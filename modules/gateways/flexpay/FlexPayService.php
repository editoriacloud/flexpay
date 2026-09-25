<?php
/**
 * FlexPayService — payment workflows shared by the gateway, callbacks,
 * invoice-page poller, dashboard and cron hook.
 *
 * Kept separate from modules/gateways/flexpay.php because WHMCS loads that
 * file itself; standalone endpoints must never include it a second time.
 *
 * @package FlexPay\Daraja
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPayService
{
    /** Account reference for an invoice ("INV-42"). Daraja allows 12 characters. */
    public static function accountReference(array $gw, int $invoiceId): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) ($gw['accountRefPrefix'] ?? 'INV'));
        if ($prefix === '') {
            $prefix = 'INV';
        }
        $ref = $prefix . '-' . $invoiceId;
        return strlen($ref) <= 12 ? $ref : (string) $invoiceId;
    }

    // ─────────────────────────────────────────────────────────────────────
    // C2B URL registration
    // ─────────────────────────────────────────────────────────────────────

    /**
     * (Re)register C2B Validation/Confirmation URLs whenever the shortcode,
     * domain, environment or callback-key scheme changes.
     *
     * Failed attempts back off for an hour (v3.4 retried a slow Daraja call
     * on every single invoice view). Production shortcodes can usually only
     * be registered once; if Daraja says the URLs are already registered we
     * stop retrying and surface a note in the dashboard — callbacks to the
     * previously registered URL keep working via the Safaricom-IP check.
     *
     * @param bool $force  Skip change detection and backoff (dashboard button)
     * @return array ['attempted' => bool, 'success' => bool, 'message' => string]
     */
    public static function registerC2B(array $gw, bool $force = false, string $actor = 'system (auto)'): array
    {
        if (!$force && ($gw['autoRegisterC2B'] ?? 'on') !== 'on') {
            return ['attempted' => false, 'success' => false, 'message' => 'Auto-registration is disabled.'];
        }

        $shortcode = DarajaClient::c2bShortcode($gw);
        if ($shortcode === '' || empty($gw['consumerKey']) || empty($gw['consumerSecret'])) {
            return ['attempted' => false, 'success' => false, 'message' => 'Shortcode or Daraja credentials are not configured.'];
        }

        $systemUrl = FlexPayStore::systemUrl($gw);
        if (stripos($systemUrl, 'https://') !== 0) {
            return ['attempted' => false, 'success' => false, 'message' => 'Your WHMCS System URL must use https:// — Daraja rejects plain-HTTP callback URLs.'];
        }

        $sandboxFlag     = (($gw['testMode'] ?? '') === 'on') ? 'sandbox' : 'live';
        $validationUrl   = FlexPaySecurity::callbackUrl($systemUrl, 'c2b_check');
        $confirmationUrl = FlexPaySecurity::callbackUrl($systemUrl, 'c2b_receipt');
        $currentHash     = hash('sha256', $shortcode . '|' . $systemUrl . '|' . $sandboxFlag . '|' . $confirmationUrl);

        if (!$force) {
            if (FlexPayStore::getSetting('c2b_registration_hash') === $currentHash) {
                return ['attempted' => false, 'success' => true, 'message' => 'Already registered.'];
            }
            $lastFail = (int) FlexPayStore::getSetting('c2b_registration_last_fail', 0);
            if ($lastFail > 0 && time() - $lastFail < 3600) {
                return ['attempted' => false, 'success' => false, 'message' => 'Waiting before retrying a failed registration.'];
            }
        }

        $response = DarajaClient::fromGatewayParams($gw)->c2bRegisterUrl([
            'shortcode'       => $shortcode,
            'responseType'    => 'Completed',
            'validationUrl'   => $validationUrl,
            'confirmationUrl' => $confirmationUrl,
        ]);

        $success = DarajaClient::isAccepted($response);
        $message = DarajaClient::errorMessage($response, 'Registration failed.');

        FlexPayStore::logApiCall('c2b_register', ['shortcode' => $shortcode, 'environment' => $sandboxFlag], $response, $success, $actor);

        if ($success) {
            FlexPayStore::setSetting('c2b_registration_hash', $currentHash);
            FlexPayStore::setSetting('c2b_registration_last_success', FlexPayStore::now());
            FlexPayStore::setSetting('c2b_registration_last_fail', '0');
            FlexPayStore::setSetting('c2b_registration_note', '');
            return ['attempted' => true, 'success' => true, 'message' => 'C2B URLs registered with Daraja.'];
        }

        if (preg_match('/already\s+registered|duplicate/i', $message)) {
            FlexPayStore::setSetting('c2b_registration_hash', $currentHash);
            FlexPayStore::setSetting(
                'c2b_registration_note',
                'Daraja reports C2B URLs are already registered for this shortcode, so the new keyed URLs could not replace them. '
                . 'Callbacks are still accepted from Safaricom IPs (Callback Security: "Secret URL key OR Safaricom IP"). '
                . 'To move to keyed URLs, delete the existing URLs in the Daraja portal (or ask Safaricom), then use Force Re-Register.'
            );
            return ['attempted' => true, 'success' => false, 'message' => $message];
        }

        FlexPayStore::setSetting('c2b_registration_last_fail', (string) time());
        return ['attempted' => true, 'success' => false, 'message' => $message];
    }

    // ─────────────────────────────────────────────────────────────────────
    // STK confirmation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ask Daraja for the authoritative outcome of an STK push and act on it.
     *
     * @return array [
     *   'state'   => 'paid'|'failed'|'pending'|'error',
     *   'code'    => string ResultCode ('' when unknown),
     *   'message' => string,
     *   'settlement' => array|null,
     * ]
     */
    public static function queryStk(array $gw, string $checkoutId, string $source): array
    {
        [$code, $response] = self::fetchStkResultCode($gw, $checkoutId);

        if ($code === '0') {
            $settlement = FlexPayStore::settleStk($checkoutId, null, $source);
            return ['state' => 'paid', 'code' => '0', 'message' => $settlement['message'], 'settlement' => $settlement];
        }

        if ($code !== '') {
            // Any other ResultCode is final: 1032 cancelled, 1037 phone
            // unreachable, 2001 wrong PIN, 1 insufficient funds, ...
            // (v3.4 treated 1032/1037 as "still pending" and polled forever.)
            $message = DarajaClient::describeResultCode($code);
            FlexPayStore::markStkFailed($checkoutId, $code, $message, json_encode($response));
            return ['state' => 'failed', 'code' => $code, 'message' => $message, 'settlement' => null];
        }

        // errorCode 500.001.1001 = "The transaction is being processed".
        $errorCode = (string) ($response['errorCode'] ?? '');
        if ($errorCode === '500.001.1001' || stripos((string) ($response['errorMessage'] ?? ''), 'being processed') !== false) {
            return ['state' => 'pending', 'code' => '', 'message' => 'Waiting for you to enter your M-Pesa PIN…', 'settlement' => null];
        }

        return ['state' => 'error', 'code' => '', 'message' => DarajaClient::errorMessage($response, 'Status unavailable.'), 'settlement' => null];
    }

    /**
     * Raw STK Query. Returns [ResultCode or '' when not final/unknown, response].
     *
     * @return array{0:string, 1:array}
     */
    public static function fetchStkResultCode(array $gw, string $checkoutId): array
    {
        $response = DarajaClient::fromGatewayParams($gw)->stkQuery([
            'shortcode'         => (string) ($gw['businessShortcode'] ?? ''),
            'passkey'           => (string) ($gw['passkey'] ?? ''),
            'checkoutRequestId' => $checkoutId,
        ]);

        $row = FlexPayStore::findTransactionByCheckoutId($checkoutId);
        if ($row) {
            FlexPayStore::touchChecked((int) $row->id);
        }

        return [isset($response['ResultCode']) ? (string) $response['ResultCode'] : '', $response];
    }

    /**
     * Cron sweeper: settle or fail STK pushes whose callback never arrived —
     * even if the customer closed the invoice page. Returns counts.
     */
    public static function sweepPendingStk(array $gw, int $limit = 20): array
    {
        $counts = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'expired' => 0];

        foreach (FlexPayStore::listStalePendingStk(120, 300, $limit) as $row) {
            $counts['checked']++;

            // Daraja only keeps STK status queryable for a limited time.
            if (strtotime((string) $row->created_at) < time() - 86400) {
                FlexPayStore::markStkFailed((string) $row->checkout_request_id, 'expired', 'No result received from Safaricom within 24 hours.');
                $counts['expired']++;
                continue;
            }

            $result = self::queryStk($gw, (string) $row->checkout_request_id, 'cron_sweeper');
            if ($result['state'] === 'paid') {
                $counts['paid']++;
            } elseif ($result['state'] === 'failed') {
                $counts['failed']++;
            }
        }

        return $counts;
    }
}

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
    // STK Push initiation (customer checkout + admin "send prompt")
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Send an STK prompt for an invoice's outstanding balance. Every value
     * sent to Safaricom is derived here, server-side, from the invoice and
     * the gateway settings.
     *
     * @return array ['success' => bool, 'message' => string, 'checkout_request_id' => ?string]
     */
    public static function initiateStk(array $gw, int $invoiceId, string $phone, string $actor, string $clientIp = ''): array
    {
        $fail = function (string $message) {
            return ['success' => false, 'message' => $message, 'checkout_request_id' => null];
        };

        $phone = DarajaClient::formatPhone($phone);
        if ($phone === '') {
            return $fail('Invalid Safaricom phone number. Use format 07XXXXXXXX or 01XXXXXXXX.');
        }

        $invoice = FlexPayStore::getInvoice($invoiceId);
        if (!$invoice || !in_array($invoice->status, FlexPayStore::OPEN_INVOICE_STATUSES, true)) {
            return $fail('This invoice is not awaiting payment.');
        }

        $amount = FlexPayStore::invoiceBalanceInKes($invoiceId);
        if ($amount === null) {
            return $fail('This invoice is not in KES and no KES currency/exchange rate is configured in WHMCS.');
        }
        if ($amount < 1) {
            return $fail('Nothing is due on this invoice.');
        }

        $accRef    = self::accountReference($gw, $invoiceId);
        $shortcode = (string) ($gw['businessShortcode'] ?? '');

        $response = DarajaClient::fromGatewayParams($gw)->stkPush([
            'shortcode'       => $shortcode,
            'passkey'         => (string) ($gw['passkey'] ?? ''),
            'amount'          => $amount,
            'phone'           => $phone,
            'txnType'         => ($gw['transactionType'] ?? '') ?: 'CustomerPayBillOnline',
            'partyB'          => DarajaClient::stkPartyB($gw),
            'accountRef'      => $accRef,
            'transactionDesc' => 'Invoice ' . $invoiceId,
            'callbackUrl'     => FlexPaySecurity::callbackUrl(FlexPayStore::systemUrl($gw), 'stk_result'),
        ]);

        $success    = DarajaClient::isAccepted($response);
        $checkoutId = (string) ($response['CheckoutRequestID'] ?? '');

        FlexPayStore::logApiCall('stk_push', ['invoice_id' => $invoiceId, 'phone' => $phone, 'amount' => $amount], $response, $success, $actor);
        if (function_exists('logTransaction')) {
            logTransaction('FlexPay (Daraja)', array_merge(['_invoice_id' => $invoiceId, '_phone' => $phone, '_amount' => $amount, '_by' => $actor], $response), $success ? 'STK Push Initiated' : 'STK Push Rejected');
        }

        if (!$success || $checkoutId === '') {
            // Daraja's own errorMessage values are customer-safe ("Invalid PhoneNumber").
            return $fail(!empty($response['errorMessage']) && is_string($response['errorMessage'])
                ? $response['errorMessage']
                : 'We could not send the payment prompt right now. Please try again, or pay manually using the details shown.');
        }

        FlexPayStore::recordTransaction([
            'channel'             => 'stk',
            'direction'           => 'in',
            'invoice_id'          => $invoiceId,
            'client_id'           => (int) $invoice->userid,
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => (string) ($response['MerchantRequestID'] ?? ''),
            'phone'               => $phone,
            'amount'              => $amount,
            'account_reference'   => $accRef,
            'status'              => 'pending',
            'raw_request'         => json_encode(['shortcode' => $shortcode, 'amount' => $amount, 'phone' => $phone, 'ip' => $clientIp, 'by' => $actor]),
        ]);

        return [
            'success'             => true,
            'message'             => (string) ($response['CustomerMessage'] ?? 'Prompt sent! Enter your M-Pesa PIN on your phone.'),
            'checkout_request_id' => $checkoutId,
            'amount'              => $amount,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Account balance (async — result arrives at ?route=balance_result)
    // ─────────────────────────────────────────────────────────────────────

    public static function requestBalance(array $gw, array $initiator, string $actor): array
    {
        $systemUrl = FlexPayStore::systemUrl($gw);
        $response  = DarajaClient::fromGatewayParams($gw)->accountBalance([
            'initiatorName'      => $initiator['name'],
            'securityCredential' => $initiator['credential'],
            'partyA'             => DarajaClient::c2bShortcode($gw),
            'identifierType'     => '4',
            'resultUrl'          => FlexPaySecurity::callbackUrl($systemUrl, 'balance_result'),
            'timeoutUrl'         => FlexPaySecurity::callbackUrl($systemUrl, 'balance_timeout'),
        ]);

        $success = DarajaClient::isAccepted($response);
        FlexPayStore::logApiCall('balance_query', ['shortcode' => DarajaClient::c2bShortcode($gw)], $response, $success, $actor);

        return $success
            ? ['success' => true, 'message' => 'Balance query sent. Refresh the Balance tab in 10–30 seconds for the result.']
            : ['success' => false, 'message' => 'Balance request failed: ' . DarajaClient::errorMessage($response)];
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

<?php
/**
 * FlexPayDashboardActions — handles every POST action from the dashboard UI.
 *
 * Each action talks to Daraja through the shared DarajaClient and records
 * the attempt via FlexPayStore, so manual dashboard-triggered calls show
 * up in the same API log and transaction ledger as automated ones.
 *
 * @package FlexPay\Dashboard
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPayDashboardActions
{
    /**
     * Dispatch a POST action by its fp_action value.
     *
     * @param  array  $post
     * @param  string $adminUsername
     * @return array  ['success' => bool, 'message' => string]
     */
    public static function handle(array $post, string $adminUsername): array
    {
        $action = $post['fp_action'] ?? '';

        switch ($action) {
            case 'reconcile':
                return self::reconcile($post, $adminUsername);

            case 'trigger_balance':
                return self::triggerBalanceQuery($adminUsername);

            case 'trigger_status':
                return self::triggerStatusQuery($post, $adminUsername);

            case 'trigger_reversal':
                return self::triggerReversal($post, $adminUsername);

            case 'register_c2b':
                return self::forceRegisterC2B($adminUsername);

            case 'simulate_c2b':
                return self::simulateC2B($post, $adminUsername);

            case 'verify_payment':
                return self::verifyPayment($post, $adminUsername);

            case 'reset_verify_limit':
                return self::resetVerifyLimit($post, $adminUsername);

            case 'recheck_license':
                return self::recheckLicense($adminUsername);

            default:
                return ['success' => false, 'message' => 'Unknown action.'];
        }
    }

    /**
     * Fetch the FlexPay gateway module's saved configuration, loading the
     * gateway function library first if needed (addon-module admin pages
     * don't get it auto-loaded the way gateway/callback files do).
     *
     * @return array
     */
    private static function getGatewayParams(): array
    {
        return FlexPayStore::getFlexPayGatewayParams();
    }

    /**
     * Reconcile a flagged unmatched C2B payment to a specific invoice.
     */
    private static function reconcile(array $post, string $adminUsername): array
    {
        $unmatchedId = (int) ($post['unmatched_id'] ?? 0);
        $invoiceId   = (int) ($post['invoice_id'] ?? 0);

        if (!$unmatchedId || !$invoiceId) {
            return ['success' => false, 'message' => 'Both a payment and an invoice number are required.'];
        }

        return FlexPayStore::reconcileUnmatched($unmatchedId, $invoiceId, $adminUsername, 'flexpay');
    }

    /**
     * Trigger an on-demand Account Balance Query. Result arrives async at
     * the callback's ?route=balance_result — we just confirm the request
     * was accepted by Daraja here.
     */
    private static function triggerBalanceQuery(string $adminUsername): array
    {
        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'FlexPay gateway is not active.'];
        }

        $client    = DarajaClient::fromGatewayParams($gw);
        $systemUrl = rtrim(\App::getSystemURL(), '/');

        $response = $client->accountBalance([
            'initiatorName'      => $gw['b2cInitiatorName'],
            'securityCredential' => $gw['b2cSecurityCredential'],
            'partyA'             => $gw['businessShortcode'],
            'identifierType'     => '4',
            'resultUrl'          => $systemUrl . '/modules/gateways/callback/flexpay.php?route=balance_result',
            'timeoutUrl'         => $systemUrl . '/modules/gateways/callback/flexpay.php?route=balance_timeout',
        ]);

        $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('balance_query', ['triggered_via' => 'dashboard'], $response, $success, $adminUsername);

        return $success
            ? ['success' => true, 'message' => 'Balance query sent. Refresh the Balance tab in ~10-30 seconds for the result.']
            : ['success' => false, 'message' => $response['errorMessage'] ?? 'Failed to request balance. Check B2C initiator credentials.'];
    }

    /**
     * Trigger an on-demand Transaction Status Query for a given receipt.
     */
    private static function triggerStatusQuery(array $post, string $adminUsername): array
    {
        $transactionId = trim((string) ($post['transaction_id'] ?? ''));
        if ($transactionId === '') {
            return ['success' => false, 'message' => 'Please provide an M-Pesa transaction/receipt number.'];
        }

        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'FlexPay gateway is not active.'];
        }

        $client    = DarajaClient::fromGatewayParams($gw);
        $systemUrl = rtrim(\App::getSystemURL(), '/');

        $response = $client->transactionStatus([
            'initiatorName'      => $gw['b2cInitiatorName'],
            'securityCredential' => $gw['b2cSecurityCredential'],
            'transactionId'      => $transactionId,
            'partyA'             => $gw['businessShortcode'],
            'identifierType'     => '4',
            'remarks'            => 'Dashboard status check',
            'resultUrl'          => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_result',
            'timeoutUrl'         => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_timeout',
        ]);

        $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('status_query', ['transaction_id' => $transactionId], $response, $success, $adminUsername);

        return $success
            ? ['success' => true, 'message' => 'Status query sent for ' . htmlspecialchars($transactionId) . '. Check the API Log tab shortly for the result.']
            : ['success' => false, 'message' => $response['errorMessage'] ?? 'Failed to request transaction status.'];
    }

    /**
     * Trigger a Transaction Reversal for a given receipt + amount.
     */
    private static function triggerReversal(array $post, string $adminUsername): array
    {
        $transactionId = trim((string) ($post['transaction_id'] ?? ''));
        $amount        = (int) ($post['amount'] ?? 0);

        if ($transactionId === '' || $amount < 1) {
            return ['success' => false, 'message' => 'A transaction ID and a positive amount are required.'];
        }

        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'FlexPay gateway is not active.'];
        }

        $client    = DarajaClient::fromGatewayParams($gw);
        $systemUrl = rtrim(\App::getSystemURL(), '/');
        $shortcode = $gw['c2bShortcode'] ?: $gw['businessShortcode'];

        $response = $client->reverseTransaction([
            'initiatorName'          => $gw['b2cInitiatorName'],
            'securityCredential'     => $gw['b2cSecurityCredential'],
            'transactionId'          => $transactionId,
            'amount'                 => $amount,
            'receiverParty'          => $shortcode,
            'receiverIdentifierType' => '11',
            'remarks'                => 'Dashboard-initiated reversal',
            'resultUrl'              => $systemUrl . '/modules/gateways/callback/flexpay.php?route=reversal_result',
            'timeoutUrl'             => $systemUrl . '/modules/gateways/callback/flexpay.php?route=reversal_timeout',
        ]);

        $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('reversal', ['transaction_id' => $transactionId, 'amount' => $amount], $response, $success, $adminUsername);

        return $success
            ? ['success' => true, 'message' => 'Reversal request submitted for ' . htmlspecialchars($transactionId) . '. Result will appear in the API Log shortly.']
            : ['success' => false, 'message' => $response['errorMessage'] ?? 'Failed to submit reversal request.'];
    }

    /**
     * Force an immediate C2B URL re-registration, bypassing the gateway
     * module's change-detection cache. Useful right after changing the
     * shortcode or moving domains, without waiting for the next invoice view.
     */
    private static function forceRegisterC2B(string $adminUsername): array
    {
        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'FlexPay gateway is not active.'];
        }

        $client    = DarajaClient::fromGatewayParams($gw);
        $systemUrl = rtrim(\App::getSystemURL(), '/');
        $shortcode = $gw['c2bShortcode'] ?: $gw['businessShortcode'];

        $validationUrl   = $systemUrl . '/modules/gateways/callback/flexpay.php?route=c2b_check';
        $confirmationUrl = $systemUrl . '/modules/gateways/callback/flexpay.php?route=c2b_receipt';

        $response = $client->c2bRegisterUrl([
            'shortcode'       => $shortcode,
            'responseType'    => 'Completed',
            'validationUrl'   => $validationUrl,
            'confirmationUrl' => $confirmationUrl,
        ]);

        $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('c2b_register', ['validationUrl' => $validationUrl, 'confirmationUrl' => $confirmationUrl], $response, $success, $adminUsername . ' (manual)');

        if ($success) {
            $sandboxFlag = ($gw['testMode'] === 'on') ? 'sandbox' : 'live';
            $hash = md5($shortcode . '|' . $systemUrl . '|' . $sandboxFlag);
            FlexPayStore::setSetting('c2b_registration_hash', $hash);
            FlexPayStore::setSetting('c2b_registration_last_success', date('Y-m-d H:i:s'));
        }

        return $success
            ? ['success' => true, 'message' => 'C2B URLs re-registered successfully with Daraja.']
            : ['success' => false, 'message' => $response['errorMessage'] ?? 'Registration failed — check credentials and shortcode.'];
    }

    /**
     * Sandbox-only: simulate an incoming C2B payment for end-to-end testing
     * without needing a real phone or real money.
     */
    private static function simulateC2B(array $post, string $adminUsername): array
    {
        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'FlexPay gateway is not active.'];
        }

        if (($gw['testMode'] ?? '') !== 'on') {
            return ['success' => false, 'message' => 'C2B simulation is only available in Sandbox mode.'];
        }

        $amount  = (int) ($post['sim_amount'] ?? 0);
        $phone   = DarajaClient::formatPhone((string) ($post['sim_phone'] ?? ''));
        $billRef = trim((string) ($post['sim_bill_ref'] ?? ''));

        if ($amount < 1 || strlen($phone) !== 12 || $billRef === '') {
            return ['success' => false, 'message' => 'Amount, a valid phone number, and an account reference are all required.'];
        }

        $client = DarajaClient::fromGatewayParams($gw);
        $shortcode = $gw['c2bShortcode'] ?: $gw['businessShortcode'];

        $response = $client->c2bSimulate([
            'shortcode'     => $shortcode,
            'amount'        => $amount,
            'phone'         => $phone,
            'billRefNumber' => $billRef,
            'commandId'     => 'CustomerPayBillOnline',
        ]);

        $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('c2b_simulate', ['amount' => $amount, 'phone' => $phone, 'bill_ref' => $billRef], $response, $success, $adminUsername);

        return $success
            ? ['success' => true, 'message' => 'Simulated C2B payment sent. It will appear in Transactions / Reconciliation within a few seconds.']
            : ['success' => false, 'message' => $response['errorMessage'] ?? 'Simulation failed.'];
    }

    /**
     * Verify a payment by reference/receipt — for the common real-world
     * case where a customer genuinely paid, but for whatever reason
     * (server hiccup, firewall blocking Safaricom's callback IPs, a
     * dropped connection) the automatic callback never reached WHMCS.
     *
     * Lookup order:
     *   1. flexpay_transactions — by M-Pesa receipt, checkout request ID,
     *      or account reference. Covers both STK and C2B payments we DID
     *      receive a callback for, in case it just wasn't applied to an
     *      invoice for some reason (e.g. reference didn't parse).
     *   2. Live Daraja Transaction Status Query — asks Safaricom directly
     *      "what actually happened to this transaction ID", for cases
     *      where our own database has no record of it at all. The result
     *      arrives asynchronously at ?route=status_result and is also
     *      logged to the API log immediately.
     *
     * If an invoice number is supplied and a genuine successful payment is
     * found (by either path) that hasn't been applied to any invoice yet,
     * this applies it immediately — the same reconciliation path used
     * elsewhere in the dashboard.
     */
    private static function verifyPayment(array $post, string $adminUsername): array
    {
        $reference = trim((string) ($post['verify_reference'] ?? ''));
        $invoiceId = (int) ($post['verify_invoice_id'] ?? 0);

        if ($reference === '') {
            return ['success' => false, 'message' => 'Enter an M-Pesa receipt number, checkout request ID, or account reference to verify.'];
        }

        // ── Step 1: check our own database first — cheapest, fastest, no API call ──
        $local = FlexPayStore::findTransactionByReference($reference);

        if ($local) {
            $statusLine = strtoupper($local->status) . ($local->mpesa_receipt ? " — Receipt: {$local->mpesa_receipt}" : '');

            if ($local->status === 'success') {
                if ($local->invoice_id) {
                    return [
                        'success' => true,
                        'message' => "Found locally: {$statusLine}. Already applied to Invoice #{$local->invoice_id}. Nothing further to do.",
                    ];
                }

                // Successful payment on file, but never linked to an invoice.
                if ($invoiceId) {
                    $result = FlexPayStore::applyOrphanedTransaction($local, $invoiceId, $adminUsername, 'flexpay');
                    return $result;
                }

                return [
                    'success' => true,
                    'message' => "Found locally: {$statusLine}. This payment succeeded but was never linked to an invoice — enter an invoice number above and verify again to apply it now.",
                ];
            }

            if ($local->status === 'pending') {
                return [
                    'success' => false,
                    'message' => "Found locally: still PENDING (no final result recorded yet). If the customer says they completed the PIN entry, wait a few seconds and check again, or try the live Daraja check below.",
                ];
            }

            // failed / reversed
            return [
                'success' => false,
                'message' => "Found locally: {$statusLine}." . ($local->result_desc ? ' (' . $local->result_desc . ')' : ''),
            ];
        }

        // ── Step 2: nothing locally — ask Safaricom directly ──────────────
        $gw = self::getGatewayParams();
        if (!$gw['type']) {
            return ['success' => false, 'message' => 'No local record found, and the FlexPay gateway is not active so a live Daraja check cannot be performed.'];
        }

        if (empty($gw['b2cInitiatorName']) || empty($gw['b2cSecurityCredential'])) {
            return [
                'success' => false,
                'message' => 'No local record found for that reference. A live Daraja check requires B2C Initiator Name + Security Credential to be configured in the gateway settings.',
            ];
        }

        $client    = DarajaClient::fromGatewayParams($gw);
        $systemUrl = rtrim(\App::getSystemURL(), '/');

        $response = $client->transactionStatus([
            'initiatorName'      => $gw['b2cInitiatorName'],
            'securityCredential' => $gw['b2cSecurityCredential'],
            'transactionId'      => $reference,
            'partyA'             => $gw['businessShortcode'],
            'identifierType'     => '4',
            'remarks'            => 'Dashboard payment verification',
            'resultUrl'          => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_result',
            'timeoutUrl'         => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_timeout',
        ]);

        $accepted = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
        FlexPayStore::logApiCall('verify_payment_status_query', ['reference' => $reference, 'invoice_id' => $invoiceId], $response, $accepted, $adminUsername);

        if (!$accepted) {
            $errMsg = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'Daraja rejected the status query — check the reference is a valid M-Pesa receipt number.';
            return ['success' => false, 'message' => "No local record found for '{$reference}'. Live Daraja check failed: {$errMsg}"];
        }

        // Daraja's TransactionStatusQuery is itself asynchronous — it never
        // returns the actual outcome in this response, only confirmation
        // that the query was accepted. The real result lands moments later
        // at ?route=status_result and is written to the API Log.
        return [
            'success' => true,
            'message' => "No local record found for '{$reference}'. A live status query has been sent to Safaricom — check the API Log tab in a few seconds for the actual result. If it confirms the payment succeeded, return here with the invoice number to apply it.",
        ];
    }

    /**
     * Clear the customer-facing self-verify rate limit for one invoice.
     * Used when a genuine customer gets blocked after several legitimate
     * retries (typos in their receipt number, etc.) and contacts support.
     */
    private static function resetVerifyLimit(array $post, string $adminUsername): array
    {
        $invoiceId = (int) ($post['reset_invoice_id'] ?? 0);

        if (!$invoiceId) {
            return ['success' => false, 'message' => 'Enter a valid invoice number.'];
        }

        FlexPayStore::clearVerifyRateLimit($invoiceId);
        FlexPayStore::logApiCall('reset_verify_limit', ['invoice_id' => $invoiceId], ['cleared' => true], true, $adminUsername);

        return ['success' => true, 'message' => "Verify-payment rate limit cleared for Invoice #{$invoiceId}."];
    }

    /**
     * Force a fresh license check, bypassing both the in-request cache
     * and the cached local key, so the admin gets a genuine live result
     * after fixing a license problem (e.g. just renewed) rather than
     * waiting out the normal local-key recheck interval.
     */
    private static function recheckLicense(string $adminUsername): array
    {
        FlexPayStore::setSetting('license_local_key', '');
        FlexPayLicense::clearRequestCache();

        $gw = self::getGatewayParams();
        $result = FlexPayLicense::check($gw);

        FlexPayStore::logApiCall('recheck_license', [], $result, $result['valid'], $adminUsername);

        return [
            'success' => $result['valid'],
            'message' => $result['valid']
                ? 'License re-checked: ' . $result['message']
                : 'License is still not valid: ' . $result['message'],
        ];
    }
}

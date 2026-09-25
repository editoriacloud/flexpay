<?php
/**
 * FlexPayDashboardActions — handles every POST action from the dashboard UI.
 *
 * Each action talks to Daraja through the shared DarajaClient and records
 * the attempt via FlexPayStore, so manual dashboard-triggered calls show
 * up in the same API log and transaction ledger as automated ones.
 *
 * Every action requires a valid dashboard CSRF token (see handle()).
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
     * @return array  ['success' => bool, 'message' => string]
     */
    public static function handle(array $post, string $adminUsername): array
    {
        if (!FlexPaySecurity::checkCsrf($post['fp_csrf'] ?? null)) {
            return ['success' => false, 'message' => 'Your session token has expired. Please reload the page and try again.'];
        }

        switch ($post['fp_action'] ?? '') {
            case 'reconcile':
                return self::reconcile($post, $adminUsername);
            case 'dismiss_unmatched':
                return self::dismissUnmatched($post, $adminUsername);
            case 'credit_client':
                return self::creditClient($post, $adminUsername);
            case 'admin_stk':
                return self::adminStk($post, $adminUsername);
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
            case 'retry_refund':
                return self::retryRefund($post, $adminUsername);
            case 'test_connection':
                return self::testConnection($adminUsername);
            case 'run_sweeper':
                return self::runSweeper($adminUsername);
            default:
                return ['success' => false, 'message' => 'Unknown action.'];
        }
    }

    private static function gateway(): ?array
    {
        $gw = FlexPayStore::getFlexPayGatewayParams();
        return !empty($gw['type']) ? $gw : null;
    }

    private static function inactive(): array
    {
        return ['success' => false, 'message' => 'The FlexPay gateway is not active (Setup → Payments → Payment Gateways).'];
    }

    private static function noInitiator(): array
    {
        return ['success' => false, 'message' => 'This needs an Initiator Name plus a Security Credential (or Initiator Password + Safaricom certificate) in the FlexPay gateway settings.'];
    }

    private static function systemUrl(array $gw): string
    {
        return FlexPayStore::systemUrl($gw);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reconciliation
    // ─────────────────────────────────────────────────────────────────────

    private static function reconcile(array $post, string $adminUsername): array
    {
        $unmatchedId = (int) ($post['unmatched_id'] ?? 0);
        $invoiceId   = (int) ($post['invoice_id'] ?? 0);

        if ($unmatchedId <= 0 || $invoiceId <= 0) {
            return ['success' => false, 'message' => 'Both a payment and an invoice number are required.'];
        }

        return FlexPayStore::reconcileUnmatched($unmatchedId, $invoiceId, $adminUsername);
    }

    private static function dismissUnmatched(array $post, string $adminUsername): array
    {
        $unmatchedId = (int) ($post['unmatched_id'] ?? 0);
        $reason      = substr(trim((string) ($post['dismiss_reason'] ?? '')), 0, 150);

        if ($unmatchedId <= 0) {
            return ['success' => false, 'message' => 'No payment selected.'];
        }

        return FlexPayStore::dismissUnmatched($unmatchedId, $adminUsername, $reason);
    }

    private static function creditClient(array $post, string $adminUsername): array
    {
        $unmatchedId = (int) ($post['unmatched_id'] ?? 0);
        $clientId    = (int) ($post['client_id'] ?? 0);
        if ($unmatchedId <= 0 || $clientId <= 0) {
            return ['success' => false, 'message' => 'Choose a payment and enter a client ID.'];
        }
        return FlexPayStore::creditUnmatchedToClient($unmatchedId, $clientId, $adminUsername);
    }

    /** Admin sends an STK prompt for an invoice (from the admin invoice page panel). */
    private static function adminStk(array $post, string $adminUsername): array
    {
        $invoiceId = (int) ($post['invoice_id'] ?? 0);
        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (!FlexPayLicense::check($gw)['valid']) {
            return ['success' => false, 'message' => 'The FlexPay license is not valid — payment prompts are disabled.'];
        }
        if (!FlexPaySecurity::rateLimit('admin_stk_inv_' . $invoiceId, 3, 300)) {
            return ['success' => false, 'message' => 'Too many prompts for this invoice — wait a few minutes.'];
        }

        $result = FlexPayService::initiateStk($gw, $invoiceId, (string) ($post['phone'] ?? ''), $adminUsername);
        return $result['success']
            ? ['success' => true, 'message' => "M-Pesa prompt for KES " . number_format((float) $result['amount']) . " sent for Invoice #{$invoiceId}. The invoice updates automatically once the customer enters their PIN."]
            : ['success' => false, 'message' => 'Prompt not sent: ' . $result['message']];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Daraja queries
    // ─────────────────────────────────────────────────────────────────────

    private static function triggerBalanceQuery(string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (!($initiator = DarajaClient::initiatorCredentials($gw))) {
            return self::noInitiator();
        }

        return self::requestBalance($gw, $initiator, $adminUsername);
    }

    /** Shared with the cron hook's optional daily balance snapshot. */
    public static function requestBalance(array $gw, array $initiator, string $actor): array
    {
        return FlexPayService::requestBalance($gw, $initiator, $actor);
    }

    private static function triggerStatusQuery(array $post, string $adminUsername): array
    {
        $transactionId = strtoupper(trim((string) ($post['transaction_id'] ?? '')));
        if (!preg_match('/^[A-Z0-9]{6,20}$/', $transactionId)) {
            return ['success' => false, 'message' => 'Enter a valid M-Pesa transaction/receipt number (letters and digits only).'];
        }

        return self::sendStatusQuery($transactionId, 0, 'admin_status', $adminUsername);
    }

    /**
     * Send a Transaction Status Query and remember why, so the async result
     * handler can act on it (see flexpay_cb_status_result()).
     */
    private static function sendStatusQuery(string $receipt, int $invoiceId, string $purpose, string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (!($initiator = DarajaClient::initiatorCredentials($gw))) {
            return self::noInitiator();
        }

        $systemUrl = self::systemUrl($gw);
        $response  = DarajaClient::fromGatewayParams($gw)->transactionStatus([
            'initiatorName'      => $initiator['name'],
            'securityCredential' => $initiator['credential'],
            'transactionId'      => $receipt,
            'partyA'             => DarajaClient::c2bShortcode($gw),
            'identifierType'     => '4',
            'remarks'            => 'Dashboard status check',
            'resultUrl'          => FlexPaySecurity::callbackUrl($systemUrl, 'status_result'),
            'timeoutUrl'         => FlexPaySecurity::callbackUrl($systemUrl, 'status_timeout'),
        ]);

        $success = DarajaClient::isAccepted($response);
        FlexPayStore::logApiCall($purpose === 'admin_verify' ? 'verify_payment_status_query' : 'status_query', ['transaction_id' => $receipt, 'invoice_id' => $invoiceId ?: null], $response, $success, $adminUsername);

        if (!$success) {
            return ['success' => false, 'message' => 'Status query failed: ' . DarajaClient::errorMessage($response)];
        }

        FlexPayStore::storeStatusQueryContext($response, [
            'purpose'    => $purpose,
            'invoice_id' => $invoiceId,
            'receipt'    => $receipt,
            'actor'      => $adminUsername,
        ]);

        return ['success' => true, 'message' => $invoiceId
            ? "Status query sent for {$receipt}. If Safaricom confirms it as a completed payment to your shortcode, it will be applied to Invoice #{$invoiceId} automatically within a few seconds (see the API Log)."
            : "Status query sent for {$receipt}. The result will appear in the API Log shortly; a confirmed payment not yet on file is added to the Reconciliation queue."];
    }

    private static function triggerReversal(array $post, string $adminUsername): array
    {
        $transactionId = strtoupper(trim((string) ($post['transaction_id'] ?? '')));
        $amount        = (int) ($post['amount'] ?? 0);

        if (!preg_match('/^[A-Z0-9]{6,20}$/', $transactionId) || $amount < 1) {
            return ['success' => false, 'message' => 'A valid transaction ID and a positive whole-shilling amount are required.'];
        }

        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (!($initiator = DarajaClient::initiatorCredentials($gw))) {
            return self::noInitiator();
        }

        $original = FlexPayStore::findTransactionByReceipt($transactionId);
        if ($original) {
            if ($original->direction !== 'in' || $original->status !== 'success') {
                return ['success' => false, 'message' => "Transaction {$transactionId} is {$original->status} — only successful incoming payments can be reversed."];
            }
            if ($amount > (float) $original->amount + 0.009) {
                return ['success' => false, 'message' => 'Reversal amount exceeds the original payment of KES ' . number_format((float) $original->amount, 2) . '.'];
            }
        }

        $systemUrl = self::systemUrl($gw);
        $response  = DarajaClient::fromGatewayParams($gw)->reverseTransaction([
            'initiatorName'          => $initiator['name'],
            'securityCredential'     => $initiator['credential'],
            'transactionId'          => $transactionId,
            'amount'                 => $amount,
            'receiverParty'          => DarajaClient::c2bShortcode($gw),
            'receiverIdentifierType' => '11',
            'remarks'                => 'Dashboard reversal',
            'resultUrl'              => FlexPaySecurity::callbackUrl($systemUrl, 'reversal_result'),
            'timeoutUrl'             => FlexPaySecurity::callbackUrl($systemUrl, 'reversal_timeout'),
        ]);

        $success = DarajaClient::isAccepted($response);
        FlexPayStore::logApiCall('reversal', ['transaction_id' => $transactionId, 'amount' => $amount], $response, $success, $adminUsername);

        if (!$success) {
            return ['success' => false, 'message' => 'Reversal request failed: ' . DarajaClient::errorMessage($response)];
        }

        // Track the request so the async result can find the ORIGINAL payment.
        FlexPayStore::insertTransactionOnce([
            'channel'                    => 'reversal',
            'direction'                  => 'out',
            'invoice_id'                 => ($original && $original->invoice_id) ? (int) $original->invoice_id : null,
            'conversation_id'            => (string) ($response['ConversationID'] ?? ''),
            'originator_conversation_id' => (string) ($response['OriginatorConversationID'] ?? ''),
            'account_reference'          => $transactionId,
            'phone'                      => $original ? (string) $original->phone : '',
            'amount'                     => $amount,
            'status'                     => 'pending',
            'result_desc'                => 'Reversal requested by ' . $adminUsername,
        ]);

        return ['success' => true, 'message' => "Reversal request submitted for {$transactionId}. The result will appear in Transactions shortly."];
    }

    private static function forceRegisterC2B(string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }

        $result = FlexPayService::registerC2B($gw, true, $adminUsername . ' (manual)');

        return ['success' => $result['success'], 'message' => $result['success']
            ? 'C2B URLs registered successfully with Daraja.'
            : 'Registration failed: ' . $result['message']];
    }

    private static function simulateC2B(array $post, string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (($gw['testMode'] ?? '') !== 'on') {
            return ['success' => false, 'message' => 'C2B simulation is only available in Sandbox mode.'];
        }

        $amount  = (int) ($post['sim_amount'] ?? 0);
        $phone   = DarajaClient::formatPhone((string) ($post['sim_phone'] ?? ''));
        $billRef = substr(trim((string) ($post['sim_bill_ref'] ?? '')), 0, 20);

        if ($amount < 1 || $phone === '') {
            return ['success' => false, 'message' => 'A positive amount and a valid Safaricom phone number are required.'];
        }

        $isTill   = ($gw['transactionType'] ?? '') === 'CustomerBuyGoodsOnline';
        $response = DarajaClient::fromGatewayParams($gw)->c2bSimulate([
            'shortcode'     => DarajaClient::c2bShortcode($gw),
            'amount'        => $amount,
            'phone'         => $phone,
            'billRefNumber' => $isTill ? '' : $billRef,
            'commandId'     => $isTill ? 'CustomerBuyGoodsOnline' : 'CustomerPayBillOnline',
        ]);

        $success = DarajaClient::isAccepted($response);
        FlexPayStore::logApiCall('c2b_simulate', ['amount' => $amount, 'phone' => $phone, 'bill_ref' => $billRef], $response, $success, $adminUsername);

        return $success
            ? ['success' => true, 'message' => 'Simulated C2B payment sent. It will appear in Transactions / Reconciliation within a few seconds.']
            : ['success' => false, 'message' => 'Simulation failed: ' . DarajaClient::errorMessage($response)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Verify / apply
    // ─────────────────────────────────────────────────────────────────────

    /**
     * "Customer says they paid but the invoice didn't update."
     * 1. Look in our own ledger (receipt, checkout ID or account reference).
     * 2. Otherwise ask Safaricom (Transaction Status Query). When an invoice
     *    number is given and Safaricom confirms a completed payment into our
     *    shortcode, the result handler applies it automatically.
     */
    private static function verifyPayment(array $post, string $adminUsername): array
    {
        $reference = trim((string) ($post['verify_reference'] ?? ''));
        $invoiceId = (int) ($post['verify_invoice_id'] ?? 0);

        if ($reference === '' || strlen($reference) > 100) {
            return ['success' => false, 'message' => 'Enter an M-Pesa receipt number, checkout request ID, or account reference to verify.'];
        }
        if ($invoiceId > 0 && !FlexPayStore::getInvoice($invoiceId)) {
            return ['success' => false, 'message' => "Invoice #{$invoiceId} does not exist."];
        }

        $local = FlexPayStore::findTransactionByReference($reference);

        if ($local) {
            $statusLine = strtoupper($local->status) . ($local->mpesa_receipt ? " — Receipt: {$local->mpesa_receipt}" : '');

            if ($local->status === 'success' && $local->direction === 'in') {
                if ($local->invoice_id) {
                    return ['success' => true, 'message' => "Found: {$statusLine}. Already applied to Invoice #{$local->invoice_id}. Nothing further to do."];
                }
                if ($invoiceId) {
                    return FlexPayStore::applyOrphanedTransaction($local, $invoiceId, $adminUsername);
                }
                return ['success' => true, 'message' => "Found: {$statusLine}. This payment succeeded but isn't linked to an invoice — enter an invoice number and verify again to apply it."];
            }

            if ($local->status === 'pending' && $local->channel === 'stk' && ($gw = self::gateway())) {
                $result = FlexPayService::queryStk($gw, (string) $local->checkout_request_id, 'admin_verify');
                return ['success' => $result['state'] === 'paid', 'message' => 'Found a pending STK push; asked Safaricom directly: ' . $result['message']];
            }

            return ['success' => false, 'message' => "Found: {$statusLine}." . ($local->result_desc ? ' (' . $local->result_desc . ')' : '')];
        }

        $receipt = FlexPaySecurity::normalizeReceipt($reference);
        if ($receipt === null) {
            return ['success' => false, 'message' => "No local record found for '{$reference}'. To ask Safaricom, enter the M-Pesa receipt number (e.g. NLJ7RT61SV)."];
        }

        return self::sendStatusQuery($receipt, $invoiceId, 'admin_verify', $adminUsername);
    }

    private static function resetVerifyLimit(array $post, string $adminUsername): array
    {
        $invoiceId = (int) ($post['reset_invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            return ['success' => false, 'message' => 'Enter a valid invoice number.'];
        }

        FlexPayStore::clearVerifyRateLimit($invoiceId);
        FlexPayStore::logApiCall('reset_verify_limit', ['invoice_id' => $invoiceId], ['cleared' => true], true, $adminUsername);

        return ['success' => true, 'message' => "Verify-payment rate limit cleared for Invoice #{$invoiceId}."];
    }

    private static function recheckLicense(string $adminUsername): array
    {
        FlexPayStore::setSetting('license_local_key', '');
        FlexPayLicense::clearRequestCache();

        $result = FlexPayLicense::check(FlexPayStore::getFlexPayGatewayParams());
        FlexPayStore::logApiCall('recheck_license', [], $result, $result['valid'], $adminUsername);

        return [
            'success' => $result['valid'],
            'message' => $result['valid'] ? 'License re-checked: ' . $result['message'] : 'License is still not valid: ' . $result['message'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Refund retry
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Re-send a FAILED B2C refund (e.g. insufficient B2C float, timeout).
     * The WHMCS refund itself was already recorded, so this only resends the
     * money; each failed refund can be retried once (the retry gets its own
     * row, which can be retried again if it too fails).
     */
    private static function retryRefund(array $post, string $adminUsername): array
    {
        $refund = FlexPayStore::getRefund((int) ($post['refund_id'] ?? 0));
        if (!$refund) {
            return ['success' => false, 'message' => 'Refund not found.'];
        }
        if ($refund->status !== 'failed' || $refund->retried_as) {
            return ['success' => false, 'message' => 'Only failed refunds that have not already been retried can be retried.'];
        }
        if (!($gw = self::gateway())) {
            return self::inactive();
        }
        if (!($initiator = DarajaClient::initiatorCredentials($gw))) {
            return self::noInitiator();
        }

        // Claim it so a double-click can't send the money twice.
        $claimed = \WHMCS\Database\Capsule::table('flexpay_refunds')
            ->where('id', $refund->id)->whereNull('retried_as')->where('status', 'failed')
            ->update(['retried_as' => 0, 'updated_at' => FlexPayStore::now()]);
        if ($claimed !== 1) {
            return ['success' => false, 'message' => 'This refund is already being retried.'];
        }

        $systemUrl = self::systemUrl($gw);
        $response  = DarajaClient::fromGatewayParams($gw)->b2cPayment([
            'initiatorName'      => $initiator['name'],
            'securityCredential' => $initiator['credential'],
            'commandId'          => ($gw['b2cCommandId'] ?? '') ?: 'BusinessPayment',
            'shortcode'          => DarajaClient::b2cShortcode($gw),
            'amount'             => (int) round((float) $refund->amount),
            'phone'              => (string) $refund->phone,
            'remarks'            => 'Refund Invoice ' . (int) $refund->invoice_id,
            'occasion'           => 'INV-' . (int) $refund->invoice_id,
            'resultUrl'          => FlexPaySecurity::callbackUrl($systemUrl, 'disbursement_result'),
            'timeoutUrl'         => FlexPaySecurity::callbackUrl($systemUrl, 'disbursement_timeout'),
        ]);

        $success = DarajaClient::isAccepted($response);
        FlexPayStore::logApiCall('b2c_retry', ['refund_id' => (int) $refund->id, 'amount' => (float) $refund->amount], $response, $success, $adminUsername);

        $newId = FlexPayStore::recordRefund([
            'invoice_id'                 => (int) $refund->invoice_id,
            'original_trans_id'          => (string) $refund->original_trans_id,
            'conversation_id'            => (string) ($response['ConversationID'] ?? ''),
            'originator_conversation_id' => (string) ($response['OriginatorConversationID'] ?? ''),
            'phone'                      => (string) $refund->phone,
            'amount'                     => (float) $refund->amount,
            'status'                     => $success ? 'pending' : 'failed',
            'result_desc'                => $success ? 'Retry of refund #' . (int) $refund->id . ' — awaiting result.' : DarajaClient::errorMessage($response),
            'initiated_by'               => $adminUsername,
        ]);

        FlexPayStore::updateRefund((int) $refund->id, ['retried_as' => $newId ?: null]);

        return $success
            ? ['success' => true, 'message' => 'Refund re-sent to Safaricom. Its result will appear on this tab shortly.']
            : ['success' => false, 'message' => 'Retry failed: ' . DarajaClient::errorMessage($response)];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Diagnostics
    // ─────────────────────────────────────────────────────────────────────

    /** Check the configuration end to end without moving money. */
    private static function testConnection(string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }

        $lines = [];
        $ok    = true;

        $token = DarajaClient::fromGatewayParams($gw)->getAccessToken(true);
        $lines[] = $token ? 'OAuth: OK' : 'OAuth: FAILED — check Consumer Key/Secret and the Sandbox setting';
        $ok = $ok && (bool) $token;

        foreach (['businessShortcode' => 'Business Shortcode', 'passkey' => 'Passkey'] as $key => $label) {
            if (trim((string) ($gw[$key] ?? '')) === '') {
                $lines[] = "{$label}: MISSING";
                $ok = false;
            }
        }

        $systemUrl = self::systemUrl($gw);
        if (stripos($systemUrl, 'https://') !== 0) {
            $lines[] = 'System URL: must start with https:// — Daraja rejects HTTP callbacks';
            $ok = false;
        } else {
            $lines[] = 'System URL: HTTPS OK';
        }

        $lines[] = DarajaClient::initiatorCredentials($gw)
            ? 'Initiator credentials: configured'
            : 'Initiator credentials: not configured (refunds, reversals, balance and status queries disabled)';

        if (trim((string) ($gw['initiatorPassword'] ?? '')) !== '' && trim((string) ($gw['b2cSecurityCredential'] ?? '')) === ''
            && !DarajaClient::securityCredentialFromPassword((string) $gw['initiatorPassword'], (string) ($gw['initiatorCertificate'] ?? ''))) {
            $lines[] = 'Security credential: could not encrypt the Initiator Password — check the pasted Safaricom certificate';
            $ok = false;
        }

        if (($gw['callbackSecurity'] ?? 'token_or_ip') === 'off') {
            $lines[] = 'Callback security: OFF — anyone can post fake payment notifications. Change this in the gateway settings.';
            $ok = false;
        }

        FlexPayStore::logApiCall('test_connection', [], ['lines' => $lines], $ok, $adminUsername);

        return ['success' => $ok, 'message' => implode(' • ', $lines)];
    }

    private static function runSweeper(string $adminUsername): array
    {
        if (!($gw = self::gateway())) {
            return self::inactive();
        }

        $counts = FlexPayService::sweepPendingStk($gw, 50);
        FlexPayStore::logApiCall('stk_sweeper', ['manual' => true], $counts, true, $adminUsername);

        return ['success' => true, 'message' => sprintf(
            'Checked %d pending STK payment(s): %d confirmed paid, %d failed, %d expired.',
            $counts['checked'], $counts['paid'], $counts['failed'], $counts['expired']
        )];
    }
}

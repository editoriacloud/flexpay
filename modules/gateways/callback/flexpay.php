<?php
/**
 * FlexPay Daraja Callback Handler
 *
 * Receives every asynchronous notification Safaricom sends, routed by the
 * ?route= query parameter (no route contains the word "mpesa", which Daraja
 * rejects in C2B URLs):
 *
 *   ?route=stk_result            — STK Push payment result
 *   ?route=c2b_check             — C2B validation (pre-payment accept/reject)
 *   ?route=c2b_receipt           — C2B confirmation (payment finalized)
 *   ?route=disbursement_result   — B2C result (refund completed/failed)
 *   ?route=disbursement_timeout  — B2C queue timeout
 *   ?route=reversal_result       — Transaction reversal result
 *   ?route=reversal_timeout      — Reversal queue timeout
 *   ?route=balance_result        — Account balance query result
 *   ?route=balance_timeout       — Balance query timeout
 *   ?route=status_result         — Transaction status query result
 *   ?route=status_timeout        — Status query timeout
 *
 * SECURITY: every route is authenticated (FlexPaySecurity::verifyCallback —
 * a secret per-route key in the URL, and/or Safaricom's source IPs). In
 * v3.4 anyone could POST a fake "payment succeeded" here and have an
 * invoice marked paid. Successful STK results are additionally confirmed
 * with Daraja's STK Query API before any money is applied, and the amount
 * applied is always the amount FlexPay itself requested.
 *
 *  • URL must be publicly reachable over HTTPS (Daraja rejects HTTP).
 *  • Do NOT output anything before <?php (no BOM, no whitespace).
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @see https://developer.safaricom.co.ke/Documentation
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/../flexpay/FlexPaySecurity.php';
require_once __DIR__ . '/../flexpay/DarajaClient.php';
require_once __DIR__ . '/../flexpay/FlexPayStore.php';
require_once __DIR__ . '/../flexpay/FlexPayLicense.php';
require_once __DIR__ . '/../flexpay/FlexPayService.php';

use WHMCS\Database\Capsule;

const FLEXPAY_CB_ROUTES = [
    'stk_result', 'c2b_check', 'c2b_receipt',
    'disbursement_result', 'disbursement_timeout',
    'reversal_result', 'reversal_timeout',
    'balance_result', 'balance_timeout',
    'status_result', 'status_timeout',
];

FlexPaySecurity::jsonHeaders();

$gatewayParams = getGatewayVariables('flexpay');
if (empty($gatewayParams['type'])) {
    http_response_code(503);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Module not activated']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Method not allowed']);
    exit;
}

$route = strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['route'] ?? '')));
if (!in_array($route, FLEXPAY_CB_ROUTES, true)) {
    http_response_code(404);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Unknown route']);
    exit;
}

// Daraja payloads are a few KB; refuse anything absurd before parsing it.
$rawInput = (string) file_get_contents('php://input', false, null, 0, 65536);
$payload  = json_decode($rawInput, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid JSON']);
    exit;
}

$auth = FlexPaySecurity::verifyCallback($route, $gatewayParams);

if (!$auth['ok']) {
    if ($route === 'c2b_check') {
        // Validation has no side effects; never block a real customer's
        // payment because of an unexpected source IP.
        echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
        exit;
    }
    // Throttle logging so a flood of forged requests can't bloat the log.
    if (FlexPaySecurity::rateLimit('cb_reject_log_' . $auth['ip'], 5, 3600)) {
        FlexPayStore::logApiCall('callback_rejected', ['route' => $route, 'ip' => $auth['ip']], ['payload_bytes' => strlen($rawInput)], false, 'security');
        if (function_exists('logActivity')) {
            logActivity('FlexPay: rejected unauthenticated callback to route "' . $route . '" from ' . $auth['ip']);
        }
    }
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Rejected']);
    exit;
}

if ($auth['via'] === 'disabled' && time() - (int) FlexPayStore::getSetting('callback_insecure_logged', 0) > 3600) {
    FlexPayStore::setSetting('callback_insecure_logged', (string) time());
    FlexPayStore::logApiCall('callback_security_off', ['route' => $route, 'ip' => $auth['ip']], [], false, 'security');
}

// Deliberately NOT a hard block: by the time a callback arrives Safaricom
// has already taken the customer's money; refusing to record it would hurt
// the paying customer. New payments are blocked at checkout instead. Local
// cache only — a slow licensing server must never delay Daraja callbacks.
$license = FlexPayLicense::check($gatewayParams, false);
if (!$license['valid'] && time() - (int) FlexPayStore::getSetting('license_callback_logged', 0) > 3600) {
    FlexPayStore::setSetting('license_callback_logged', (string) time());
    FlexPayStore::logApiCall('license_warning_callback', ['route' => $route], $license, false, 'system');
}

switch ($route) {
    case 'stk_result':
        flexpay_cb_stk_result($payload, $gatewayParams, $auth);
        break;
    case 'c2b_check':
        flexpay_cb_c2b_check($payload, $gatewayParams);
        break;
    case 'c2b_receipt':
        flexpay_cb_c2b_receipt($payload, $gatewayParams);
        break;
    case 'disbursement_result':
        flexpay_cb_disbursement_result($payload, $gatewayParams);
        break;
    case 'disbursement_timeout':
        flexpay_cb_disbursement_timeout($payload, $gatewayParams);
        break;
    case 'reversal_result':
    case 'reversal_timeout':
        flexpay_cb_reversal_result($payload, $gatewayParams, $route === 'reversal_timeout');
        break;
    case 'balance_result':
        flexpay_cb_balance_result($payload, $gatewayParams);
        break;
    case 'status_result':
        flexpay_cb_status_result($payload, $gatewayParams);
        break;
    default: // *_timeout
        flexpay_cb_generic_timeout($payload, $gatewayParams, ucfirst(str_replace('_', ' ', $route)));
}

// ═══════════════════════════════════════════════════════════════════════════════
// STK Push result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_stk_result(array $payload, array $gw, array $auth): void
{
    $stk        = $payload['Body']['stkCallback'] ?? [];
    $resultCode = isset($stk['ResultCode']) ? (string) $stk['ResultCode'] : '';
    $resultDesc = (string) ($stk['ResultDesc'] ?? '');
    $checkoutId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($stk['CheckoutRequestID'] ?? ''));

    if ($checkoutId === '') {
        logTransaction($gw['name'], $payload, 'STK Callback – Missing CheckoutRequestID');
        flexpay_json_ok();
        return;
    }

    $meta = flexpay_cb_items($stk['CallbackMetadata']['Item'] ?? [], 'Name', 'Value');
    $receipt = strtoupper((string) ($meta['MpesaReceiptNumber'] ?? ''));
    $amount  = isset($meta['Amount']) ? (float) $meta['Amount'] : null;
    $phone   = (string) ($meta['PhoneNumber'] ?? '');

    if ($receipt !== '' && !preg_match('/^[A-Z0-9]{6,20}$/', $receipt)) {
        $receipt = '';
    }

    if ($resultCode === '0') {
        // Independently confirm with Daraja before touching money.
        if (($gw['verifyStkCallbacks'] ?? 'on') === 'on') {
            [$confirmedCode, $queryResponse] = FlexPayService::fetchStkResultCode($gw, $checkoutId);

            if ($confirmedCode !== '' && $confirmedCode !== '0') {
                FlexPayStore::logApiCall('stk_callback_contradicted', ['checkout_request_id' => $checkoutId, 'callback_receipt' => $receipt, 'ip' => $auth['ip']], $queryResponse, false, 'security');
                FlexPayStore::markStkFailed($checkoutId, $confirmedCode, DarajaClient::describeResultCode($confirmedCode));
                logTransaction($gw['name'], $stk, 'STK Callback REJECTED – Daraja reports code ' . $confirmedCode . ' for this request');
                flexpay_json_ok();
                return;
            }

            if ($confirmedCode === '' && $auth['via'] !== 'token') {
                // Couldn't confirm and the request isn't key-authenticated:
                // leave it pending; the invoice poller / cron sweeper will
                // settle it from Daraja's own answer.
                FlexPayStore::logApiCall('stk_callback_unconfirmed', ['checkout_request_id' => $checkoutId, 'via' => $auth['via']], $queryResponse, false, 'callback');
                logTransaction($gw['name'], $stk, 'STK Success reported – awaiting confirmation from Daraja');
                flexpay_json_ok();
                return;
            }
        }

        $settlement = FlexPayStore::settleStk($checkoutId, $receipt ?: null, 'callback', $phone, $amount);
        logTransaction($gw['name'], $stk, 'STK Success – Receipt: ' . ($receipt ?: '(none)') . ' — ' . $settlement['message']);
    } else {
        $desc = $resultCode !== '' ? DarajaClient::describeResultCode($resultCode) : ($resultDesc ?: 'Payment failed');
        FlexPayStore::markStkFailed($checkoutId, $resultCode, $desc, json_encode($stk));
        logTransaction($gw['name'], $stk, 'STK Failed – Code ' . $resultCode . ': ' . $resultDesc);
    }

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// C2B validation — strict/lenient
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Fires before a C2B payment completes (only if Safaricom has enabled
 * external validation on the shortcode).
 *   lenient (default): always accept; unknown references are reconciled later.
 *   strict: reject paybill payments whose account number doesn't resolve to
 *           an open invoice — the customer sees the rejection on their phone.
 *           Payments with no reference (Till) are always accepted.
 */
function flexpay_cb_c2b_check(array $payload, array $gw): void
{
    $billRef = trim((string) ($payload['BillRefNumber'] ?? ''));
    $mode    = $gw['c2bValidationMode'] ?? 'lenient';

    if ($mode !== 'strict' || $billRef === '' || preg_match('/^0+$/', $billRef)) {
        echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
        return;
    }

    if ((float) ($payload['TransAmount'] ?? 0) <= 0) {
        echo json_encode(['ResultCode' => 'C2B00013', 'ResultDesc' => 'Rejected']);
        return;
    }

    // Same rigid rule as the confirmation step: the account number must be
    // exactly one open invoice.
    $resolved = FlexPayStore::resolveInvoiceReference($billRef, $gw);

    if ($resolved['reason'] !== 'matched') {
        logTransaction($gw['name'], $payload, 'C2B Validation REJECTED (strict) – Ref: ' . $billRef);
        echo json_encode(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Rejected']);
        return;
    }

    echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// C2B confirmation
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_c2b_receipt(array $payload, array $gw): void
{
    $transId   = strtoupper(trim((string) ($payload['TransID'] ?? '')));
    $amount    = round((float) ($payload['TransAmount'] ?? 0), 2);
    $billRef   = substr(trim((string) ($payload['BillRefNumber'] ?? '')), 0, 50);
    $msisdn    = trim((string) ($payload['MSISDN'] ?? ''));
    $shortcode = trim((string) ($payload['BusinessShortCode'] ?? ''));
    $name      = trim(implode(' ', array_filter([
        (string) ($payload['FirstName'] ?? ''), (string) ($payload['MiddleName'] ?? ''), (string) ($payload['LastName'] ?? ''),
    ])));
    $transTime = (string) ($payload['TransTime'] ?? '');

    if (!preg_match('/^[A-Z0-9]{6,20}$/', $transId) || $amount <= 0) {
        logTransaction($gw['name'], $payload, 'C2B Confirm – invalid TransID/amount, ignored');
        flexpay_json_ok();
        return;
    }

    // Retried confirmation, or already recorded via the STK callback. If an
    // earlier attempt died after recording the receipt but before queueing
    // or linking it, heal that payment now rather than waiting for cron.
    if (FlexPayStore::findTransactionByReceipt($transId)) {
        FlexPayStore::repairUnqueuedPayments(60, 1, $transId);
        flexpay_json_ok();
        return;
    }

    logTransaction($gw['name'], $payload, 'C2B Confirm – TransID: ' . $transId);

    // Is this the C2B echo of an STK push we started? Then settle THAT push
    // with the real receipt instead of applying the money a second time.
    $stkRow = FlexPayStore::findStkForC2B($amount, $billRef, $msisdn);
    if ($stkRow) {
        $settlement = FlexPayStore::settleStk((string) $stkRow->checkout_request_id, $transId, 'c2b_confirmation', $msisdn, $amount);
        logTransaction($gw['name'], $payload, 'C2B Confirm linked to STK ' . $stkRow->checkout_request_id . ' — ' . $settlement['message']);
        flexpay_json_ok();
        return;
    }

    $ourShortcodes = array_filter([DarajaClient::c2bShortcode($gw), DarajaClient::stkPartyB($gw), (string) ($gw['businessShortcode'] ?? '')]);
    $shortcodeOk   = $shortcode === '' || in_array($shortcode, $ourShortcodes, true);

    // Insert first: the UNIQUE receipt index makes concurrent retries of
    // this same confirmation lose the race instead of double-applying.
    $rowId = FlexPayStore::insertTransactionOnce([
        'channel'           => 'c2b',
        'direction'         => 'in',
        'mpesa_receipt'     => $transId,
        'phone'             => $msisdn,
        'amount'            => $amount,
        'account_reference' => $billRef,
        'status'            => 'success',
        'result_code'       => '0',
        'result_desc'       => 'Received — matching…',
        'raw_response'      => json_encode($payload),
    ]);
    if ($rowId === null) {
        flexpay_json_ok();
        return;
    }

    $match = $shortcodeOk
        ? FlexPayStore::matchC2BInvoice($amount, $billRef, $msisdn, $transTime, $gw)
        : ['invoice_id' => null, 'method' => 'none', 'note' => "Paid to shortcode {$shortcode}, which is not configured in FlexPay.", 'suggested' => null];

    $apply = null;
    if ($match['invoice_id'] !== null) {
        $apply = FlexPayStore::applyPaymentToInvoice($match['invoice_id'], $transId, $amount, FlexPayStore::OPEN_INVOICE_STATUSES);
    }

    if ($apply && $apply['applied']) {
        $invoice = FlexPayStore::getInvoice($match['invoice_id']);
        FlexPayStore::updateTransaction($rowId, [
            'invoice_id'      => $match['invoice_id'],
            'client_id'       => $invoice ? (int) $invoice->userid : null,
            'payment_outcome' => $apply['outcome']['outcome'] ?? null,
            'result_desc'     => $apply['message'],
        ]);
        logTransaction($gw['name'], $payload, "C2B Applied to Invoice #{$match['invoice_id']} (matched via {$match['method']}) — {$apply['message']}");
        flexpay_json_ok();
        return;
    }

    // Not applied — queue for a human, with as much context as we have.
    $notes = trim($match['note'] . ($apply ? ' ' . $apply['message'] : ''));
    $partials = FlexPayStore::detectPossiblePartials($amount, $msisdn);
    if (!empty($partials)) {
        $parts = array_map(function ($p) {
            return '#' . $p['id'] . ' (balance KES ' . number_format($p['balance_kes'], 2) . ($p['phone_match'] ? ', same phone' : '') . ')';
        }, $partials);
        $notes = trim($notes . ' Possible partial payment toward: ' . implode(', ', $parts) . '.');
    }

    $suggested = $match['suggested'] ?? $match['invoice_id'];
    if ($suggested === null && !empty($partials) && ($partials[0]['phone_match'] || count($partials) === 1)) {
        $suggested = $partials[0]['id'];
    }

    FlexPayStore::updateTransaction($rowId, [
        'result_desc'     => 'No matching invoice found — needs manual reconciliation.',
        'payment_outcome' => !empty($partials) ? 'possible_partial' : null,
    ]);

    FlexPayStore::storeUnmatched([
        'trans_id'             => $transId,
        'amount'               => $amount,
        'phone'                => $msisdn,
        'bill_ref'             => $billRef !== '' ? $billRef : '(none — likely a Till/Buy Goods payment)',
        'customer_name'        => $name,
        'notes'                => $notes,
        'suggested_invoice_id' => $suggested,
        'raw_data'             => json_encode($payload),
    ]);

    logTransaction($gw['name'], $payload, 'C2B Unmatched – queued for reconciliation. Ref: ' . ($billRef !== '' ? $billRef : '(none)') . ($notes !== '' ? ' — ' . $notes : ''));
    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// B2C (refund) result / timeout
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_disbursement_result(array $payload, array $gw): void
{
    $result         = $payload['Result'] ?? [];
    $resultCode     = (string) ($result['ResultCode'] ?? '-1');
    $resultDesc     = (string) ($result['ResultDesc'] ?? '');
    $transId        = strtoupper((string) ($result['TransactionID'] ?? ''));
    $conversationId = (string) ($result['ConversationID'] ?? '');
    $originatorId   = (string) ($result['OriginatorConversationID'] ?? '');

    $params  = flexpay_cb_items($result['ResultParameters']['ResultParameter'] ?? [], 'Key', 'Value');
    $amount  = isset($params['TransactionAmount']) ? (float) $params['TransactionAmount'] : 0.0;
    $receipt = strtoupper((string) ($params['TransactionReceipt'] ?? $transId));
    $phone   = trim(explode(' - ', (string) ($params['ReceiverPartyPublicName'] ?? ''))[0]);

    $success = $resultCode === '0';
    $refund  = FlexPayStore::findRefundByIds($conversationId, $originatorId);

    logTransaction($gw['name'], $payload, 'Disbursement ' . ($success ? 'Success' : 'Failed') . ' – ' . $resultDesc);

    FlexPayStore::insertTransactionOnce([
        'channel'                    => 'b2c',
        'direction'                  => 'out',
        'invoice_id'                 => $refund ? (int) $refund->invoice_id : null,
        'conversation_id'            => $conversationId,
        'originator_conversation_id' => $originatorId,
        // Failed B2C results often carry a placeholder TransactionID; never
        // let it occupy the unique receipt slot.
        'mpesa_receipt'              => ($success && preg_match('/^[A-Z0-9]{6,20}$/', $receipt)) ? $receipt : null,
        'phone'                      => $phone !== '' ? $phone : ($refund ? (string) $refund->phone : ''),
        'amount'                     => $amount > 0 ? $amount : ($refund ? (float) $refund->amount : 0),
        'status'                     => $success ? 'success' : 'failed',
        'result_code'                => $resultCode,
        'result_desc'                => $resultDesc ?: DarajaClient::describeResultCode($resultCode),
        'raw_response'               => json_encode($result),
    ]);

    if ($refund) {
        FlexPayStore::updateRefund((int) $refund->id, [
            'status'      => $success ? 'success' : 'failed',
            'result_desc' => $success ? ('Paid — receipt ' . $receipt) : ($resultDesc ?: DarajaClient::describeResultCode($resultCode)),
        ]);
        if (!$success && function_exists('logActivity')) {
            logActivity('FlexPay: M-Pesa refund of KES ' . number_format((float) $refund->amount, 2) . ' for Invoice #' . (int) $refund->invoice_id
                . ' FAILED (' . $resultDesc . '). WHMCS recorded the refund but no money was sent — retry it from Addons → FlexPay Dashboard → Refunds.');
        }
    }

    flexpay_json_ok();
}

function flexpay_cb_disbursement_timeout(array $payload, array $gw): void
{
    $result = $payload['Result'] ?? $payload;
    $refund = FlexPayStore::findRefundByIds((string) ($result['ConversationID'] ?? ''), (string) ($result['OriginatorConversationID'] ?? ''));

    logTransaction($gw['name'], $payload, 'Disbursement (B2C) Queue Timeout');

    if ($refund && $refund->status === 'pending') {
        FlexPayStore::updateRefund((int) $refund->id, [
            'status'      => 'failed',
            'result_desc' => 'Timed out in Safaricom\'s queue. Check with a Transaction Status query before retrying.',
        ]);
        if (function_exists('logActivity')) {
            logActivity('FlexPay: M-Pesa refund for Invoice #' . (int) $refund->invoice_id . ' timed out at Safaricom — verify and retry from the FlexPay Dashboard.');
        }
    }

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Transaction Reversal result / timeout
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * v3.4 marked Result.TransactionID as "reversed" — but that's the ID of the
 * reversal itself, not of the payment being reversed, so the original was
 * never flagged. The original is now taken from the reversal row recorded
 * when the request was sent (matched by conversation IDs), falling back to
 * the OriginalTransactionID result parameter.
 */
function flexpay_cb_reversal_result(array $payload, array $gw, bool $isTimeout): void
{
    $result         = $payload['Result'] ?? [];
    $resultCode     = $isTimeout ? 'timeout' : (string) ($result['ResultCode'] ?? '-1');
    $resultDesc     = $isTimeout ? 'Reversal timed out in Safaricom\'s queue.' : (string) ($result['ResultDesc'] ?? '');
    $conversationId = (string) ($result['ConversationID'] ?? '');
    $originatorId   = (string) ($result['OriginatorConversationID'] ?? '');
    $reversalTxnId  = strtoupper((string) ($result['TransactionID'] ?? ''));
    $params         = flexpay_cb_items($result['ResultParameters']['ResultParameter'] ?? [], 'Key', 'Value');

    $success = $resultCode === '0';

    logTransaction($gw['name'], $payload, 'Reversal ' . ($success ? 'Success' : 'Failed') . ' – ' . $resultDesc);

    $row = null;
    if ($conversationId !== '' || $originatorId !== '') {
        $row = Capsule::table('flexpay_transactions')
            ->where('channel', 'reversal')
            ->where(function ($q) use ($conversationId, $originatorId) {
                if ($conversationId !== '') {
                    $q->orWhere('conversation_id', $conversationId);
                }
                if ($originatorId !== '') {
                    $q->orWhere('originator_conversation_id', $originatorId);
                }
            })
            ->first();
    }

    $original = strtoupper((string) ($row->account_reference ?? ($params['OriginalTransactionID'] ?? '')));

    $update = [
        'status'       => $success ? 'success' : 'failed',
        'result_code'  => $resultCode,
        'result_desc'  => $resultDesc ?: DarajaClient::describeResultCode($resultCode),
        'raw_response' => json_encode($result),
    ];
    if ($success && preg_match('/^[A-Z0-9]{6,20}$/', $reversalTxnId) && !FlexPayStore::findTransactionByReceipt($reversalTxnId)) {
        $update['mpesa_receipt'] = $reversalTxnId;
    }
    if (isset($params['Amount'])) {
        $update['amount'] = (float) $params['Amount'];
    }

    if ($row) {
        FlexPayStore::updateTransaction((int) $row->id, $update);
    } else {
        FlexPayStore::insertTransactionOnce(array_merge($update, [
            'channel'                    => 'reversal',
            'direction'                  => 'out',
            'conversation_id'            => $conversationId,
            'originator_conversation_id' => $originatorId,
            'account_reference'          => $original,
        ]));
    }

    if ($success && $original !== '') {
        $originalRow = FlexPayStore::findTransactionByReceipt($original);
        Capsule::table('flexpay_transactions')
            ->where('mpesa_receipt', $original)
            ->where('status', 'success')
            ->update(['status' => 'reversed', 'updated_at' => FlexPayStore::now()]);

        // Let WHMCS record the reversal natively (reversal transaction on the
        // invoice, invoice → Collections, due dates reverted) when the
        // original payment was credited to an invoice.
        $whmcsNote = '';
        $credited  = Capsule::table('tblaccounts')->where('transid', $original)->where('gateway', 'flexpay')->exists();
        if ($credited && function_exists('paymentReversed')) {
            try {
                paymentReversed($reversalTxnId !== '' ? $reversalTxnId : ('REV-' . $original), $original);
                $whmcsNote = ' WHMCS has recorded the reversal on the invoice.';
            } catch (\Throwable $e) {
                $whmcsNote = ' WHMCS could not record the reversal automatically (' . $e->getMessage() . ') — record it on the invoice manually.';
            }
        } elseif ($credited) {
            $whmcsNote = ' Record the refund on the invoice in WHMCS.';
        }

        if (function_exists('logActivity')) {
            logActivity('FlexPay: M-Pesa transaction ' . $original . ' was reversed'
                . (($originalRow && $originalRow->invoice_id) ? ' (Invoice #' . (int) $originalRow->invoice_id . ').' : '.') . $whmcsNote);
        }
    }

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Account Balance result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_balance_result(array $payload, array $gw): void
{
    $result     = $payload['Result'] ?? [];
    $resultCode = (string) ($result['ResultCode'] ?? '-1');
    $params     = flexpay_cb_items($result['ResultParameters']['ResultParameter'] ?? [], 'Key', 'Value');

    logTransaction($gw['name'], $payload, 'Balance Query Result – Code ' . $resultCode);
    FlexPayStore::logApiCall('balance_result', ['code' => $resultCode], $result, $resultCode === '0', 'callback');

    if ($resultCode === '0') {
        $accounts = flexpay_parse_balance_string((string) ($params['AccountBalance'] ?? ''));
        FlexPayStore::recordBalanceSnapshot([
            'shortcode'       => DarajaClient::c2bShortcode($gw),
            'working_account' => $accounts['working'],
            'utility_account' => $accounts['utility'],
            'charges_account' => $accounts['charges'],
            'raw_response'    => json_encode($result),
        ]);
    }

    flexpay_json_ok();
}

/**
 * Parse Daraja's AccountBalance string, accounts separated by "&":
 *   "{Name}|{Currency}|{Amount}|{Available}|{Reserved}|{Uncleared}"
 *
 * @return array{working: float, utility: float, charges: float}
 */
function flexpay_parse_balance_string(string $raw): array
{
    $out = ['working' => 0.0, 'utility' => 0.0, 'charges' => 0.0];

    foreach (explode('&', $raw) as $segment) {
        $parts = explode('|', trim($segment));
        if (count($parts) < 3) {
            continue;
        }
        $name  = strtolower($parts[0]);
        $total = (float) $parts[2];

        if (strpos($name, 'working') !== false) {
            $out['working'] = $total;
        } elseif (strpos($name, 'utility') !== false) {
            $out['utility'] = $total;
        } elseif (strpos($name, 'charges') !== false) {
            $out['charges'] = $total;
        }
    }

    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Transaction Status Query result
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Status queries are sent by the customer "Verify your payment" box and the
 * admin Verify tool when a receipt isn't on file. When the result confirms
 * a completed payment INTO one of our shortcodes, it's recorded and:
 *   - admin query with an invoice → applied to that invoice (an admin
 *     chose the invoice, which is manual reconciliation);
 *   - customer query → queued for one-click admin approval with the
 *     invoice pre-filled. Only with Self-Verify Mode = "apply" AND the
 *     paying phone matching the invoice's client is it applied directly.
 */
function flexpay_cb_status_result(array $payload, array $gw): void
{
    $result     = $payload['Result'] ?? [];
    $resultCode = (string) ($result['ResultCode'] ?? '-1');
    $resultDesc = (string) ($result['ResultDesc'] ?? '');
    $params     = flexpay_cb_items($result['ResultParameters']['ResultParameter'] ?? [], 'Key', 'Value');

    logTransaction($gw['name'], $payload, 'Status Query Result – Code ' . $resultCode . ': ' . $resultDesc);

    $context = FlexPayStore::takeStatusQueryContext($result);
    FlexPayStore::logApiCall('status_async_result', ['context' => $context], $result, $resultCode === '0', 'callback');

    if ($resultCode !== '0' || !$context) {
        flexpay_json_ok();
        return;
    }

    $receipt     = strtoupper((string) ($params['ReceiptNo'] ?? ''));
    $status      = strtolower((string) ($params['TransactionStatus'] ?? ''));
    $amount      = round((float) ($params['Amount'] ?? 0), 2);
    $debitParty  = (string) ($params['DebitPartyName'] ?? '');
    $creditParty = (string) ($params['CreditPartyName'] ?? '');
    $creditCode  = trim(explode(' - ', $creditParty)[0]);
    $invoiceId   = (int) ($context['invoice_id'] ?? 0);

    $ourShortcodes = array_filter([DarajaClient::c2bShortcode($gw), DarajaClient::stkPartyB($gw), (string) ($gw['businessShortcode'] ?? '')]);

    if ($receipt === '' || $receipt !== strtoupper((string) ($context['receipt'] ?? ''))
        || $status !== 'completed' || $amount <= 0
        || !in_array($creditCode, $ourShortcodes, true)) {
        FlexPayStore::logApiCall('status_result_not_applicable', ['receipt' => $receipt, 'status' => $status, 'credit' => $creditCode], $context, false, 'callback');
        flexpay_json_ok();
        return;
    }

    if (FlexPayStore::findTransactionByReceipt($receipt)) {
        flexpay_json_ok(); // already on file — nothing to do
        return;
    }

    $payerPhone = trim(explode(' - ', $debitParty)[0]);
    $rowId = FlexPayStore::insertTransactionOnce([
        'channel'           => 'c2b',
        'direction'         => 'in',
        'mpesa_receipt'     => $receipt,
        'phone'             => $payerPhone,
        'amount'            => $amount,
        'account_reference' => 'status-query',
        'status'            => 'success',
        'result_code'       => '0',
        'result_desc'       => 'Confirmed by Transaction Status query (' . ($context['purpose'] ?? 'query') . ').',
        'raw_response'      => json_encode($result),
    ]);
    if ($rowId === null) {
        flexpay_json_ok();
        return;
    }

    $autoApply = false;
    if ($invoiceId > 0 && ($context['purpose'] ?? '') === 'admin_verify') {
        $autoApply = true;
    } elseif ($invoiceId > 0 && ($context['purpose'] ?? '') === 'customer_verify' && FlexPayStore::selfVerifyApplies($gw)) {
        $invoice = FlexPayStore::getInvoice($invoiceId);
        $client  = $invoice ? Capsule::table('tblclients')->where('id', $invoice->userid)->first(['phonenumber']) : null;
        $autoApply = $client && FlexPayStore::phoneMatches((string) $client->phonenumber, $payerPhone);
    }

    $row = FlexPayStore::findTransactionByReceipt($receipt);
    if ($autoApply && $row) {
        $applied = FlexPayStore::applyOrphanedTransaction($row, $invoiceId, (string) ($context['actor'] ?? $context['purpose']));
        if ($applied['success']) {
            flexpay_json_ok();
            return;
        }
    }

    FlexPayStore::storeUnmatched([
        'trans_id'             => $receipt,
        'amount'               => $amount,
        'phone'                => $payerPhone,
        'bill_ref'             => '(verified by status query)',
        'customer_name'        => trim(implode(' - ', array_slice(explode(' - ', $debitParty), 1))),
        'notes'                => $invoiceId > 0
            ? "Confirmed by Safaricom. Customer on Invoice #{$invoiceId} submitted this receipt via self-verify — approve to apply it."
            : 'Confirmed by Transaction Status query; not linked to an invoice.',
        'suggested_invoice_id' => $invoiceId ?: null,
        'raw_data'             => json_encode($result),
    ]);

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Shared helpers
// ═══════════════════════════════════════════════════════════════════════════════

/** Flatten Daraja's [{Name|Key: x, Value: y}, ...] lists into [x => y]. */
function flexpay_cb_items($items, string $keyField, string $valueField): array
{
    $out = [];
    if (!is_array($items)) {
        return $out;
    }
    // A single item is sometimes sent as an object rather than a list.
    if (isset($items[$keyField])) {
        $items = [$items];
    }
    foreach ($items as $item) {
        if (is_array($item) && isset($item[$keyField]) && is_scalar($item[$keyField])) {
            $value = $item[$valueField] ?? null;
            $out[(string) $item[$keyField]] = is_scalar($value) ? $value : null;
        }
    }
    return $out;
}

function flexpay_cb_generic_timeout(array $payload, array $gw, string $label): void
{
    logTransaction($gw['name'], $payload, $label);
    flexpay_json_ok();
}

function flexpay_json_ok(): void
{
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

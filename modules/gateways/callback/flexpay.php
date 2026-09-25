<?php
/**
 * FlexPay Daraja Callback Handler
 *
 * Receives every asynchronous notification Safaricom sends, routed by the
 * ?route= query parameter. None of these routes contain the word "mpesa"
 * anywhere in the path or parameter values, per the no-mpesa-in-URL
 * requirement:
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
 * IMPORTANT:
 *  • URL must be publicly reachable over HTTPS (Daraja rejects HTTP).
 *  • Always return HTTP 200 with {"ResultCode":0,...} — Safaricom retries on non-200.
 *  • Do NOT output anything before <?php (no BOM, no whitespace).
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 * @see https://developer.safaricom.co.ke/Documentation
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/../flexpay/DarajaClient.php';
require_once __DIR__ . '/../flexpay/FlexPayStore.php';
require_once __DIR__ . '/../flexpay/FlexPayLicense.php';

$gatewayModuleName = 'flexpay';
$gatewayParams      = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// Deliberately NOT a hard block here: by the time a callback arrives,
// Safaricom has already told the customer their payment succeeded — if
// the license lapsed between the STK prompt being sent and the callback
// landing, refusing to apply that payment would leave a paying customer
// stuck with money gone and no credited invoice, which is a worse
// outcome than letting an already-initiated payment complete. New
// payment INITIATION is blocked instead, at checkout.php and
// flexpay_link() — this just logs clearly so the admin sees it.
$license = FlexPayLicense::check($gatewayParams);
if (!$license['valid']) {
    FlexPayStore::logApiCall('license_warning_callback', ['route' => $_GET['route'] ?? ''], $license, false, 'system');
}

$rawInput = (string) file_get_contents('php://input');
$payload  = json_decode($rawInput, true) ?? [];
$route    = strtolower(trim($_GET['route'] ?? 'stk_result'));

header('Content-Type: application/json');

switch ($route) {

    case 'stk_result':
        flexpay_cb_stk_result($payload, $gatewayParams, $gatewayModuleName);
        break;

    case 'c2b_check':
        flexpay_cb_c2b_check($payload, $gatewayParams);
        break;

    case 'c2b_receipt':
        flexpay_cb_c2b_receipt($payload, $gatewayParams, $gatewayModuleName);
        break;

    case 'disbursement_result':
        flexpay_cb_disbursement_result($payload, $gatewayParams);
        break;

    case 'disbursement_timeout':
        flexpay_cb_generic_timeout($payload, 'Disbursement (B2C) Queue Timeout');
        break;

    case 'reversal_result':
        flexpay_cb_reversal_result($payload, $gatewayParams);
        break;

    case 'reversal_timeout':
        flexpay_cb_generic_timeout($payload, 'Reversal Queue Timeout');
        break;

    case 'balance_result':
        flexpay_cb_balance_result($payload, $gatewayParams);
        break;

    case 'balance_timeout':
        flexpay_cb_generic_timeout($payload, 'Balance Query Timeout');
        break;

    case 'status_result':
        flexpay_cb_status_result($payload, $gatewayParams);
        break;

    case 'status_timeout':
        flexpay_cb_generic_timeout($payload, 'Transaction Status Query Timeout');
        break;

    default:
        flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// STK Push result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_stk_result(array $payload, array $gw, string $moduleName): void
{
    $stk        = $payload['Body']['stkCallback'] ?? [];
    $resultCode = isset($stk['ResultCode']) ? (int) $stk['ResultCode'] : -1;
    $resultDesc = $stk['ResultDesc'] ?? 'No description';
    $checkoutId = $stk['CheckoutRequestID'] ?? '';
    $merchantId = $stk['MerchantRequestID'] ?? '';

    if (empty($checkoutId)) {
        logTransaction($gw['name'], $payload, 'STK Callback – Missing CheckoutRequestID');
        flexpay_json_ok();
        return;
    }

    $meta = [];
    foreach (($stk['CallbackMetadata']['Item'] ?? []) as $item) {
        if (isset($item['Name'])) {
            $meta[$item['Name']] = $item['Value'] ?? null;
        }
    }

    $receipt = (string) ($meta['MpesaReceiptNumber'] ?? '');
    $amount  = isset($meta['Amount']) ? (float) $meta['Amount'] : 0.0;
    $phone   = (string) ($meta['PhoneNumber'] ?? '');

    if ($resultCode === 0 && $receipt !== '') {
        $settlement = FlexPayStore::settleStkSuccess($checkoutId, $receipt, $amount, $phone, $resultDesc, $moduleName, 'callback');
        logTransaction($gw['name'], $stk, 'STK Success – Receipt: ' . $receipt . ' — ' . $settlement['message']);

    } else {
        $invoiceId = flexpay_lookup_pending_invoice($checkoutId);
        logTransaction($gw['name'], $stk, 'STK Failed – Code ' . $resultCode . ': ' . $resultDesc);

        FlexPayStore::recordTransaction([
            'channel'             => 'stk',
            'direction'           => 'in',
            'invoice_id'          => $invoiceId,
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
            'status'              => 'failed',
            'result_code'         => (string) $resultCode,
            'result_desc'         => DarajaClient::describeResultCode($resultCode),
            'raw_response'        => json_encode($stk),
        ]);
    }

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// C2B validation — intelligent strict/lenient handling
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Fires before a C2B payment is finalized. This is where "intelligent"
 * automation actually happens:
 *
 *  - lenient mode (default): always accept. Unrecognised references get
 *    reconciled later in the dashboard — never blocks a real payment due
 *    to a typo'd account number.
 *  - strict mode: rejects up-front if the reference doesn't map to an
 *    open, unpaid invoice, telling the customer immediately (M-Pesa shows
 *    the rejection reason on their phone) rather than silently capturing
 *    an unreconciled payment.
 *
 * Either mode tolerates common reference typos by trying multiple parse
 * strategies (see flexpay_parse_invoice_ref) before giving up.
 */
function flexpay_cb_c2b_check(array $payload, array $gw): void
{
    $billRef = (string) ($payload['BillRefNumber'] ?? '');
    $amount  = (float) ($payload['TransAmount'] ?? 0);
    $mode    = $gw['c2bValidationMode'] ?? 'lenient';

    logTransaction($gw['name'], $payload, 'C2B Validate (' . $mode . ') – Ref: ' . ($billRef ?: '(none)'));

    if ($mode !== 'strict') {
        echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
        return;
    }

    $billRefLooksUsable = (trim($billRef) !== '') && !preg_match('/^0+$/', trim($billRef));

    // Till (Buy Goods) payments never carry a usable BillRefNumber by
    // design — Safaricom doesn't ask the customer for one. Rejecting
    // every till payment outright in strict mode would block 100% of
    // legitimate Till transactions, so instead we check whether the
    // amount unambiguously matches exactly one open invoice. If it does,
    // accept now and let the confirmation step apply it via the same
    // amount-matching logic. If it's ambiguous or doesn't match anything,
    // we still accept (since rejecting loses the payment for everyone,
    // whereas accepting-then-queueing-for-reconciliation loses nothing)
    // and let the dashboard's Reconciliation tab handle it.
    if (!$billRefLooksUsable) {
        echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
        return;
    }

    $invoiceId = flexpay_parse_invoice_ref($billRef);

    if ($invoiceId === null) {
        // Couldn't extract any plausible invoice number at all
        echo json_encode(['ResultCode' => 'C2B00012', 'ResultDesc' => 'Invalid account number format']);
        return;
    }

    try {
        $invoice = \WHMCS\Database\Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->whereIn('status', ['Unpaid', 'Overdue'])
            ->first(['id', 'total']);

        if (!$invoice) {
            echo json_encode(['ResultCode' => 'C2B00011', 'ResultDesc' => 'Invoice not found or already paid']);
            return;
        }
    } catch (\Exception $e) {
        // DB error mid-validation — accept to be safe, reconcile in confirm step
    }

    echo json_encode(['ResultCode' => '0', 'ResultDesc' => 'Accepted']);
}

// ═══════════════════════════════════════════════════════════════════════════════
// C2B confirmation — intelligent dual-strategy reconciliation:
//   - Paybill payments carry a real BillRefNumber → match by invoice
//     reference (tolerating typos), exactly as before.
//   - Till (Buy Goods) payments carry NO usable account reference at all
//     — Safaricom's own C2B payload leaves BillRefNumber empty for these,
//     since the customer never enters one. For these, we intelligently
//     match by amount against currently-open invoices, using the
//     transaction timestamp to prefer the most recently due/created
//     invoice when more than one open invoice shares the same amount.
//     If the match isn't unambiguous, we never guess — it goes to the
//     Reconciliation queue for a human to confirm in one click.
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_c2b_receipt(array $payload, array $gw, string $moduleName): void
{
    $transId   = (string) ($payload['TransID']       ?? '');
    $amount    = (float)  ($payload['TransAmount']   ?? 0);
    $billRef   = (string) ($payload['BillRefNumber'] ?? '');
    $phone     = (string) ($payload['MSISDN']        ?? '');
    $firstName = (string) ($payload['FirstName']     ?? '');
    $lastName  = (string) ($payload['LastName']      ?? '');
    $transTime = (string) ($payload['TransTime']     ?? ''); // YYYYMMDDHHmmss, Safaricom's own timestamp

    if (empty($transId)) {
        logTransaction($gw['name'], $payload, 'C2B Confirm – Missing TransID');
        flexpay_json_ok();
        return;
    }

    logTransaction($gw['name'], $payload, 'C2B Confirm – TransID: ' . $transId);

    $invoiceId    = null;
    $matched      = false;
    $matchMethod  = 'none';
    $billRefLooksUsable = (trim($billRef) !== '') && !preg_match('/^0+$/', trim($billRef));

    // ── Strategy 1: Paybill-style reference parsing (existing behaviour) ──
    if ($billRefLooksUsable) {
        $parsedRef = flexpay_parse_invoice_ref($billRef);

        if ($parsedRef !== null) {
            try {
                $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $parsedRef)->first(['id', 'status', 'total']);
                if ($invoice) {
                    $invoiceId   = $parsedRef;
                    $matchMethod = 'account_reference';
                }
            } catch (\Exception $e) {
                // fall through to strategy 2
            }
        }
    }

    // ── Strategy 2: Till-style intelligent amount + timestamp matching ──
    // Used whenever there's no usable reference at all (the normal case
    // for Buy Goods/Till payments) OR the reference didn't resolve to a
    // real invoice. Looks for open invoices whose total matches exactly;
    // if more than one open invoice shares that exact amount, we
    // deliberately do NOT guess — ambiguous matches always go to the
    // Reconciliation queue so a human confirms which one it was, rather
    // than risking crediting the wrong customer's invoice.
    if ($invoiceId === null) {
        $candidate = flexpay_find_invoice_by_amount($amount, $transTime);

        if ($candidate !== null) {
            $invoiceId   = $candidate;
            $matchMethod = 'amount_timestamp';
        }
    }

    $outcome = null;

    if ($invoiceId !== null) {
        try {
            $normalizedInvoiceId = checkCbInvoiceID($invoiceId, $gw['name']);
            checkCbTransID($transId);
            addInvoicePayment($normalizedInvoiceId, $transId, $amount, 0, $moduleName);
            $matched   = true;
            $invoiceId = $normalizedInvoiceId;
            $outcome   = FlexPayStore::classifyPaymentOutcome($normalizedInvoiceId, $amount);
            logTransaction($gw['name'], $payload, "C2B Applied to Invoice #{$invoiceId} (matched via {$matchMethod}) — {$outcome['message']}");
        } catch (\Exception $e) {
            // checkCbInvoiceID/checkCbTransID may die() intentionally on
            // duplicate detection — that's WHMCS's own safe behaviour.
        }
    }

    // When nothing matched exactly, check whether this amount could
    // plausibly be a PARTIAL payment toward one or more open invoices —
    // purely informational, never auto-applied (see
    // flexpay_detect_possible_partial's docblock for why). This turns a
    // bare "no matching invoice found" into actionable context for
    // whoever reconciles it: "this could be a partial payment toward
    // Invoice #X (KES Y still owed)" instead of starting from zero.
    $possiblePartials = [];
    if (!$matched) {
        $possiblePartials = flexpay_detect_possible_partial($amount);
    }

    $partialNote = '';
    if (!empty($possiblePartials)) {
        $parts = array_map(
            fn($p) => "#{$p['id']} (balance KES " . number_format($p['balance'], 2) . ")",
            array_slice($possiblePartials, 0, 5) // cap the note length if many invoices qualify
        );
        $partialNote = ' Possible partial payment toward: ' . implode(', ', $parts) . '.';
    }

    FlexPayStore::recordTransaction([
        'channel'           => 'c2b',
        'direction'         => 'in',
        'invoice_id'        => $matched ? $invoiceId : null,
        'mpesa_receipt'     => $transId,
        'phone'             => $phone,
        'amount'            => $amount,
        'account_reference' => $billRef,
        'status'            => 'success',
        'result_code'       => '0',
        'result_desc'       => $matched
            ? ($outcome['message'] ?? "Matched and applied (via {$matchMethod})")
            : ('No matching invoice found — needs manual reconciliation.' . $partialNote),
        'payment_outcome'   => $matched ? ($outcome['outcome'] ?? null) : (!empty($possiblePartials) ? 'possible_partial' : null),
        'raw_response'      => json_encode($payload),
    ]);

    if (!$matched) {
        FlexPayStore::storeUnmatched([
            'trans_id'      => $transId,
            'amount'        => $amount,
            'phone'         => $phone,
            'bill_ref'      => $billRef ?: '(none — likely a Till/Buy Goods payment)',
            'customer_name' => trim($firstName . ' ' . $lastName),
            'notes'         => $partialNote ? trim($partialNote) : '',
            'raw_data'      => json_encode($payload),
        ]);
        logTransaction($gw['name'], $payload, 'C2B Unmatched – stored for dashboard reconciliation. Ref: ' . ($billRef ?: '(none)') . $partialNote);
    }

    flexpay_json_ok();
}

/**
 * Intelligent amount-based invoice matching for Till (Buy Goods) C2B
 * payments, which never carry a usable account reference. Looks for
 * currently-open (Unpaid/Overdue) invoices whose total exactly matches
 * the amount paid.
 *
 * Disambiguation when multiple open invoices share the same total:
 *   - If a transaction timestamp is available, prefer the invoice whose
 *     due date is closest to (on or before) that timestamp — the most
 *     likely candidate a customer would be paying right now.
 *   - If still tied, or no timestamp is available, refuse to guess and
 *     return null so the payment is queued for manual reconciliation
 *     instead of risking a wrong match.
 *
 * Deliberately does NOT attempt partial-payment matching here — matching
 * an amount LESS than an invoice's total has no natural uniqueness
 * signal (KES 400 could plausibly be a partial payment toward any open
 * invoice with a balance of 400 or more), so auto-applying it would risk
 * crediting the wrong customer. See flexpay_detect_possible_partial()
 * for how that case is surfaced instead — flagged for a human, never
 * auto-applied.
 *
 * @param  float  $amount
 * @param  string $transTime  Safaricom TransTime, format YYYYMMDDHHmmss (may be empty)
 * @return int|null
 */
function flexpay_find_invoice_by_amount(float $amount, string $transTime = ''): ?int
{
    if ($amount <= 0) {
        return null;
    }

    try {
        $candidates = \WHMCS\Database\Capsule::table('tblinvoices')
            ->whereIn('status', ['Unpaid', 'Overdue'])
            ->where('total', $amount)
            ->orderBy('duedate', 'asc')
            ->get(['id', 'duedate', 'date']);
    } catch (\Exception $e) {
        return null;
    }

    $count = is_array($candidates) ? count($candidates) : $candidates->count();

    if ($count === 0) {
        return null;
    }

    if ($count === 1) {
        $first = is_array($candidates) ? $candidates[0] : $candidates->first();
        return (int) $first->id;
    }

    // Multiple open invoices share this exact amount — try to disambiguate
    // using the transaction timestamp, preferring the invoice due on or
    // closest to the day the payment was made (the customer is most
    // likely settling the bill that's currently due, not a future one).
    // Comparison is at day granularity — due dates have no meaningful
    // time-of-day component, and this avoids spurious "near ties" caused
    // by time-of-day noise that would otherwise make disambiguation less
    // deterministic than it should be.
    if ($transTime !== '' && preg_match('/^\d{14}$/', $transTime)) {
        $txnDateOnly = \DateTime::createFromFormat('Ymd', substr($transTime, 0, 8));

        if ($txnDateOnly) {
            $txnDateOnly->setTime(0, 0, 0);
            $bestDiffDays = null;
            $tiedAtBest   = [];

            foreach ($candidates as $inv) {
                if (empty($inv->duedate) || $inv->duedate === '0000-00-00') {
                    continue;
                }
                $due = \DateTime::createFromFormat('Y-m-d', substr($inv->duedate, 0, 10));
                if (!$due) {
                    continue;
                }
                $due->setTime(0, 0, 0);

                $diffDays = abs($txnDateOnly->diff($due)->days);

                if ($bestDiffDays === null || $diffDays < $bestDiffDays) {
                    $bestDiffDays = $diffDays;
                    $tiedAtBest   = [$inv];
                } elseif ($diffDays === $bestDiffDays) {
                    $tiedAtBest[] = $inv;
                }
            }

            if ($bestDiffDays !== null && count($tiedAtBest) === 1) {
                return (int) $tiedAtBest[0]->id;
            }

            // Either a genuine tie at the closest distance, or no invoice
            // had a usable due date at all — refuse to guess.
            return null;
        }
    }

    // No usable transaction timestamp to disambiguate with, and more than
    // one invoice matches the amount — refuse to guess.
    return null;
}

/**
 * Detect whether an unmatched amount PLAUSIBLY represents a partial
 * payment toward one or more currently-open invoices, purely to give a
 * human reconciler useful context — this NEVER auto-applies anything.
 *
 * Unlike exact-amount matching (flexpay_find_invoice_by_amount), there's
 * no natural uniqueness signal for "this amount is less than the balance
 * of invoice X" — many open invoices could plausibly be the target of a
 * partial payment. Auto-applying here would risk crediting the wrong
 * customer's invoice, which is a real mistake with real consequences, so
 * this function only ever informs, never decides.
 *
 * @param  float $amount
 * @return array  List of ['id' => int, 'total' => float, 'balance' => float]
 *                for open invoices whose balance is greater than the
 *                amount paid (i.e. this payment could plausibly be a
 *                partial payment toward them). Empty array if amount is
 *                zero, or no invoice's balance exceeds it.
 */
function flexpay_detect_possible_partial(float $amount): array
{
    if ($amount <= 0) {
        return [];
    }

    try {
        $candidates = \WHMCS\Database\Capsule::table('tblinvoices')
            ->whereIn('status', ['Unpaid', 'Overdue'])
            ->where('balance', '>', $amount)
            ->orderBy('duedate', 'asc')
            ->get(['id', 'total', 'balance']);
    } catch (\Exception $e) {
        return [];
    }

    $out = [];
    foreach ($candidates as $inv) {
        $out[] = ['id' => (int) $inv->id, 'total' => (float) $inv->total, 'balance' => (float) $inv->balance];
    }

    return $out;
}

/**
 * Intelligently extract a WHMCS invoice ID from a customer-entered C2B
 * account reference, tolerating common variations:
 *
 *   "INV-42"   → 42
 *   "INV42"    → 42
 *   "inv 42"   → 42
 *   "42"       → 42
 *   "0000042"  → 42
 *   "INV-42-A" → 42  (extra suffix ignored)
 *   "garbage"  → null
 *
 * @param  string $ref
 * @return int|null
 */
function flexpay_parse_invoice_ref(string $ref): ?int
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }

    // Strategy 1: any run of digits anywhere in the string
    if (preg_match('/(\d+)/', $ref, $m)) {
        $num = (int) $m[1];
        if ($num > 0) {
            return $num;
        }
    }

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════════
// B2C (disbursement / refund) result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_disbursement_result(array $payload, array $gw): void
{
    $result         = $payload['Result'] ?? [];
    $resultCode     = (int) ($result['ResultCode'] ?? -1);
    $resultDesc     = (string) ($result['ResultDesc'] ?? '');
    $transId        = (string) ($result['TransactionID'] ?? '');
    $conversationId = (string) ($result['ConversationID'] ?? '');

    $params = [];
    foreach (($result['ResultParameters']['ResultParameter'] ?? []) as $p) {
        if (isset($p['Key'])) {
            $params[$p['Key']] = $p['Value'] ?? null;
        }
    }

    $amount  = isset($params['TransactionAmount']) ? (float) $params['TransactionAmount'] : 0.0;
    $receipt = (string) ($params['TransactionReceipt'] ?? $transId);
    $phone   = (string) ($params['ReceiverPartyPublicName'] ?? '');

    $status = ($resultCode === 0) ? 'success' : 'failed';

    logTransaction($gw['name'], $payload, 'Disbursement ' . ucfirst($status) . ' – ' . $resultDesc);

    FlexPayStore::recordTransaction([
        'channel'         => 'b2c',
        'direction'       => 'out',
        'conversation_id' => $conversationId,
        'mpesa_receipt'   => $receipt,
        'phone'           => $phone,
        'amount'          => $amount,
        'status'          => $status,
        'result_code'     => (string) $resultCode,
        'result_desc'     => $resultDesc ?: DarajaClient::describeResultCode($resultCode),
        'raw_response'    => json_encode($result),
    ]);

    if ($conversationId) {
        FlexPayStore::updateRefundByConversationId($conversationId, [
            'status'      => $status,
            'result_desc' => $resultDesc,
        ]);
    }

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Transaction Reversal result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_reversal_result(array $payload, array $gw): void
{
    $result     = $payload['Result'] ?? [];
    $resultCode = (int) ($result['ResultCode'] ?? -1);
    $resultDesc = (string) ($result['ResultDesc'] ?? '');
    $transId    = (string) ($result['TransactionID'] ?? '');

    $status = ($resultCode === 0) ? 'success' : 'failed';

    logTransaction($gw['name'], $payload, 'Reversal ' . ucfirst($status) . ' – ' . $resultDesc);

    FlexPayStore::recordTransaction([
        'channel'      => 'reversal',
        'direction'    => 'out',
        'mpesa_receipt' => $transId . '-REV', // avoid unique clash with original receipt
        'status'       => $status,
        'result_code'  => (string) $resultCode,
        'result_desc'  => $resultDesc ?: DarajaClient::describeResultCode($resultCode),
        'raw_response' => json_encode($result),
    ]);

    // If reversal succeeded, mark the original transaction as reversed
    if ($status === 'success' && $transId) {
        try {
            \WHMCS\Database\Capsule::table('flexpay_transactions')
                ->where('mpesa_receipt', $transId)
                ->update(['status' => 'reversed', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Exception $e) {
            // Non-fatal
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
    $resultCode = (int) ($result['ResultCode'] ?? -1);

    $params = [];
    foreach (($result['ResultParameters']['ResultParameter'] ?? []) as $p) {
        if (isset($p['Key'])) {
            $params[$p['Key']] = $p['Value'] ?? null;
        }
    }

    // AccountBalance comes back as a pipe-and-ampersand encoded string, e.g.:
    // "Working Account|KES|481000.00|481000.00|0.00|0.00&Utility Account|KES|0.00|0.00|0.00|0.00"
    $balanceStr = (string) ($params['AccountBalance'] ?? '');
    $accounts   = flexpay_parse_balance_string($balanceStr);

    logTransaction($gw['name'], $payload, 'Balance Query Result – Code ' . $resultCode);

    if ($resultCode === 0) {
        FlexPayStore::recordBalanceSnapshot([
            'shortcode'        => $gw['businessShortcode'] ?? '',
            'working_account'  => $accounts['working'] ?? 0,
            'utility_account'  => $accounts['utility'] ?? 0,
            'charges_account'  => $accounts['charges'] ?? 0,
            'raw_response'     => json_encode($result),
        ]);
    }

    flexpay_json_ok();
}

/**
 * Parse Daraja's AccountBalance pipe-delimited string into a clean array.
 *
 * Format per account, separated by "&":
 *   "{Name}|{Currency}|{Total}|{Available}|{Reserved}|{Uncleared}"
 *
 * @param  string $raw
 * @return array  ['working' => float, 'utility' => float, 'charges' => float]
 */
function flexpay_parse_balance_string(string $raw): array
{
    $out = ['working' => 0.0, 'utility' => 0.0, 'charges' => 0.0];
    if ($raw === '') {
        return $out;
    }

    foreach (explode('&', $raw) as $segment) {
        $parts = explode('|', $segment);
        if (count($parts) < 3) {
            continue;
        }
        $name  = strtolower($parts[0]);
        $total = (float) $parts[2];

        if (str_contains($name, 'working')) {
            $out['working'] = $total;
        } elseif (str_contains($name, 'utility')) {
            $out['utility'] = $total;
        } elseif (str_contains($name, 'charges')) {
            $out['charges'] = $total;
        }
    }

    return $out;
}

// ═══════════════════════════════════════════════════════════════════════════════
// Transaction Status Query result
// ═══════════════════════════════════════════════════════════════════════════════
function flexpay_cb_status_result(array $payload, array $gw): void
{
    $result     = $payload['Result'] ?? [];
    $resultCode = (int) ($result['ResultCode'] ?? -1);
    $resultDesc = (string) ($result['ResultDesc'] ?? '');

    logTransaction($gw['name'], $payload, 'Status Query Result – Code ' . $resultCode . ': ' . $resultDesc);

    FlexPayStore::logApiCall('status_async_result', $payload, $result, $resultCode === 0, 'system');

    flexpay_json_ok();
}

// ═══════════════════════════════════════════════════════════════════════════════
// Shared helpers
// ═══════════════════════════════════════════════════════════════════════════════

function flexpay_lookup_pending_invoice(string $checkoutId): ?int
{
    try {
        $row = \WHMCS\Database\Capsule::table('flexpay_transactions')
            ->where('checkout_request_id', $checkoutId)
            ->first(['invoice_id']);

        return $row ? (int) $row->invoice_id : null;
    } catch (\Exception $e) {
        return null;
    }
}

function flexpay_cb_generic_timeout(array $payload, string $label): void
{
    if (function_exists('logTransaction')) {
        logTransaction('FlexPay (Daraja)', $payload, $label);
    }
    flexpay_json_ok();
}

function flexpay_json_ok(): void
{
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}

<?php
/**
 * FlexPay Poll — Universal Payment Status Poller (AJAX endpoint)
 *
 * Called by the JavaScript on the invoice page, starting immediately on
 * page load (not just after an STK push), so it catches BOTH:
 *   - STK Push payments (checkout_id supplied)
 *   - Manual paybill (C2B) payments (no checkout_id — just invoice_id)
 *
 * This means a customer who pays via the M-Pesa menu directly, without
 * ever clicking "Send Prompt", still sees their invoice page flip to
 * "Paid" automatically the moment the C2B confirmation callback lands —
 * no reload needed.
 *
 * Returns a rich, staged status payload so the frontend can show the
 * customer real progress messages (queued → sent → awaiting PIN →
 * processing → confirmed/failed) using the actual text Safaricom sent us,
 * not just a generic spinner.
 *
 * Location: modules/gateways/flexpay/poll.php
 *
 * GET parameters: checkout_id (optional), invoice_id (required)
 *
 * Response shape:
 *   {
 *     "paid": bool,
 *     "failed": bool,
 *     "stage": "queued"|"sent"|"processing"|"confirmed"|"failed"|"unknown",
 *     "message": "human readable status straight from Daraja/WHMCS",
 *     "receipt": "NLJ7RT61SV" | null,
 *     "amount": 1500.0 | null,
 *     "source": "local"|"invoice"|"live_query"
 *   }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['paid' => false, 'failed' => false, 'stage' => 'unknown', 'message' => 'Method not allowed.']);
    exit;
}

$checkoutId = trim($_GET['checkout_id'] ?? '');
$invoiceId  = (int) ($_GET['invoice_id'] ?? 0);

if (!$invoiceId) {
    echo json_encode(['paid' => false, 'failed' => false, 'stage' => 'unknown', 'message' => 'Invalid invoice reference.']);
    exit;
}

function flexpay_poll_respond(bool $paid, bool $failed, string $stage, string $message, ?string $receipt = null, $amount = null, string $source = 'local'): void
{
    echo json_encode([
        'paid'    => $paid,
        'failed'  => $failed,
        'stage'   => $stage,
        'message' => $message,
        'receipt' => $receipt,
        'amount'  => $amount,
        'source'  => $source,
    ]);
    exit;
}

// ── Tier 1: local flexpay_transactions table ───────────────────────────────
// Covers BOTH STK (matched by checkout_request_id) and C2B (matched by
// invoice_id, populated once the confirmation callback reconciles it).
try {
    $row = null;

    if ($checkoutId) {
        $row = \WHMCS\Database\Capsule::table('flexpay_transactions')
            ->where('checkout_request_id', $checkoutId)
            ->first(['status', 'result_desc', 'mpesa_receipt', 'amount', 'channel', 'payment_outcome']);
    }

    if (!$row) {
        // No checkout_id (manual paybill flow) OR STK record not found yet —
        // check whether ANY successful transaction has already been linked
        // to this invoice (covers C2B payments made independently of STK).
        $row = \WHMCS\Database\Capsule::table('flexpay_transactions')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'success')
            ->orderBy('created_at', 'desc')
            ->first(['status', 'result_desc', 'mpesa_receipt', 'amount', 'channel', 'payment_outcome']);
    }

    if ($row) {
        if ($row->status === 'success') {
            $channelLabel = ($row->channel === 'c2b') ? 'manual M-Pesa payment' : 'M-Pesa payment';

            // result_desc carries the precise outcome message (set by
            // settleStkSuccess()/the C2B handler via classifyPaymentOutcome) —
            // "KES X still due" for a partial payment, or "KES Y credited
            // to your account" for an overpayment — which matters far more
            // to the customer than a flat "confirmed". Fall back to a
            // generic line only if that's somehow empty.
            $message = $row->result_desc ?: "Payment confirmed via {$channelLabel}. Receipt: {$row->mpesa_receipt}.";

            flexpay_poll_respond(
                true, false, 'confirmed',
                $message,
                $row->mpesa_receipt, (float) $row->amount, 'local'
            );
        }
        if ($row->status === 'failed') {
            flexpay_poll_respond(
                false, true, 'failed',
                $row->result_desc ?: 'Payment was declined or cancelled.',
                null, null, 'local'
            );
        }
        if ($row->status === 'pending' && $checkoutId) {
            // We know STK is in flight but no result yet — tell the
            // customer we're actively waiting on their PIN entry.
            flexpay_poll_respond(
                false, false, 'sent',
                'Prompt delivered. Waiting for you to enter your M-Pesa PIN…',
                null, null, 'local'
            );
        }
    }
} catch (\Exception $e) {
    // fall through to next tier
}

// ── Tier 2: WHMCS invoice status ────────────────────────────────────────────
// Catches payments applied through any path (including manual admin
// marking, or a reconciliation applied from the dashboard) even if our
// own transaction table lookup above missed it for some reason.
try {
    $invoice = \WHMCS\Database\Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['status']);
    if ($invoice && $invoice->status === 'Paid') {
        flexpay_poll_respond(true, false, 'confirmed', 'Payment confirmed. Invoice marked as paid.', null, null, 'invoice');
    }
    if ($invoice && in_array($invoice->status, ['Cancelled', 'Refunded'], true)) {
        flexpay_poll_respond(false, true, 'failed', 'This invoice is ' . strtolower($invoice->status) . ' and can no longer be paid.', null, null, 'invoice');
    }
} catch (\Exception $e) {
    // fall through to next tier
}

// ── Tier 3: live Daraja query (self-healing fallback for STK) ──────────────
// THIS IS THE SELF-HEALING PATH: if Safaricom's callback was delayed,
// dropped, or never reached our server at all (a known reliability gap,
// especially in sandbox), this tier asks Safaricom directly whether the
// payment actually succeeded — and if so, SETTLES it right here using the
// exact same WHMCS-native invoice-application path the real callback
// would have used. Without this, a customer could see "Payment
// confirmed!" on screen (because Daraja genuinely says ResultCode 0)
// while their invoice silently remains unpaid forever, because nothing
// ever called addInvoicePayment(). settleStkSuccess() is idempotent, so
// if the real callback arrives moments later (or already arrived), it's
// a safe no-op rather than a double-credit.
if ($checkoutId) {
    $gatewayParams = getGatewayVariables('flexpay');

    if ($gatewayParams['type']) {
        $client = DarajaClient::fromGatewayParams($gatewayParams);

        $response = $client->stkQuery([
            'shortcode'          => $gatewayParams['businessShortcode'],
            'passkey'            => $gatewayParams['passkey'],
            'checkoutRequestId'  => $checkoutId,
        ]);

        $resultCode = isset($response['ResultCode']) ? (string) $response['ResultCode'] : '';
        $resultDesc = (string) ($response['ResultDesc'] ?? '');

        if ($resultCode === '0') {
            // Daraja confirms success. Pull the receipt/amount/phone we
            // already have on file from checkout.php's pending row (Daraja's
            // own stkQuery response does NOT reliably include the receipt
            // number — CallbackMetadata is only sent on the async callback,
            // not on this synchronous query), then settle.
            $receipt = null;
            $amount  = null;
            $phone   = null;

            try {
                $localRow = \WHMCS\Database\Capsule::table('flexpay_transactions')
                    ->where('checkout_request_id', $checkoutId)
                    ->first(['mpesa_receipt', 'amount', 'phone']);
                if ($localRow) {
                    $receipt = $localRow->mpesa_receipt ?: null;
                    $amount  = (float) $localRow->amount;
                    $phone   = $localRow->phone;
                }
            } catch (\Exception $e) {
                // fall through with nulls
            }

            // Daraja's stkQuery doesn't give us a receipt number when no
            // callback metadata exists yet. Without a receipt we cannot
            // safely call addInvoicePayment (WHMCS needs a transaction ID
            // for the ledger), so in that rare case we report success to
            // the customer for UX purposes but flag it for the admin to
            // verify/settle manually via the dashboard's Verify Payment
            // tool, rather than risk crediting with a placeholder ID.
            if ($receipt) {
                $settlement = FlexPayStore::settleStkSuccess(
                    $checkoutId, $receipt, (float) $amount, (string) $phone,
                    $resultDesc ?: 'Confirmed via live status query', 'flexpay', 'live_query_self_heal'
                );

                flexpay_poll_respond(
                    true, false, 'confirmed',
                    ($resultDesc ?: 'Payment confirmed.') . ' ' . $settlement['message'],
                    $receipt, $amount, 'live_query_self_heal'
                );
            }

            FlexPayStore::logApiCall(
                'stk_settlement_missing_receipt',
                ['checkout_request_id' => $checkoutId],
                $response,
                false,
                'live_query_self_heal'
            );

            flexpay_poll_respond(
                true, false, 'confirmed',
                'Payment confirmed by Safaricom, finalizing… if this invoice does not update within a minute, use Verify Payment in the FlexPay Dashboard.',
                null, null, 'live_query_self_heal'
            );
        }

        // 1037 = timeout (no PIN entered), 1032 = user cancelled,
        // 500.001.1001 = "still being processed" — none of these are final.
        $stillPending = in_array($resultCode, ['1032', '1037', '500.001.1001', ''], true);

        if (!$stillPending && $resultCode !== '') {
            flexpay_poll_respond(
                false, true, 'failed',
                $resultDesc ?: DarajaClient::describeResultCode($resultCode),
                null, null, 'live_query'
            );
        }

        // Still genuinely pending / being processed
        flexpay_poll_respond(false, false, 'processing', $resultDesc ?: 'Processing your payment…', null, null, 'live_query');
    }
}

// Nothing conclusive yet — for a manual-paybill visitor (no checkout_id)
// this just means "no payment seen yet", which is normal while they're
// still entering the paybill details on their phone.
flexpay_poll_respond(false, false, 'queued', 'Waiting for payment…', null, null, 'local');

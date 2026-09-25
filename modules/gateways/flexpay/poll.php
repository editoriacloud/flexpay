<?php
/**
 * FlexPay Poll — payment status poller for the invoice page (AJAX endpoint)
 *
 * Polled by the payment widget from page load, so it catches both STK
 * payments (checkout_id supplied) and manual paybill/till payments (none).
 *
 * SECURITY: requires the signed invoice token issued with the widget, and
 * every lookup is bound to that invoice — v3.4 answered for any invoice ID
 * (leaking receipts/amounts) and ran live Daraja queries for any checkout ID.
 *
 * SELF-HEALING: when an STK push is still "pending" locally ~15 seconds
 * after it was sent, this asks Daraja directly (throttled per push) and
 * settles or fails it — covering callbacks that are delayed or never
 * arrive. (In v3.4 this tier was unreachable: the pending row always
 * answered first.)
 *
 * GET parameters: invoice_id, token, since (unix time the widget rendered),
 *                 checkout_id (optional)
 *
 * Response: { paid, failed, stage, message, receipt, amount, source }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/FlexPaySecurity.php';
require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';
require_once __DIR__ . '/FlexPayService.php';

use WHMCS\Database\Capsule;

FlexPaySecurity::jsonHeaders();

function flexpay_poll_respond(bool $paid, bool $failed, string $stage, string $message, ?string $receipt = null, $amount = null, string $source = 'local', int $httpCode = 200): void
{
    http_response_code($httpCode);
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    flexpay_poll_respond(false, false, 'unknown', 'Method not allowed.', null, null, 'local', 405);
}

$invoiceId  = (int) ($_GET['invoice_id'] ?? 0);
$token      = (string) ($_GET['token'] ?? '');
$checkoutId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_GET['checkout_id'] ?? ''));
$since      = (int) ($_GET['since'] ?? 0);

if (!FlexPaySecurity::verifyInvoiceToken($invoiceId, $token)) {
    flexpay_poll_respond(false, false, 'unknown', 'Your session for this invoice has expired. Please reload the page.', null, null, 'local', 403);
}

$gatewayParams = getGatewayVariables('flexpay');

// Generous: the widget polls every 3–6 seconds.
if (!FlexPaySecurity::rateLimit('poll_ip_' . FlexPaySecurity::clientIp($gatewayParams), 120, 60)) {
    flexpay_poll_respond(false, false, 'queued', 'Waiting for payment…', null, null, 'throttled', 429);
}

// Only payments recorded after the widget rendered count as "just paid" —
// otherwise an earlier partial payment would reload the page forever.
$sinceDate = $since > 0 ? date('Y-m-d H:i:s', $since - 10) : null;

// ── Tier 1: the STK push this page started ─────────────────────────────────
if ($checkoutId !== '') {
    $row = FlexPayStore::findTransactionByCheckoutId($checkoutId);

    if ($row && (int) $row->invoice_id === $invoiceId) {
        if ($row->status === 'success') {
            flexpay_poll_respond(true, false, 'confirmed', $row->result_desc ?: 'Payment confirmed.', $row->mpesa_receipt ?: null, (float) $row->amount, 'local');
        }
        if ($row->status === 'failed' || $row->status === 'reversed') {
            flexpay_poll_respond(false, true, 'failed', $row->result_desc ?: 'Payment was declined or cancelled.', null, null, 'local');
        }

        $age         = time() - strtotime((string) $row->created_at);
        $lastChecked = $row->last_checked_at ? time() - strtotime((string) $row->last_checked_at) : PHP_INT_MAX;

        if (!empty($gatewayParams['type']) && $age >= 15 && $lastChecked >= 10) {
            $result = FlexPayService::queryStk($gatewayParams, $checkoutId, 'live_query_self_heal');
            if ($result['state'] === 'paid') {
                $fresh = FlexPayStore::findTransactionByCheckoutId($checkoutId);
                flexpay_poll_respond(true, false, 'confirmed', $result['message'], $fresh->mpesa_receipt ?? null, $fresh ? (float) $fresh->amount : null, 'live_query_self_heal');
            }
            if ($result['state'] === 'failed') {
                flexpay_poll_respond(false, true, 'failed', $result['message'], null, null, 'live_query');
            }
            if ($result['state'] === 'pending') {
                flexpay_poll_respond(false, false, 'processing', $result['message'], null, null, 'live_query');
            }
        }

        flexpay_poll_respond(false, false, 'sent', 'Prompt sent. Waiting for you to enter your M-Pesa PIN…', null, null, 'local');
    }
}

// ── Tier 2: any payment linked to this invoice since the page loaded ──────
try {
    $query = Capsule::table('flexpay_transactions')
        ->where('invoice_id', $invoiceId)
        ->where('direction', 'in')
        ->where('status', 'success');
    if ($sinceDate !== null) {
        $query->where('updated_at', '>=', $sinceDate);
    }
    $row = $query->orderBy('updated_at', 'desc')->first(['result_desc', 'mpesa_receipt', 'amount', 'channel']);

    if ($row) {
        $label = ($row->channel === 'c2b') ? 'manual M-Pesa payment' : 'M-Pesa payment';
        flexpay_poll_respond(true, false, 'confirmed', $row->result_desc ?: "Payment confirmed via {$label}.", $row->mpesa_receipt ?: null, (float) $row->amount, 'local');
    }
} catch (\Throwable $e) {
    // fall through
}

// ── Tier 3: WHMCS invoice status (admin marked paid, reconciled, etc.) ─────
$invoice = FlexPayStore::getInvoice($invoiceId);
if ($invoice && $invoice->status === 'Paid') {
    flexpay_poll_respond(true, false, 'confirmed', 'Payment confirmed. Invoice marked as paid.', null, null, 'invoice');
}
if ($invoice && in_array($invoice->status, ['Cancelled', 'Refunded', 'Collections'], true)) {
    flexpay_poll_respond(false, true, 'failed', 'This invoice is ' . strtolower($invoice->status) . ' and can no longer be paid online.', null, null, 'invoice');
}

flexpay_poll_respond(false, false, 'queued', 'Waiting for payment…', null, null, 'local');

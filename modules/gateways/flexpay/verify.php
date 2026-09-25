<?php
/**
 * FlexPay Verify — Customer Self-Service Payment Verification (AJAX endpoint)
 *
 * Lets a customer on the invoice page confirm a payment themselves —
 * "I paid via M-Pesa, here's my receipt number" — without needing to
 * contact support or wait for an admin. This is a well-established
 * pattern for M-Pesa integrations specifically (manual paybill/till
 * payments don't always reconcile automatically), not a new concept.
 *
 * SECURITY MODEL — this endpoint has no WHMCS client login of its own to
 * rely on (the invoice page itself may be viewed by a guest with just the
 * invoice link), so trust is established the same way checkout.php
 * already does it: an HMAC token computed server-side from the specific
 * invoice ID + amount + the gateway passkey (a secret only the server
 * knows) and embedded in the page when it was legitimately rendered by
 * flexpay_link(). A request without a valid token for THIS invoice is
 * rejected outright.
 *
 * Beyond that, every lookup performed by this endpoint is invoice-locked
 * by FlexPayStore::verifyPaymentForInvoice() — see that method's docblock
 * for exactly what it does and does not allow. In short: a reference
 * belonging to a different invoice is reported as "not found", never
 * described or confirmed, and nothing here can apply a payment to any
 * invoice other than the one this request was scoped to.
 *
 * The one external-API-cost path (a live Daraja query when nothing is
 * found locally) is rate-limited per invoice — see
 * FlexPayStore::checkAndBumpVerifyRateLimit().
 *
 * Location: modules/gateways/flexpay/verify.php
 *
 * POST parameters: invoice_id, amount, passkey, csrf, reference
 *
 * Response JSON: { "success": bool, "applied": bool, "message": "..." }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'applied' => false, 'message' => 'Method not allowed.']);
    exit;
}

$invoiceId = (int)    ($_POST['invoice_id'] ?? 0);
$amount    = (int)    ($_POST['amount']     ?? 0);
$passkey   = trim((string) ($_POST['passkey']   ?? ''));
$csrfToken = trim((string) ($_POST['csrf']      ?? ''));
$reference = trim((string) ($_POST['reference'] ?? ''));

// Same HMAC trust model as checkout.php — proves this request originated
// from a legitimately-rendered invoice page for THIS exact invoice and
// amount, without needing a separate client login of its own.
if (!$invoiceId || empty($passkey) || !hash_equals(
    hash_hmac('sha256', $invoiceId . '|' . $amount, $passkey),
    $csrfToken
)) {
    echo json_encode(['success' => false, 'applied' => false, 'message' => 'Security token mismatch. Please reload the page and try again.']);
    exit;
}

if ($reference === '') {
    echo json_encode(['success' => false, 'applied' => false, 'message' => 'Please enter your M-Pesa receipt number.']);
    exit;
}

// Basic sanity cap — real M-Pesa receipts are short alphanumeric codes
// (e.g. NLJ7RT61SV); reject anything wildly oversized before it ever
// reaches a database query or an external API call.
if (strlen($reference) > 40) {
    echo json_encode(['success' => false, 'applied' => false, 'message' => 'That doesn\'t look like a valid M-Pesa receipt number.']);
    exit;
}

$result = FlexPayStore::verifyPaymentForInvoice($reference, $invoiceId, 'flexpay');

echo json_encode([
    'success' => $result['success'],
    'applied' => $result['applied'],
    'message' => $result['message'],
]);

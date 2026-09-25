<?php
/**
 * FlexPay Verify — customer self-service payment verification (AJAX endpoint)
 *
 * Lets a customer on the invoice page say "I paid, here's my M-Pesa receipt
 * number" and have the payment found and applied without contacting support.
 *
 * SECURITY: the caller must hold the signed invoice token issued with the
 * widget (proving WHMCS showed them this invoice). All matching rules and
 * rate limits are in FlexPayStore::verifyPaymentForInvoice(): receipt
 * numbers only, invoice-locked, per-invoice and per-IP limits.
 *
 * POST parameters: invoice_id, token, reference
 *
 * Response JSON: { "success": bool, "applied": bool, "already": bool, "message": "..." }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/FlexPaySecurity.php';
require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';

FlexPaySecurity::jsonHeaders();

function flexpay_verify_respond(array $body, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge(['success' => false, 'applied' => false, 'already' => false, 'message' => ''], $body));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    flexpay_verify_respond(['message' => 'Method not allowed.'], 405);
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$token     = (string) ($_POST['token'] ?? '');
$reference = substr(trim((string) ($_POST['reference'] ?? '')), 0, 40);

if (!FlexPaySecurity::verifyInvoiceToken($invoiceId, $token)) {
    flexpay_verify_respond(['message' => 'Your session for this invoice has expired. Please reload the page and try again.'], 403);
}

$gatewayParams = getGatewayVariables('flexpay');
if (empty($gatewayParams['type'])) {
    flexpay_verify_respond(['message' => 'Payment verification is temporarily unavailable. Please contact support.']);
}

$result = FlexPayStore::verifyPaymentForInvoice($reference, $invoiceId, FlexPaySecurity::clientIp($gatewayParams));

flexpay_verify_respond([
    'success' => (bool) $result['success'],
    'applied' => (bool) $result['applied'],
    'already' => (bool) ($result['already'] ?? false),
    'message' => (string) $result['message'],
]);

<?php
/**
 * FlexPay Checkout — STK Push Initiator (AJAX endpoint)
 *
 * Called by the payment widget on the WHMCS invoice page.
 *
 * SECURITY: the browser supplies only the invoice ID, the signed invoice
 * token issued when WHMCS rendered the widget, and the phone number to
 * prompt. Everything else — amount, shortcode, passkey, account
 * reference, callback URL — is derived server-side. (v3.4 accepted all of
 * these from the browser, including the Daraja passkey, which was embedded
 * in the invoice page for anyone to read.)
 *
 * Location: modules/gateways/flexpay/checkout.php
 *
 * POST parameters: invoice_id, token, phone
 *
 * Response JSON:
 *   Success: { "success": true, "checkout_request_id": "ws_CO_...", "message": "..." }
 *   Failure: { "success": false, "message": "..." }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

require_once __DIR__ . '/FlexPaySecurity.php';
require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';
require_once __DIR__ . '/FlexPayLicense.php';
require_once __DIR__ . '/FlexPayService.php';

FlexPaySecurity::jsonHeaders();

function flexpay_checkout_respond(bool $success, string $message, array $extra = [], int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    flexpay_checkout_respond(false, 'Method not allowed.', [], 405);
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$token     = (string) ($_POST['token'] ?? '');
$phone     = DarajaClient::formatPhone((string) ($_POST['phone'] ?? ''));

if (!FlexPaySecurity::verifyInvoiceToken($invoiceId, $token)) {
    flexpay_checkout_respond(false, 'Your session for this invoice has expired. Please reload the page.', [], 403);
}

if ($phone === '') {
    flexpay_checkout_respond(false, 'Invalid Safaricom phone number. Use format 07XXXXXXXX or 01XXXXXXXX.');
}

$gatewayParams = getGatewayVariables('flexpay');
if (empty($gatewayParams['type'])) {
    flexpay_checkout_respond(false, 'M-Pesa payment is temporarily unavailable. Please try another payment method.');
}

// Abuse limits: STK pushes cost money-adjacent API calls and can be used to
// spam a stranger's phone with PIN prompts.
$ip = FlexPaySecurity::clientIp($gatewayParams);
if (!FlexPaySecurity::rateLimit('stk_ip_' . $ip, 10, 600)
    || !FlexPaySecurity::rateLimit('stk_inv_' . $invoiceId, 5, 300)
    || !FlexPaySecurity::rateLimit('stk_phone_' . $phone, 3, 120)) {
    flexpay_checkout_respond(false, 'Too many payment requests. Please wait a couple of minutes and try again.', [], 429);
}

$license = FlexPayLicense::check($gatewayParams);
if (!$license['valid']) {
    flexpay_checkout_respond(false, 'M-Pesa payment is temporarily unavailable. Please try another payment method.');
}

$result = FlexPayService::initiateStk($gatewayParams, $invoiceId, $phone, 'customer', $ip);

if (!$result['success']) {
    flexpay_checkout_respond(false, $result['message']);
}

flexpay_checkout_respond(true, $result['message'], ['checkout_request_id' => $result['checkout_request_id']]);

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

$invoice = FlexPayStore::getInvoice($invoiceId);
if (!$invoice || !in_array($invoice->status, FlexPayStore::OPEN_INVOICE_STATUSES, true)) {
    flexpay_checkout_respond(false, 'This invoice is not awaiting payment. Please reload the page.');
}

$amount = FlexPayStore::invoiceBalanceInKes($invoiceId);
if ($amount === null || $amount < 1) {
    flexpay_checkout_respond(false, 'Nothing is due on this invoice. Please reload the page.');
}

$accRef    = FlexPayService::accountReference($gatewayParams, $invoiceId);
$systemUrl = FlexPayStore::systemUrl($gatewayParams);
$shortcode = (string) ($gatewayParams['businessShortcode'] ?? '');

$response = DarajaClient::fromGatewayParams($gatewayParams)->stkPush([
    'shortcode'       => $shortcode,
    'passkey'         => (string) ($gatewayParams['passkey'] ?? ''),
    'amount'          => $amount,
    'phone'           => $phone,
    'txnType'         => ($gatewayParams['transactionType'] ?? '') ?: 'CustomerPayBillOnline',
    'partyB'          => DarajaClient::stkPartyB($gatewayParams),
    'accountRef'      => $accRef,
    'transactionDesc' => 'Invoice ' . $invoiceId,
    'callbackUrl'     => FlexPaySecurity::callbackUrl($systemUrl, 'stk_result'),
]);

$success = DarajaClient::isAccepted($response);

FlexPayStore::logApiCall('stk_push', ['invoice_id' => $invoiceId, 'phone' => $phone, 'amount' => $amount], $response, $success, 'customer');
logTransaction('FlexPay (Daraja)', array_merge(['_invoice_id' => $invoiceId, '_phone' => $phone, '_amount' => $amount], $response), $success ? 'STK Push Initiated' : 'STK Push Rejected');

$checkoutId = (string) ($response['CheckoutRequestID'] ?? '');

if (!$success || $checkoutId === '') {
    // Daraja's own errorMessage values are customer-safe ("Invalid PhoneNumber" etc.)
    // but transport/internal details are not shown.
    $message = !empty($response['errorMessage']) && is_string($response['errorMessage'])
        ? $response['errorMessage']
        : 'We could not send the payment prompt right now. Please try again, or pay manually using the details below.';
    flexpay_checkout_respond(false, $message);
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
    'raw_request'         => json_encode(['shortcode' => $shortcode, 'amount' => $amount, 'phone' => $phone, 'ip' => $ip]),
]);

flexpay_checkout_respond(true, (string) ($response['CustomerMessage'] ?? 'Prompt sent! Enter your M-Pesa PIN on your phone.'), [
    'checkout_request_id' => $checkoutId,
]);

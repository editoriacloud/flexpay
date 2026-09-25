<?php
/**
 * FlexPay Checkout — STK Push Initiator (AJAX endpoint)
 *
 * Called via HTTP POST by the JavaScript on the WHMCS invoice page.
 *
 * Location: modules/gateways/flexpay/checkout.php
 *
 * POST parameters: invoice_id, amount, phone, shortcode, passkey,
 *                   txn_type, acc_ref, callback_url, csrf
 *
 * Response JSON:
 *   Success: { "success": true, "checkout_request_id": "ws_CO_...", "message": "..." }
 *   Failure: { "success": false, "message": "..." }
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';

require_once __DIR__ . '/DarajaClient.php';
require_once __DIR__ . '/FlexPayStore.php';
require_once __DIR__ . '/FlexPayLicense.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$invoiceId   = (int)    ($_POST['invoice_id']   ?? 0);
$amount      = (int)    ($_POST['amount']        ?? 0);
$phone       = (string) ($_POST['phone']         ?? '');
$shortcode   = trim((string) ($_POST['shortcode']    ?? ''));
$passkey     = trim((string) ($_POST['passkey']      ?? ''));
$txnType     = trim((string) ($_POST['txn_type']     ?? 'CustomerPayBillOnline'));
$accRef      = trim((string) ($_POST['acc_ref']      ?? ''));
$callbackUrl = trim((string) ($_POST['callback_url'] ?? ''));
$csrfToken   = trim((string) ($_POST['csrf']         ?? ''));

// CSRF check
if (empty($passkey) || !hash_equals(
    hash_hmac('sha256', $invoiceId . '|' . $amount, $passkey),
    $csrfToken
)) {
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please reload the page.']);
    exit;
}

if (!$invoiceId || $amount < 1 || empty($phone) || empty($shortcode) || empty($passkey)) {
    echo json_encode(['success' => false, 'message' => 'Missing required payment parameters.']);
    exit;
}

if (!preg_match('/^254[17]\d{8}$/', $phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Safaricom phone number. Use format 07XXXXXXXX or 01XXXXXXXX.']);
    exit;
}

$gatewayParams = getGatewayVariables('flexpay');

if (!$gatewayParams['type']) {
    echo json_encode(['success' => false, 'message' => 'M-Pesa gateway is not active in WHMCS.']);
    exit;
}

$license = FlexPayLicense::check($gatewayParams);
if (!$license['valid']) {
    echo json_encode(['success' => false, 'message' => 'M-Pesa payment is temporarily unavailable. Please try another payment method.']);
    exit;
}

$client = DarajaClient::fromGatewayParams($gatewayParams);

if (strpos($callbackUrl, 'route=') === false) {
    $callbackUrl .= (strpos($callbackUrl, '?') !== false ? '&' : '?') . 'route=stk_result';
}

$response = $client->stkPush([
    'shortcode'       => $shortcode,
    'passkey'         => $passkey,
    'amount'          => $amount,
    'phone'           => $phone,
    'txnType'         => $txnType,
    'accountRef'      => $accRef,
    'transactionDesc' => 'Invoice ' . $accRef,
    'callbackUrl'     => $callbackUrl,
]);

$success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';

FlexPayStore::logApiCall('stk_push', ['invoice_id' => $invoiceId, 'phone' => $phone, 'amount' => $amount], $response, $success, 'system');

logTransaction('FlexPay (Daraja)', array_merge(['_invoice_id' => $invoiceId, '_phone' => $phone], $response), 'STK Push Initiated');

if ($success) {

    $checkoutId = (string) ($response['CheckoutRequestID'] ?? '');
    $merchantId = (string) ($response['MerchantRequestID'] ?? '');
    $custMessage = (string) ($response['CustomerMessage'] ?? 'Request accepted');

    if ($checkoutId) {
        FlexPayStore::recordTransaction([
            'channel'             => 'stk',
            'direction'           => 'in',
            'invoice_id'          => $invoiceId,
            'checkout_request_id' => $checkoutId,
            'merchant_request_id' => $merchantId,
            'phone'               => $phone,
            'amount'              => $amount,
            'account_reference'   => $accRef,
            'status'              => 'pending',
            'raw_request'         => json_encode(['shortcode' => $shortcode, 'amount' => $amount, 'phone' => $phone]),
        ]);
    }

    echo json_encode([
        'success'             => true,
        'checkout_request_id' => $checkoutId,
        'message'             => $custMessage,
    ]);

} else {
    $errMsg = $response['errorMessage']
        ?? $response['ResponseDescription']
        ?? $response['ResultDesc']
        ?? 'STK Push request failed. Please try again.';

    echo json_encode(['success' => false, 'message' => $errMsg]);
}

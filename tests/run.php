<?php
/**
 * FlexPay end-to-end test suite.
 *
 *   cd tests && composer install && php run.php
 *
 * Builds a throwaway fake WHMCS root (real Illuminate/Capsule on SQLite,
 * stubbed WHMCS functions, mocked Daraja HTTP), serves the real module
 * files through PHP's built-in web server, and drives them over HTTP the
 * way Safaricom and a customer's browser would. Admin-side code (refunds,
 * dashboard actions, cron hooks, rendering) runs in-process.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// Dashboard code needs a session; start it before any output.
session_id('fptest');
session_start();

$repo = realpath(__DIR__ . '/..');
$root = sys_get_temp_dir() . '/flexpay-test-' . getmypid();
$db   = $root . '/test.sqlite';

// ── Build the fake WHMCS root ────────────────────────────────────────────────
exec('rm -rf ' . escapeshellarg($root));
mkdir($root . '/includes', 0777, true);
exec('cp -R ' . escapeshellarg($repo . '/modules') . ' ' . escapeshellarg($root . '/modules'));
copy(__DIR__ . '/fixtures/init.php', $root . '/init.php');
copy(__DIR__ . '/fixtures/includes/gatewayfunctions.php', $root . '/includes/gatewayfunctions.php');
copy(__DIR__ . '/fixtures/includes/invoicefunctions.php', $root . '/includes/invoicefunctions.php');
touch($db);

putenv('FP_TEST_DB=' . $db);
putenv('FP_TEST_STUBS=' . __DIR__ . '/fixtures/whmcs_stubs.php');
putenv('FP_SYSTEM_URL=https://billing.example.com/');

require __DIR__ . '/fixtures/whmcs_stubs.php';
fp_test_reset_mysql();
fp_test_boot_db($db);
echo 'Database: ' . (getenv('FP_TEST_MYSQL') ? 'MySQL/MariaDB (' . getenv('FP_TEST_MYSQL') . ')' : 'SQLite') . "\n";
fp_test_create_whmcs_tables();
fp_test_install_daraja_mock();

require $root . '/modules/gateways/flexpay.php';
require $root . '/modules/addons/flexpay_dashboard/flexpay_dashboard.php';
require $root . '/modules/addons/flexpay_dashboard/hooks.php';
DarajaClient::setTransport('fp_test_daraja_transport');

use Illuminate\Database\Capsule\Manager as DB;

FlexPayStore::ensureTables();

// ── Seed ─────────────────────────────────────────────────────────────────────
DB::table('tblcurrencies')->insert([
    ['id' => 1, 'code' => 'KES', 'prefix' => 'KSh', 'rate' => 1],
    ['id' => 2, 'code' => 'USD', 'prefix' => '$', 'rate' => 0.0077],
]);
DB::table('tblclients')->insert([
    ['id' => 1, 'firstname' => 'Amina', 'phonenumber' => '0712345678', 'currency' => 1],
    ['id' => 2, 'firstname' => 'Brian', 'phonenumber' => '+254 722 000 111', 'currency' => 2],
    ['id' => 3, 'firstname' => 'Chebet', 'phonenumber' => '0733999888', 'currency' => 1],
]);
$inv = function (int $id, int $user, float $total, string $status = 'Unpaid', string $due = '2026-09-30') {
    DB::table('tblinvoices')->insert(['id' => $id, 'userid' => $user, 'total' => $total, 'status' => $status, 'duedate' => $due]);
};
$inv(1, 1, 1500);   // STK happy path
$inv(2, 1, 900);    // forged callback target
$inv(3, 3, 2000);   // C2B by reference
$inv(4, 3, 777);    // Till / phone match
$inv(5, 1, 5000);   // partial-payment hint
$inv(6, 1, 1200);   // poll self-heal
$inv(7, 1, 300);    // poll failure
$inv(8, 3, 450);    // self-verify apply
$inv(9, 1, 650);    // self-verify live status query
$inv(10, 2, 10.00); // USD invoice
$inv(11, 3, 3000);  // cron sweeper
$inv(12, 1, 800, 'Paid'); // already paid

$gatewaySettings = [
    'testMode' => 'on', 'consumerKey' => 'ck', 'consumerSecret' => 'cs',
    'businessShortcode' => '174379', 'passkey' => 'SECRET-PASSKEY-VALUE', 'transactionType' => 'CustomerPayBillOnline',
    'accountRefPrefix' => 'INV', 'autoRegisterC2B' => 'on', 'c2bValidationMode' => 'strict',
    'c2bPhoneMatching' => 'on', 'c2bAmountOnlyMatching' => '', 'b2cInitiatorName' => 'apiop',
    'b2cSecurityCredential' => 'ENCRYPTED-CRED', 'callbackSecurity' => 'token_or_ip', 'verifyStkCallbacks' => 'on',
    'licenseKey' => 'FlexPay-TEST', 'licensingSecret' => 'lic-secret',
];
foreach ($gatewaySettings as $k => $v) {
    DB::table('tblpaymentgateways')->insert(['gateway' => 'flexpay', 'setting' => $k, 'value' => $v]);
}
function setGw(string $k, string $v): void
{
    DB::table('tblpaymentgateways')->where('gateway', 'flexpay')->where('setting', $k)->delete();
    DB::table('tblpaymentgateways')->insert(['gateway' => 'flexpay', 'setting' => $k, 'value' => $v]);
}

// A valid cached license (domain/IP unrestricted) so payment paths run.
$enc = new ReflectionMethod('FlexPayLicense', 'encodeLocalKey');
$enc->setAccessible(true);
FlexPayStore::setSetting('license_local_key', $enc->invoke(null, ['status' => 'Active', 'checkdate' => date('Ymd'), 'validdomain' => '', 'validip' => ''], 'lic-secret'));

DB::table('tbladminroles')->insert([['id' => 1, 'name' => 'Full Administrator'], ['id' => 2, 'name' => 'Support Operator']]);
DB::table('tbladmins')->insert([['id' => 1, 'roleid' => 1, 'username' => 'boss'], ['id' => 2, 'roleid' => 2, 'username' => 'helper']]);

// ── Start the web server ─────────────────────────────────────────────────────
$port   = 18000 + (getmypid() % 1000);
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
    [1 => ['file', $root . '/server.log', 'a'], 2 => ['file', $root . '/server.log', 'a']],
    $pipes,
    $root,
    ['FP_TEST_DB' => $db, 'FP_TEST_MYSQL' => (string) getenv('FP_TEST_MYSQL'), 'FP_TEST_STUBS' => __DIR__ . '/fixtures/whmcs_stubs.php', 'FP_SYSTEM_URL' => 'https://billing.example.com/', 'PATH' => getenv('PATH')]
);
register_shutdown_function(function () use ($server, $root) {
    proc_terminate($server);
    if (!getenv('FP_KEEP')) {
        exec('rm -rf ' . escapeshellarg($root));
    }
});
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

// ── Helpers ──────────────────────────────────────────────────────────────────
$GLOBALS['fp_pass'] = 0;
$GLOBALS['fp_fail'] = [];

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['fp_pass']++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $GLOBALS['fp_fail'][] = $name;
        echo "  \033[31m✗ {$name}\033[0m" . ($detail !== '' ? "\n      {$detail}" : '') . "\n";
    }
}

function section(string $title): void
{
    echo "\n\033[1m{$title}\033[0m\n";
}

/** @return array{0:int,1:array|null,2:string} */
function http(string $method, string $path, array $params = [], ?string $json = null, array $headers = []): array
{
    global $port;
    $url = 'http://127.0.0.1:' . $port . $path;
    if ($method === 'GET' && $params) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true), $body];
}

function mockDaraja(string $path, array $body, int $status = 200): void
{
    DB::table('fp_daraja_mock')->where('path', $path)->delete();
    DB::table('fp_daraja_mock')->insert(['path' => $path, 'status' => $status, 'body' => json_encode($body)]);
}

function lastDarajaCall(string $path): ?array
{
    $row = DB::table('fp_daraja_calls')->where('path', $path)->orderBy('id', 'desc')->first();
    return $row ? json_decode($row->body, true) : null;
}

function cbUrl(string $route, bool $withKey = true): string
{
    return '/modules/gateways/callback/flexpay.php?route=' . $route . ($withKey ? '&k=' . FlexPaySecurity::callbackKey($route) : '');
}

function invoiceStatus(int $id): string
{
    return (string) DB::table('tblinvoices')->where('id', $id)->value('status');
}

function ledger(int $invoiceId): array
{
    return FlexPayStore::rows(DB::table('tblaccounts')->where('invoiceid', $invoiceId)->get());
}

function stkCallback(string $checkoutId, int $code, ?string $receipt = null, float $amount = 1.0, string $phone = '254712345678'): string
{
    $cb = ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => $checkoutId, 'ResultCode' => $code, 'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user'];
    if ($code === 0) {
        $cb['CallbackMetadata'] = ['Item' => [
            ['Name' => 'Amount', 'Value' => $amount],
            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
            ['Name' => 'TransactionDate', 'Value' => 20260925101010],
            ['Name' => 'PhoneNumber', 'Value' => (int) $phone],
        ]];
    }
    return json_encode(['Body' => ['stkCallback' => $cb]]);
}

function c2bPayload(string $transId, float $amount, string $ref, string $msisdn = '254700000001', string $shortcode = '174379'): string
{
    return json_encode([
        'TransactionType' => 'Pay Bill', 'TransID' => $transId, 'TransTime' => '20260925101010',
        'TransAmount' => number_format($amount, 2, '.', ''), 'BusinessShortCode' => $shortcode, 'BillRefNumber' => $ref,
        'MSISDN' => $msisdn, 'FirstName' => 'JOHN', 'MiddleName' => '', 'LastName' => 'DOE',
    ]);
}

function resetRateLimits(): void
{
    DB::table('flexpay_settings')->whereRaw("SUBSTR(setting_key, 1, 3) = 'rl_'")->delete();
}

function startCheckout(int $invoiceId, string $checkoutId, string $phone = '0712345678', bool $resetLimits = true): array
{
    if ($resetLimits) {
        resetRateLimits();
    }
    mockDaraja('/mpesa/stkpush/v1/processrequest', ['MerchantRequestID' => 'm-' . $checkoutId, 'CheckoutRequestID' => $checkoutId, 'ResponseCode' => '0', 'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success. Request accepted for processing']);
    return http('POST', '/modules/gateways/flexpay/checkout.php', [
        'invoice_id' => $invoiceId, 'token' => FlexPaySecurity::invoiceToken($invoiceId), 'phone' => $phone,
        // Attacker-style extra fields that v3.4 trusted — must be ignored now:
        'amount' => 1, 'passkey' => 'x', 'shortcode' => '999999', 'callback_url' => 'https://evil.example/cb',
    ]);
}

function ageRow(string $checkoutId, int $seconds): void
{
    DB::table('flexpay_transactions')->where('checkout_request_id', $checkoutId)->update(['created_at' => date('Y-m-d H:i:s', time() - $seconds), 'last_checked_at' => null]);
}

// ═════════════════════════════════════════════════════════════════════════════
section('Pure helpers');
// ═════════════════════════════════════════════════════════════════════════════

$refs = ['INV-42' => 42, 'inv42' => 42, 'Inv 0042' => 42, '42' => 42, '0000042' => 42, 'INV#7' => 7, ' inv - 42 ' => 42,
    'INV-42-A' => null, 'INV 23 and 24' => null, 'PAY INV42' => null, 'ACC 2 PLAN 7' => null, '0712345678' => null, 'garbage' => null, '' => null, 'INV' => null, 'INV-0' => null];
$ok = true;
foreach ($refs as $in => $want) {
    if (FlexPayStore::parseInvoiceRef((string) $in, 'INV') !== $want) {
        $ok = false;
        echo "      parseInvoiceRef('{$in}') = " . var_export(FlexPayStore::parseInvoiceRef((string) $in, 'INV'), true) . "\n";
    }
}
check('parseInvoiceRef: formatting variants accepted, anything extra rejected', $ok);
check('bare invoice number can be disabled', FlexPayStore::parseInvoiceRef('42', 'INV', false) === null && FlexPayStore::parseInvoiceRef('INV-42', 'INV', false) === 42);

check('formatPhone normalises Kenyan numbers', DarajaClient::formatPhone('0712 345 678') === '254712345678'
    && DarajaClient::formatPhone('+254 110 000 111') === '254110000111' && DarajaClient::formatPhone('712345678') === '254712345678'
    && DarajaClient::formatPhone('0612345678') === '' && DarajaClient::formatPhone('12345') === '');

check('phoneMatches: full, masked and SHA-256 hashed MSISDNs', FlexPayStore::phoneMatches('0712345678', '254712345678')
    && FlexPayStore::phoneMatches('0712345678', '2547*****678')
    && FlexPayStore::phoneMatches('0712345678', hash('sha256', '254712345678'))
    && FlexPayStore::phoneMatches('0712345678', '254712345678 - JOHN DOE')
    && !FlexPayStore::phoneMatches('0712345678', '254712345679')
    && !FlexPayStore::phoneMatches('0712345678', '2547********'));

$t = FlexPaySecurity::invoiceToken(5);
check('invoice token: valid for its invoice only', FlexPaySecurity::verifyInvoiceToken(5, $t) && !FlexPaySecurity::verifyInvoiceToken(6, $t));
check('invoice token: tampered / expired tokens rejected', !FlexPaySecurity::verifyInvoiceToken(5, substr($t, 0, -1) . (substr($t, -1) === 'a' ? 'b' : 'a'))
    && !FlexPaySecurity::verifyInvoiceToken(5, FlexPaySecurity::invoiceToken(5, time() - 3 * 86400))
    && !FlexPaySecurity::verifyInvoiceToken(5, '') && !FlexPaySecurity::verifyInvoiceToken(5, 'hmac-of-passkey'));

check('ipInList: exact, IPv4 CIDR, IPv6 CIDR', FlexPaySecurity::ipInList('196.201.214.200', ['196.201.214.200'])
    && FlexPaySecurity::ipInList('10.1.2.3', ['10.0.0.0/8']) && !FlexPaySecurity::ipInList('11.1.2.3', ['10.0.0.0/8'])
    && FlexPaySecurity::ipInList('2001:db8::5', ['2001:db8::/32']) && !FlexPaySecurity::ipInList('garbage', ['0.0.0.0/0']));

$spoof = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '196.201.214.200'];
check('clientIp ignores X-Forwarded-For from untrusted peers', FlexPaySecurity::clientIp([], $spoof) === '203.0.113.9');
check('clientIp honours X-Forwarded-For from a trusted proxy', FlexPaySecurity::clientIp(['trustedProxies' => '203.0.113.0/24'], $spoof) === '196.201.214.200');

$v = FlexPaySecurity::verifyCallback('c2b_receipt', ['callbackSecurity' => 'token_only'], 'wrong', ['REMOTE_ADDR' => '196.201.214.200']);
check('token_only mode rejects a Safaricom IP without the key', !$v['ok']);
$v = FlexPaySecurity::verifyCallback('c2b_receipt', ['callbackSecurity' => 'token_only'], FlexPaySecurity::callbackKey('c2b_receipt'), ['REMOTE_ADDR' => '1.2.3.4']);
check('token_only mode accepts the right key from any IP', $v['ok'] && $v['via'] === 'token');
$v = FlexPaySecurity::verifyCallback('stk_result', [], FlexPaySecurity::callbackKey('c2b_receipt'), ['REMOTE_ADDR' => '1.2.3.4']);
check('a key for one route does not work on another', !$v['ok']);

check('callback keys are hex (safe for Daraja C2B URL rules)', (bool) preg_match('/^[a-f0-9]{32}$/', FlexPaySecurity::callbackKey('c2b_receipt'))
    && stripos(FlexPaySecurity::callbackUrl('https://x.test', 'c2b_receipt'), 'mpesa') === false);

$redacted = FlexPayStore::redact(['a' => 1, 'SecurityCredential' => 'x', 'nested' => ['Password' => 'y', 'passkey' => 'z']]);
check('API-log redaction strips credentials', $redacted['SecurityCredential'] === '[redacted]' && $redacted['nested']['Password'] === '[redacted]' && $redacted['nested']['passkey'] === '[redacted]' && $redacted['a'] === 1);

$key  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr  = openssl_csr_new(['commonName' => 'daraja-test'], $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($cert, $pem);
$cred = DarajaClient::securityCredentialFromPassword('Safaricom999!*!', $pem);
openssl_private_decrypt(base64_decode((string) $cred), $plain, $key, OPENSSL_PKCS1_PADDING);
check('security credential generated from initiator password + certificate', $plain === 'Safaricom999!*!');
check('bad certificate yields no credential', DarajaClient::securityCredentialFromPassword('pw', 'not a cert') === null);

check('KES amount for a USD invoice converts at WHMCS rates (ceil)', FlexPayStore::invoiceBalanceInKes(10) === 1299);
check('invoice balance computed from tblaccounts (no "balance" column in WHMCS)', FlexPayStore::getInvoiceBalance(5) === 5000.0);

$tokenStored = (string) FlexPayStore::getSetting('oauth_' . substr(hash('sha256', 'ck|sbx'), 0, 32), '');
DarajaClient::fromGatewayParams(getGatewayVariables('flexpay'))->getAccessToken(true);
$tokenStored = (string) FlexPayStore::getSetting('oauth_' . substr(hash('sha256', 'ck|sbx'), 0, 32), '');
check('OAuth token cached encrypted in the DB, not in /tmp', strncmp($tokenStored, 'enc:', 4) === 0 && strpos($tokenStored, 'test-token') === false
    && empty(glob(sys_get_temp_dir() . '/flexpay_tok_*')));

// ═════════════════════════════════════════════════════════════════════════════
section('Invoice widget');
// ═════════════════════════════════════════════════════════════════════════════
$params = getGatewayVariables('flexpay') + ['invoiceid' => 1, 'amount' => '1500.00', 'currency' => 'KES', 'returnurl' => 'https://billing.example.com/viewinvoice.php?id=1', 'clientdetails' => ['phonenumber' => '0712345678']];
mockDaraja('/mpesa/c2b/v2/registerurl', ['OriginatorCoversationID' => 'x', 'ResponseCode' => '0', 'ResponseDescription' => 'success']);
$html = flexpay_link($params);
check('widget never contains the passkey', strpos($html, 'SECRET-PASSKEY-VALUE') === false && stripos($html, 'passkey') === false);
check('widget carries a signed invoice token', (bool) preg_match('/"token":"\d+\.[a-f0-9]{48}"/', $html));
check('widget inserts server messages as text, not HTML', strpos($html, 'innerHTML') === false);
$reg = lastDarajaCall('/mpesa/c2b/v2/registerurl');
check('C2B URLs auto-registered with secret keys', $reg && strpos($reg['ConfirmationURL'], '&k=' . FlexPaySecurity::callbackKey('c2b_receipt')) !== false);
$calls = DB::table('fp_daraja_calls')->where('path', '/mpesa/c2b/v2/registerurl')->count();
flexpay_link($params);
check('C2B registration is not repeated on every invoice view', DB::table('fp_daraja_calls')->where('path', '/mpesa/c2b/v2/registerurl')->count() === $calls);
check('USD invoice widget shows the KES amount', strpos(flexpay_link(['invoiceid' => 10] + $params), 'KES 1,299') !== false);

// ═════════════════════════════════════════════════════════════════════════════
section('Checkout endpoint');
// ═════════════════════════════════════════════════════════════════════════════
[$code] = http('POST', '/modules/gateways/flexpay/checkout.php', ['invoice_id' => 1, 'amount' => 1, 'phone' => '0712345678', 'passkey' => 'SECRET-PASSKEY-VALUE', 'csrf' => hash_hmac('sha256', '1|1', 'SECRET-PASSKEY-VALUE')]);
check('v3.4-style forged request (passkey HMAC) is rejected', $code === 403);

[$code, $res] = startCheckout(1, 'ws_CO_1');
$sent = lastDarajaCall('/mpesa/stkpush/v1/processrequest');
check('checkout succeeds with a valid token', $code === 200 && ($res['success'] ?? false) && $res['checkout_request_id'] === 'ws_CO_1', json_encode($res));
check('amount, shortcode and callback are server-derived (attacker fields ignored)', $sent['Amount'] === 1500 && $sent['BusinessShortCode'] === '174379'
    && strpos($sent['CallBackURL'], 'https://billing.example.com/modules/gateways/callback/flexpay.php?route=stk_result&k=') === 0 && $sent['AccountReference'] === 'INV-1');
check('pending STK row stored with invoice + KES amount', ($r = FlexPayStore::findTransactionByCheckoutId('ws_CO_1')) && (int) $r->invoice_id === 1 && (float) $r->amount === 1500.0 && $r->status === 'pending');

[$code, $res] = startCheckout(2, 'ws_CO_2');
check('a second pending STK row can exist (v3.4 unique-index bug fixed)', ($res['success'] ?? false) && FlexPayStore::findTransactionByCheckoutId('ws_CO_2') !== null);

[$code, $res] = startCheckout(12, 'ws_CO_X');
check('checkout refuses an already-paid invoice', !($res['success'] ?? true));

for ($i = 0; $i < 4; $i++) {
    [$code] = startCheckout(3, 'ws_CO_RL' . $i, '0799000111', $i === 0);
}
check('per-phone STK rate limit blocks prompt spam', $code === 429);

// ═════════════════════════════════════════════════════════════════════════════
section('STK callbacks');
// ═════════════════════════════════════════════════════════════════════════════
[$code] = http('POST', cbUrl('stk_result', false), [], stkCallback('ws_CO_2', 0, 'FAKE000001', 900));
check('forged STK callback without key from a non-Safaricom IP → 403', $code === 403);
check('…and the invoice stays unpaid', invoiceStatus(2) === 'Unpaid' && count(ledger(2)) === 0);

mockDaraja('/mpesa/stkpushquery/v1/query', ['ResponseCode' => '0', 'ResultCode' => '1032', 'ResultDesc' => 'Request cancelled by user']);
[$code] = http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_2', 0, 'FAKE000002', 900));
check('keyed "success" callback that Daraja contradicts is NOT applied', invoiceStatus(2) === 'Unpaid' && count(ledger(2)) === 0
    && FlexPayStore::findTransactionByCheckoutId('ws_CO_2')->status === 'failed');

mockDaraja('/mpesa/stkpushquery/v1/query', ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']);
[$code, $res] = http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_1', 0, 'SKA1B2C3D4', 1.0));
$l = ledger(1);
check('confirmed STK callback marks the invoice paid', $code === 200 && invoiceStatus(1) === 'Paid');
check('amount applied is the amount requested (1500), not the callback\'s (1)', count($l) === 1 && (float) $l[0]->amountin === 1500.0 && $l[0]->transid === 'SKA1B2C3D4');

http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_1', 0, 'SKA1B2C3D4', 1500));
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('SKA1B2C3D4', 1500, 'INV-1', '254712345678'));
check('retried callback + C2B echo of the same payment do not double-credit', count(ledger(1)) === 1);

http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_1', 1032));
check('a late failure callback never downgrades a settled payment', FlexPayStore::findTransactionByCheckoutId('ws_CO_1')->status === 'success');

// ═════════════════════════════════════════════════════════════════════════════
section('C2B');
// ═════════════════════════════════════════════════════════════════════════════
[$code] = http('POST', cbUrl('c2b_receipt', false), [], c2bPayload('FORGED0001', 2000, 'INV-3'));
check('forged C2B confirmation (no key, not Safaricom IP) → 403, invoice unpaid', $code === 403 && invoiceStatus(3) === 'Unpaid');

[$code] = http('POST', cbUrl('c2b_receipt', false), [], c2bPayload('FORGED0002', 2000, 'INV-3'), ['X-Forwarded-For: 196.201.214.200']);
check('spoofed X-Forwarded-For does not pass the IP check', $code === 403 && invoiceStatus(3) === 'Unpaid');

[$code, $res] = http('POST', cbUrl('c2b_check'), [], c2bPayload('VAL0000001', 100, 'INV-999999'));
check('strict validation rejects an unknown account number (C2B00012)', ($res['ResultCode'] ?? '') === 'C2B00012');
[$code, $res] = http('POST', cbUrl('c2b_check'), [], c2bPayload('VAL0000002', 100, 'inv 3'));
check('strict validation accepts a typo\'d reference for an open invoice', ($res['ResultCode'] ?? '') === '0');

[$code] = http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RC3A0B1C2D', 2000, 'inv 3'));
check('keyed C2B with a typo\'d reference is applied', $code === 200 && invoiceStatus(3) === 'Paid' && count(ledger(3)) === 1);
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RC3A0B1C2D', 2000, 'inv 3'));
check('retried C2B confirmation is idempotent', count(ledger(3)) === 1);

setGw('trustedProxies', '127.0.0.1');
[$code] = http('POST', cbUrl('c2b_receipt', false), [], c2bPayload('TILL00PHON', 777, '', hash('sha256', '254733999888')), ['X-Forwarded-For: 196.201.214.200']);
check('Safaricom IP via trusted proxy is accepted (legacy un-keyed C2B URL)', $code === 200);
check('Till payment (no reference) is NOT applied even when phone + amount match', invoiceStatus(4) === 'Unpaid');
$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'TILL00PHON')->first();
check('…it is queued with the phone/amount match as a suggestion only', $u && (int) $u->suggested_invoice_id === 4 && stripos($u->notes, 'suggestion') !== false, $u->notes ?? '');
setGw('trustedProxies', '');

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('PART000001', 1000, 'RENT', '254712345678'));
$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'PART000001')->first();
check('unmatched payment queued with a partial-payment hint', $u && strpos($u->notes, '#5') !== false && (int) $u->suggested_invoice_id === 5, $u->notes ?? 'none');

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('WRONGSC001', 800, 'INV-12', '254700000001', '600999'));
check('payment to an unconfigured shortcode is never auto-applied', DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGSC001')->exists());

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('PAIDINV001', 800, 'INV-12'));
$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'PAIDINV001')->first();
check('C2B for an already-paid invoice goes to reconciliation, not silently credited', $u && (int) $u->suggested_invoice_id === 12 && count(ledger(12)) === 0);

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('USD0000001', 1299, 'INV-10', '254722000111'));
$l = ledger(10);
check('KES payment on a USD invoice is converted before crediting', invoiceStatus(10) === 'Paid' && count($l) === 1 && abs((float) $l[0]->amountin - 10.0) < 0.01, json_encode($l));

// ═════════════════════════════════════════════════════════════════════════════
section('Rigid reference matching (no reference match → never auto-paid)');
// ═════════════════════════════════════════════════════════════════════════════
$inv(20, 1, 1000);  // client 1 (0712345678)
$inv(21, 3, 2500);
$inv(22, 3, 4100);
$inv(23, 1, 640);

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00001', 1000, 'HELLO', '254712345678'));
check('same amount + payer\'s own phone but wrong reference → NOT paid', invoiceStatus(20) === 'Unpaid' && count(ledger(20)) === 0);
check('…queued for manual reconciliation', DB::table('flexpay_unmatched_payments')->where('trans_id', 'RIGID00001')->where('matched', 0)->exists());

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00002', 1000, '', hash('sha256', '254712345678')));
check('same amount + hashed payer phone, no reference (Till) → NOT paid', invoiceStatus(20) === 'Unpaid');

setGw('c2bAmountOnlyMatching', 'on');
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00003', 2500, 'RANDOM', '254700000055'));
check('amount-only matching cannot be switched back on (legacy setting ignored)', invoiceStatus(21) === 'Unpaid');
setGw('c2bAmountOnlyMatching', '');

startCheckout(22, 'ws_CO_22', '0733999888');
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00004', 4100, 'SOMETHING', '254733999888'));
check('paybill payment with a different reference is not linked to a pending STK for the same amount', invoiceStatus(22) === 'Unpaid'
    && FlexPayStore::findTransactionByCheckoutId('ws_CO_22')->status === 'pending');

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00005', 999, 'INV-20', '254700000066'));
check('correct reference is applied even from an unknown phone (partial)', count(ledger(20)) === 1 && (float) ledger(20)[0]->amountin === 999.0);

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00006', 640, 'INV 23 and 24', '254700000066'));
check('reference that merely contains the invoice number among other text → NOT paid', invoiceStatus(23) === 'Unpaid');

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00007', 640, 'inv-0023', '254700000066'));
check('formatting variants of the exact reference (case, dash, zeros) still match', invoiceStatus(23) === 'Paid');


DB::table('tblinvoices')->where('id', 23)->update(['status' => 'Unpaid']);
DB::statement("UPDATE tblinvoices SET total = 640 WHERE id = 23");
if (!DB::schema()->hasColumn('tblinvoices', 'invoicenum')) {
    DB::schema()->table('tblinvoices', function ($t) { $t->string('invoicenum')->default(''); });
}
$inv(24, 1, 1500);
$inv(25, 3, 870);
DB::table('tblinvoices')->where('id', 24)->update(['invoicenum' => '2026-0077']);
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00008', 1500, '2026-0077', '254700000066'));
check('WHMCS custom invoice number (invoicenum) is accepted as the reference', invoiceStatus(24) === 'Paid');
DB::table('tblinvoices')->where('id', 25)->update(['invoicenum' => '26']);
$inv(26, 3, 870);
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('RIGID00009', 870, '26', '254700000066'));
check('reference that is invoice ID of one invoice and invoicenum of another → ambiguous, NOT paid', invoiceStatus(25) === 'Unpaid' && invoiceStatus(26) === 'Unpaid'
    && stripos((string) DB::table('flexpay_unmatched_payments')->where('trans_id', 'RIGID00009')->value('notes'), 'more than one') !== false);

$inv(27, 2, 50.00); // USD
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('TINYUSD001', 0.5, 'INV-27', '254700000066'));
check('payment converting to < 0.01 is never passed to addInvoicePayment (0 = full balance in WHMCS)', invoiceStatus(27) === 'Unpaid' && count(ledger(27)) === 0);

// Till echo arrives BEFORE the STK callback: queued, then absorbed when STK confirms (exists once, credited once).
setGw('transactionType', 'CustomerBuyGoodsOnline');
$inv(28, 1, 330);
startCheckout(28, 'ws_CO_28');
DB::table('flexpay_transactions')->where('checkout_request_id', 'ws_CO_28')->update(['phone' => '254799000555']);
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('TILLECHO01', 330, '', '254799000555'));
http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_28', 0, 'TILLECHO01', 330, '254799000555'));
check('Till C2B echo + STK result for the same payment: credited once', invoiceStatus(28) === 'Paid' && count(ledger(28)) === 1);
setGw('transactionType', 'CustomerPayBillOnline');

$inv(29, 1, 410);
startCheckout(29, 'ws_CO_29');
DB::table('tblinvoices')->where('id', 29)->update(['status' => 'Cancelled']);
http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_29', 0, 'STKCANCEL1', 410));
check('STK payment for an invoice cancelled meanwhile goes to reconciliation, not credit', count(ledger(29)) === 0
    && DB::table('flexpay_unmatched_payments')->where('trans_id', 'STKCANCEL1')->where('matched', 0)->exists());

// ═════════════════════════════════════════════════════════════════════════════
section('Invoice poller');
// ═════════════════════════════════════════════════════════════════════════════
[$code] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 1]);
check('poll without a token → 403 (no more invoice/receipt enumeration)', $code === 403);

[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 6, 'token' => FlexPaySecurity::invoiceToken(6), 'checkout_id' => 'ws_CO_1']);
check('poll never reports another invoice\'s checkout', !($res['paid'] ?? true));

startCheckout(6, 'ws_CO_6');
ageRow('ws_CO_6', 20);
mockDaraja('/mpesa/stkpushquery/v1/query', ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 6, 'token' => FlexPaySecurity::invoiceToken(6), 'checkout_id' => 'ws_CO_6', 'since' => time() - 30]);
check('self-heal: pending STK confirmed via live query settles the invoice', ($res['paid'] ?? false) && invoiceStatus(6) === 'Paid', json_encode($res));
check('…recorded with the CheckoutRequestID until the receipt is known', ledger(6)[0]->transid === 'ws_CO_6');
http('POST', cbUrl('stk_result'), [], stkCallback('ws_CO_6', 0, 'SKB6C7D8E9', 1200));
check('late callback swaps in the real receipt without double-crediting', count(ledger(6)) === 1 && ledger(6)[0]->transid === 'SKB6C7D8E9');

startCheckout(7, 'ws_CO_7');
ageRow('ws_CO_7', 20);
mockDaraja('/mpesa/stkpushquery/v1/query', ['ResponseCode' => '0', 'ResultCode' => '1037', 'ResultDesc' => 'DS timeout user cannot be reached']);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 7, 'token' => FlexPaySecurity::invoiceToken(7), 'checkout_id' => 'ws_CO_7']);
check('1037/1032 are final failures (v3.4 polled them forever)', ($res['failed'] ?? false) && FlexPayStore::findTransactionByCheckoutId('ws_CO_7')->status === 'failed');

[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 5, 'token' => FlexPaySecurity::invoiceToken(5), 'since' => time()]);
check('poll does not report old payments as new (no reload loop)', !($res['paid'] ?? true));

// ═════════════════════════════════════════════════════════════════════════════
section('Customer self-verify');
// ═════════════════════════════════════════════════════════════════════════════
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('SELF000450', 450, 'my account', '254700000009'));
[$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 8, 'token' => FlexPaySecurity::invoiceToken(8), 'reference' => 'my account']);
check('guessing an account reference can\'t claim someone\'s payment', !($res['applied'] ?? true) && invoiceStatus(8) === 'Unpaid');
[$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 8, 'token' => FlexPaySecurity::invoiceToken(8), 'reference' => 'self000450']);
check('default (queue) mode: receipt is flagged for staff, invoice NOT auto-paid', ($res['success'] ?? false) && !($res['applied'] ?? true) && invoiceStatus(8) === 'Unpaid'
    && (int) DB::table('flexpay_unmatched_payments')->where('trans_id', 'SELF000450')->value('suggested_invoice_id') === 8, json_encode($res));
setGw('selfVerifyMode', 'apply');
[$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 8, 'token' => FlexPaySecurity::invoiceToken(8), 'reference' => 'self000450']);
check('"apply" mode: the payer\'s receipt number applies their unmatched payment', ($res['applied'] ?? false) && invoiceStatus(8) === 'Paid', json_encode($res));
check('…and clears it from the reconciliation queue', (int) DB::table('flexpay_unmatched_payments')->where('trans_id', 'SELF000450')->value('matched') === 1);
[$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 9, 'token' => FlexPaySecurity::invoiceToken(9), 'reference' => 'RC3A0B1C2D']);
check('a receipt belonging to another invoice is "not found", not disclosed', !($res['success'] ?? true) && strpos($res['message'], '#3') === false);

mockDaraja('/mpesa/transactionstatus/v1/query', ['OriginatorConversationID' => 'oc-9', 'ConversationID' => 'AG_9', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']);
[$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 9, 'token' => FlexPaySecurity::invoiceToken(9), 'reference' => 'LIVE000650']);
check('unknown receipt triggers a Daraja status query', ($res['success'] ?? false) && lastDarajaCall('/mpesa/transactionstatus/v1/query')['TransactionID'] === 'LIVE000650');
$statusResult = json_encode(['Result' => [
    'ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
    'OriginatorConversationID' => 'oc-9', 'ConversationID' => 'AG_9', 'TransactionID' => 'SQ1', 'ResultParameters' => ['ResultParameter' => [
        ['Key' => 'ReceiptNo', 'Value' => 'LIVE000650'], ['Key' => 'TransactionStatus', 'Value' => 'Completed'], ['Key' => 'Amount', 'Value' => 650],
        ['Key' => 'DebitPartyName', 'Value' => '254712345678 - AMINA'], ['Key' => 'CreditPartyName', 'Value' => '174379 - Test Co'],
    ]],
]]);
http('POST', cbUrl('status_result'), [], $statusResult);
check('status result for the payer\'s own phone is applied to the invoice', invoiceStatus(9) === 'Paid', json_encode(ledger(9)));

resetRateLimits();
$blocked = false;
for ($i = 0; $i < 12; $i++) {
    [$code, $res] = http('POST', '/modules/gateways/flexpay/verify.php', ['invoice_id' => 5, 'token' => FlexPaySecurity::invoiceToken(5), 'reference' => 'ZZZZZZZZ' . sprintf('%02d', $i)]);
    $blocked = $blocked || stripos((string) ($res['message'] ?? ''), 'too many') !== false;
}
check('self-verify is rate-limited per invoice', $blocked);

// ═════════════════════════════════════════════════════════════════════════════
section('Refunds (B2C)');
// ═════════════════════════════════════════════════════════════════════════════
DB::table('tblclients')->where('id', 1)->update(['phonenumber' => '0799111222']); // profile changed since payment
mockDaraja('/mpesa/b2c/v3/paymentrequest', ['ConversationID' => 'AG_R1', 'OriginatorConversationID' => 'will-be-echoed', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']);
$r = flexpay_refund(getGatewayVariables('flexpay') + ['invoiceid' => 1, 'amount' => '500.00', 'transid' => 'SKA1B2C3D4', 'clientdetails' => ['phonenumber' => '0799111222']]);
$b2c = lastDarajaCall('/mpesa/b2c/v3/paymentrequest');
check('refund accepted and sent to the phone that PAID, not the profile phone', $r['status'] === 'success' && $b2c['PartyB'] === '254712345678' && $b2c['Amount'] === 500);
check('B2C v3 request carries an OriginatorConversationID', !empty($b2c['OriginatorConversationID']));

$refund = DB::table('flexpay_refunds')->orderBy('id', 'desc')->first();
http('POST', cbUrl('disbursement_result'), [], json_encode(['Result' => ['ResultType' => 0, 'ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.', 'OriginatorConversationID' => $refund->originator_conversation_id, 'ConversationID' => 'AG_R1', 'TransactionID' => '0000000000000']]));
$refund = DB::table('flexpay_refunds')->where('id', $refund->id)->first();
check('failed B2C result marks the refund failed and alerts the admin log', $refund->status === 'failed' && DB::table('fp_test_log')->where('kind', 'activity')->where('message', 'like', '%refund%FAILED%')->exists());

$_SESSION['adminid'] = 1;
$_SESSION['adminusername'] = 'boss';
$csrf = FlexPaySecurity::csrfToken();

mockDaraja('/mpesa/b2c/v3/paymentrequest', ['ConversationID' => 'AG_R2', 'OriginatorConversationID' => 'x', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'retry_refund', 'refund_id' => $refund->id], 'boss');
check('dashboard actions without a CSRF token are refused', !$res['success'] && stripos($res['message'], 'token') !== false);
$res = FlexPayDashboardActions::handle(['fp_action' => 'retry_refund', 'refund_id' => $refund->id, 'fp_csrf' => $csrf], 'boss');
check('failed refund can be retried', $res['success'] && DB::table('flexpay_refunds')->count() === 2, $res['message']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'retry_refund', 'refund_id' => $refund->id, 'fp_csrf' => $csrf], 'boss');
check('…but only once (no double payout on double-click)', !$res['success']);

// ═════════════════════════════════════════════════════════════════════════════
section('Reversal, balance, reconciliation, cron');
// ═════════════════════════════════════════════════════════════════════════════
mockDaraja('/mpesa/reversal/v1/request', ['OriginatorConversationID' => 'oc-rev', 'ConversationID' => 'AG_REV', 'ResponseCode' => '0', 'ResponseDescription' => 'Accept the service request successfully.']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'trigger_reversal', 'transaction_id' => 'RC3A0B1C2D', 'amount' => 999999, 'fp_csrf' => $csrf], 'boss');
check('reversal larger than the original payment is refused', !$res['success']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'trigger_reversal', 'transaction_id' => 'RC3A0B1C2D', 'amount' => 2000, 'fp_csrf' => $csrf], 'boss');
http('POST', cbUrl('reversal_result'), [], json_encode(['Result' => ['ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'Reversal accepted', 'OriginatorConversationID' => 'oc-rev', 'ConversationID' => 'AG_REV', 'TransactionID' => 'REVNEW0001']]));
check('reversal result marks the ORIGINAL payment reversed (v3.4 marked the wrong ID)', FlexPayStore::findTransactionByReceipt('RC3A0B1C2D')->status === 'reversed'
    && FlexPayStore::findTransactionByReceipt('REVNEW0001')->channel === 'reversal');

http('POST', cbUrl('balance_result'), [], json_encode(['Result' => ['ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'ok', 'ConversationID' => 'AG_B', 'ResultParameters' => ['ResultParameter' => [
    ['Key' => 'AccountBalance', 'Value' => 'Working Account|KES|700000.00|700000.00|0.00|0.00&Float Account|KES|0.00|0.00|0.00|0.00&Utility Account|KES|228037.00|228037.00|0.00|0.00&Charges Paid Account|KES|-1540.00|-1540.00|0.00|0.00'],
]]]]));
$bal = FlexPayStore::getLatestBalance('174379');
check('balance result parsed into a snapshot', $bal && (float) $bal->working_account === 700000.0 && (float) $bal->utility_account === 228037.0 && (float) $bal->charges_account === -1540.0);

$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'PART000001')->first();
$res = FlexPayDashboardActions::handle(['fp_action' => 'reconcile', 'unmatched_id' => $u->id, 'invoice_id' => 5, 'fp_csrf' => $csrf], 'boss');
check('admin reconciliation applies the payment (partial) without die()', $res['success'] && stripos($res['message'], 'still due') !== false, $res['message']);
check('…and links the ledger row to the invoice', (int) FlexPayStore::findTransactionByReceipt('PART000001')->invoice_id === 5);
$res = FlexPayDashboardActions::handle(['fp_action' => 'reconcile', 'unmatched_id' => $u->id, 'invoice_id' => 5, 'fp_csrf' => $csrf], 'boss');
check('reconciling twice is refused', !$res['success']);
$w = DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGSC001')->first();
$res = FlexPayDashboardActions::handle(['fp_action' => 'dismiss_unmatched', 'unmatched_id' => $w->id, 'dismiss_reason' => 'not ours', 'fp_csrf' => $csrf], 'boss');
check('unmatched payment can be dismissed', $res['success'] && (int) DB::table('flexpay_unmatched_payments')->where('id', $w->id)->value('matched') === 1);

startCheckout(11, 'ws_CO_11');
ageRow('ws_CO_11', 300);
mockDaraja('/mpesa/stkpushquery/v1/query', ['ResponseCode' => '0', 'ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully.']);
DB::table('tbladdonmodules')->insert(['module' => 'flexpay_dashboard', 'setting' => 'pending_sweeper', 'value' => 'on']);
foreach ($GLOBALS['fp_test_hooks']['AfterCronJob'] ?? [] as $hook) {
    $hook([]);
}
check('cron sweeper settles an abandoned STK payment', invoiceStatus(11) === 'Paid');
foreach ($GLOBALS['fp_test_hooks']['DailyCronJob'] ?? [] as $hook) {
    $hook([]);
}
check('daily housekeeping runs', DB::table('flexpay_api_log')->where('operation', 'housekeeping')->exists());

$res = FlexPayDashboardActions::handle(['fp_action' => 'test_connection', 'fp_csrf' => $csrf], 'boss');
check('Test Configuration action reports OAuth + HTTPS', strpos($res['message'], 'OAuth: OK') !== false && strpos($res['message'], 'HTTPS OK') !== false, $res['message']);

// ═════════════════════════════════════════════════════════════════════════════
section('WHMCS-native integrations');
// ═════════════════════════════════════════════════════════════════════════════
$validate = function (array $overrides) {
    try {
        flexpay_config_validate(array_merge(getGatewayVariables('flexpay'), $overrides));
        return 'ok';
    } catch (\WHMCS\Exception\Module\InvalidConfiguration $e) {
        return $e->getMessage();
    }
};
check('_config_validate accepts the current settings', $validate([]) === 'ok');
check('_config_validate rejects a bad shortcode / prefix / proxy CIDR', strpos($validate(['businessShortcode' => '12ab']), 'Shortcode') !== false
    && strpos($validate(['accountRefPrefix' => 'INVOICE-LONG']), 'Prefix') !== false
    && strpos($validate(['trustedProxies' => '10.0.0.0/99']), 'Trusted Proxies') !== false);
check('_config_validate refuses Callback Security "off" in live mode', strpos($validate(['callbackSecurity' => 'off', 'testMode' => '']), 'Callback Security') !== false
    && $validate(['callbackSecurity' => 'off', 'testMode' => 'on']) === 'ok');

$bal = flexpay_account_balance(getGatewayVariables('flexpay'));
check('_account_balance returns WHMCS Balance objects from the latest snapshot', $bal instanceof \WHMCS\Module\Gateway\BalanceCollection
    && (float) $bal->items[0]->amount === 700000.0 && $bal->items[0]->currency === 'KES');

$info = flexpay_TransactionInformation(['transactionId' => 'SKA1B2C3D4']);
check('_TransactionInformation describes a FlexPay receipt', ($info->data['amount'] ?? null) === 1500.0 && ($info->data['currency'] ?? '') === 'KES'
    && ($info->data['type'] ?? '') === 'M-Pesa STK Push');

check('successful M-Pesa reversal is recorded natively via WHMCS paymentReversed()', DB::table('fp_test_log')->where('kind', 'reversed')->where('message', 'REVNEW0001 RC3A0B1C2D')->exists()
    && invoiceStatus(3) === 'Collections');

$inv(30, 1, 2750);
$fields = FlexPayWhmcsIntegration::emailMergeFields(['messagename' => 'Invoice Created', 'relid' => 30, 'mergefields' => ['invoice_id' => 30]]);
check('EmailPreSend adds paybill / account / amount / instructions merge fields', ($fields['flexpay_account'] ?? '') === 'INV-30' && ($fields['flexpay_paybill'] ?? '') === '174379'
    && ($fields['flexpay_amount_kes'] ?? '') === 'KES 2,750' && strpos($fields['flexpay_instructions'] ?? '', 'Account No. INV-30') !== false);
check('EmailPreSend adds nothing to non-invoice emails', FlexPayWhmcsIntegration::emailMergeFields(['messagename' => 'Password Reset', 'relid' => 1, 'mergefields' => []]) === []);
check('EmailTplMergeFields lists the fields for invoice templates only', isset(FlexPayWhmcsIntegration::emailTemplateFields(['type' => 'invoice'])['flexpay_instructions'])
    && FlexPayWhmcsIntegration::emailTemplateFields(['type' => 'general']) === []);

$panel = FlexPayWhmcsIntegration::adminInvoicePanel(['invoiceid' => 20, 'paymentmethod' => 'flexpay']);
check('admin invoice panel lists payments, suggestions and the STK button', strpos($panel, 'RIGID00005') !== false && strpos($panel, 'RIGID00001') !== false
    && strpos($panel, 'value="admin_stk"') !== false && strpos($panel, 'fp_csrf') !== false);
check('admin invoice panel is hidden for unrelated invoices', FlexPayWhmcsIntegration::adminInvoicePanel(['invoiceid' => 30, 'paymentmethod' => 'banktransfer']) === '');

resetRateLimits();
mockDaraja('/mpesa/stkpush/v1/processrequest', ['MerchantRequestID' => 'm-adm', 'CheckoutRequestID' => 'ws_CO_ADMIN', 'ResponseCode' => '0', 'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'admin_stk', 'invoice_id' => 30, 'phone' => '0712345678', 'fp_csrf' => $csrf], 'boss');
$row = FlexPayStore::findTransactionByCheckoutId('ws_CO_ADMIN');
check('admin can send an STK prompt for an invoice', $res['success'] && $row && (int) $row->invoice_id === 30 && (float) $row->amount === 2750.0, $res['message']);

$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'RIGID00002')->first();
$res = FlexPayDashboardActions::handle(['fp_action' => 'credit_client', 'unmatched_id' => $u->id, 'client_id' => 1, 'fp_csrf' => $csrf], 'boss');
check('unmatched payment credited to client account via WHMCS AddTransaction', $res['success'] && DB::table('tblaccounts')->where('transid', 'RIGID00002')->exists()
    && DB::table('fp_test_log')->where('kind', 'localapi')->where('message', 'like', 'AddTransaction%"credit":true%')->exists(), $res['message']);
$res = FlexPayDashboardActions::handle(['fp_action' => 'credit_client', 'unmatched_id' => $u->id, 'client_id' => 1, 'fp_csrf' => $csrf], 'boss');
check('…and cannot be credited twice', !$res['success']);

$ml = FlexPayStore::rows(DB::table('fp_test_log')->where('kind', 'modulelog')->get());
$mlLeak = false;
foreach ($ml as $l) {
    if (strpos($l->message, 'SECRET-PASSKEY-VALUE') !== false || strpos($l->message, 'ENCRYPTED-CRED') !== false || preg_match('/"Password":"(?!\[redacted\])/', $l->message)) {
        $mlLeak = true;
    }
}
check('Daraja calls reach the WHMCS Module Log with credentials redacted', count($ml) > 0 && !$mlLeak);

// ═════════════════════════════════════════════════════════════════════════════
section('Rigid manual paths, customer feedback, alerts, self-healing');
// ═════════════════════════════════════════════════════════════════════════════
$u = DB::table('flexpay_unmatched_payments')->where('trans_id', 'RIGID00003')->first();
$res = FlexPayDashboardActions::handle(['fp_action' => 'reconcile', 'unmatched_id' => $u->id, 'invoice_id' => 12, 'fp_csrf' => $csrf], 'boss');
check('admin cannot apply money to an already-PAID invoice (use Credit instead)', !$res['success'] && stripos($res['message'], 'Credit to client') !== false && count(ledger(12)) === 0, $res['message']);
check('…and the payment stays in the queue', (int) DB::table('flexpay_unmatched_payments')->where('id', $u->id)->value('matched') === 0);

$res = FlexPayDashboardActions::handle(['fp_action' => 'reconcile', 'unmatched_id' => $u->id, 'invoice_id' => 21, 'fp_csrf' => $csrf], 'boss');
check('manual reconciliation is written to the WHMCS Activity Log, linked to the client', $res['success']
    && DB::table('fp_test_log')->where('kind', 'activity')->where('message', 'like', '%RIGID00003%applied to Invoice #21 by boss [client 3]%')->exists());

// Customer paid with the wrong reference from their own phone while viewing the invoice.
$inv(31, 3, 1800);
$since = time() - 5;
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('WRONGREF31', 1800, 'CHEBET', '254733999888'));
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 31, 'token' => FlexPaySecurity::invoiceToken(31), 'since' => $since]);
check('poll tells the customer a wrong-reference payment arrived (stage "review"), not paid', ($res['stage'] ?? '') === 'review' && !($res['paid'] ?? true)
    && invoiceStatus(31) === 'Unpaid', json_encode($res));
check('…without disclosing receipt, amount or reference', $res['receipt'] === null && $res['amount'] === null
    && strpos($res['message'], 'CHEBET') === false && strpos($res['message'], '1800') === false && strpos($res['message'], 'WRONGREF31') === false);
check('…and pins that invoice as the suggestion for staff', (int) DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGREF31')->value('suggested_invoice_id') === 31);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 30, 'token' => FlexPaySecurity::invoiceToken(30), 'since' => $since]);
check('another customer\'s invoice page is not told about it', ($res['stage'] ?? '') !== 'review');

// Attack: attacker sets their profile phone to the victim's and asks for since=1.
$inv(33, 2, 999);
DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGREF31')->update(['suggested_invoice_id' => null]);
DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGREF31')->update(['created_at' => date('Y-m-d H:i:s', time() - 7200)]);
DB::table('tblclients')->where('id', 2)->update(['phonenumber' => '0733999888']);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 33, 'token' => FlexPaySecurity::invoiceToken(33), 'since' => 1]);
check('look-back is capped at 30 minutes whatever "since" says', ($res['stage'] ?? '') !== 'review'
    && DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGREF31')->value('suggested_invoice_id') === null, json_encode($res));
DB::table('tblclients')->where('id', 2)->update(['phonenumber' => '+254 722 000 111']);
DB::table('flexpay_unmatched_payments')->where('trans_id', 'WRONGREF31')->update(['suggested_invoice_id' => 31, 'created_at' => date('Y-m-d H:i:s')]);

http('POST', cbUrl('c2b_receipt'), [], c2bPayload('MASKED0001', 50, 'ZZZ', '2547*****888'));
$inv(34, 3, 60);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 34, 'token' => FlexPaySecurity::invoiceToken(34), 'since' => time() - 5]);
check('a masked MSISDN never counts as "your number"', (int) DB::table('flexpay_unmatched_payments')->where('trans_id', 'MASKED0001')->value('suggested_invoice_id') !== 34);

DB::table('tblinvoices')->where('id', 31)->update(['status' => 'Paid']);
[$code, $res] = http('GET', '/modules/gateways/flexpay/poll.php', ['invoice_id' => 31, 'token' => FlexPaySecurity::invoiceToken(31), 'since' => $since]);
check('once staff settle the invoice, the page shows paid (status wins over "review")', ($res['paid'] ?? false) === true, json_encode($res));
DB::table('tblinvoices')->where('id', 31)->update(['status' => 'Unpaid']);

// Admin digest (first run only records a baseline).
DB::table('fp_test_log')->where('kind', 'localapi')->where('message', 'like', 'SendAdminEmail%')->delete();
FlexPayStore::deleteSetting('notify_last_unmatched_id');
FlexPayStore::deleteSetting('notify_last_refund_scan');
$d = FlexPayService::sendAdminDigest('https://billing.example.com');
check('admin digest: first run sets a baseline without emailing the backlog', !$d['sent'] && !DB::table('fp_test_log')->where('message', 'like', 'SendAdminEmail%')->exists());
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('DIGEST0001', 555, 'WHO KNOWS', '254700000077'));
$d = FlexPayService::sendAdminDigest('https://billing.example.com');
$mail = (string) DB::table('fp_test_log')->where('message', 'like', 'SendAdminEmail%')->value('message');
check('admin digest emails new unmatched payments via WHMCS SendAdminEmail', $d['sent'] && $d['unmatched'] === 1 && strpos($mail, 'DIGEST0001') !== false && strpos($mail, '"type":"system"') !== false, $mail);
check('…and never repeats them', !FlexPayService::sendAdminDigest('https://billing.example.com')['sent']);
sleep(1);
DB::table('flexpay_refunds')->insert(['invoice_id' => 1, 'phone' => '254712345678', 'amount' => 77, 'status' => 'failed', 'result_desc' => 'Rejected at once', 'created_at' => date('Y-m-d H:i:s')]);
sleep(1);
$d = FlexPayService::sendAdminDigest('https://billing.example.com');
check('admin digest includes a refund rejected immediately (no updated_at)', $d['sent'] && $d['refunds'] === 1, json_encode($d));

// Interrupted callback: receipt recorded, never queued.
DB::table('flexpay_transactions')->insert(['channel' => 'c2b', 'direction' => 'in', 'mpesa_receipt' => 'CRASHED001', 'phone' => '254700000088', 'amount' => 999, 'account_reference' => 'XX',
    'status' => 'success', 'result_desc' => 'Received — matching…', 'created_at' => date('Y-m-d H:i:s', time() - 900), 'updated_at' => date('Y-m-d H:i:s', time() - 900)]);
$inv(32, 1, 400);
DB::table('tblaccounts')->insert(['invoiceid' => 32, 'gateway' => 'flexpay', 'transid' => 'CRASHED002', 'amountin' => 400]);
DB::table('flexpay_transactions')->insert(['channel' => 'c2b', 'direction' => 'in', 'mpesa_receipt' => 'CRASHED002', 'phone' => '254700000088', 'amount' => 400, 'account_reference' => 'INV-32',
    'status' => 'success', 'result_desc' => 'Received — matching…', 'created_at' => date('Y-m-d H:i:s', time() - 900), 'updated_at' => date('Y-m-d H:i:s', time() - 900)]);
http('POST', cbUrl('c2b_receipt'), [], c2bPayload('CRASHED001', 999, 'XX'));
check('a Safaricom retry of an interrupted callback does not double-process', DB::table('flexpay_transactions')->where('mpesa_receipt', 'CRASHED001')->count() === 1);
check('…and heals it immediately: the payment is queued for reconciliation', DB::table('flexpay_unmatched_payments')->where('trans_id', 'CRASHED001')->where('matched', 0)->exists());
$r = FlexPayStore::repairUnqueuedPayments();
check('ledger check links a payment WHMCS already credited instead of queueing it', $r['linked'] === 1 && (int) FlexPayStore::findTransactionByReceipt('CRASHED002')->invoice_id === 32
    && !DB::table('flexpay_unmatched_payments')->where('trans_id', 'CRASHED002')->exists());
check('ledger check is idempotent', FlexPayStore::repairUnqueuedPayments() === ['linked' => 0, 'queued' => 0, 'errors' => 0]);

$_GET = ['fp_tab' => 'reconciliation', 'q' => 'crashed001'];
ob_start();
flexpay_dashboard_output($vars ?? ['modulelink' => 'addonmodules.php?module=flexpay_dashboard', 'access_roles' => 'Full Administrator', 'default_lookback_days' => '30']);
$recon = ob_get_clean();
check('Reconciliation search filters the queue and shows totals/age', strpos($recon, 'CRASHED001') !== false && strpos($recon, 'WRONGREF31') === false
    && strpos($recon, 'payment(s) waiting') !== false && strpos($recon, ' ago') !== false);

// ═════════════════════════════════════════════════════════════════════════════
section('Dashboard rendering & access');
// ═════════════════════════════════════════════════════════════════════════════
$vars = ['modulelink' => 'addonmodules.php?module=flexpay_dashboard', 'access_roles' => 'Full Administrator', 'default_lookback_days' => '30'];
$renderOk = true;
foreach (['overview', 'transactions', 'refunds', 'reconciliation', 'balance', 'tools', 'apilog'] as $tab) {
    $_GET = ['fp_tab' => $tab];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    set_error_handler(function ($no, $str, $file, $line) use ($tab, &$renderOk) {
        $renderOk = false;
        echo "      [{$tab}] {$str} at {$file}:{$line}\n";
        return true;
    });
    ob_start();
    flexpay_dashboard_output($vars);
    $out = ob_get_clean();
    restore_error_handler();
    if (strpos($out, 'fp-wrap') === false) {
        $renderOk = false;
        echo "      [{$tab}] missing dashboard markup\n";
    }
    if ($tab === 'overview') {
        $overviewHtml = $out;
    }
}
check('every dashboard tab renders without PHP warnings', $renderOk);
check('live-refresh URL is not HTML-escaped inside JavaScript', strpos($overviewHtml, '&amp;fp_tab=live_data') === false && strpos($overviewHtml, 'fp_tab=live_data') !== false);

$_SESSION['adminid'] = 2;
$_GET = ['fp_tab' => 'overview'];
ob_start();
flexpay_dashboard_output($vars);
$out = ob_get_clean();
check('"Restrict Access To" roles are enforced', strpos($out, 'not permitted') !== false && strpos($out, 'fp-wrap') === false);
$_SESSION['adminid'] = 1;

// ═════════════════════════════════════════════════════════════════════════════
section('Security logging');
// ═════════════════════════════════════════════════════════════════════════════
$logs = FlexPayStore::rows(DB::table('flexpay_api_log')->get());
$leak = false;
foreach ($logs as $l) {
    if (strpos($l->request_data . $l->response_data, 'SECRET-PASSKEY-VALUE') !== false || strpos($l->request_data . $l->response_data, 'ENCRYPTED-CRED') !== false) {
        $leak = true;
    }
}
check('no credentials anywhere in the API log', !$leak);
check('rejected callbacks are logged for the admin', DB::table('flexpay_api_log')->where('operation', 'callback_rejected')->exists());

$serverLog = (string) @file_get_contents($root . '/server.log');
$phpErrors = preg_match_all('/PHP (Warning|Fatal|Notice|Deprecated|Parse)[^\n]*/', $serverLog, $m);
check('no PHP errors/warnings from any endpoint', $phpErrors === 0, implode("\n      ", array_slice(array_unique($m[0] ?? []), 0, 5)));

// ── Summary ──────────────────────────────────────────────────────────────────
$failed = count($GLOBALS['fp_fail']);
echo "\n" . ($failed ? "\033[31m" : "\033[32m") . "{$GLOBALS['fp_pass']} passed, {$failed} failed\033[0m\n";
exit($failed ? 1 : 0);

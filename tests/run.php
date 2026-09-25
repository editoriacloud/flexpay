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

$refs = ['INV-42' => 42, 'inv42' => 42, 'Inv 0042' => 42, '42' => 42, '0000042' => 42, 'INV-42-A' => 42, 'ACC 2 PLAN 7' => null, '0712345678' => null, 'garbage' => null, '' => null, 'INV#7' => 7];
$ok = true;
foreach ($refs as $in => $want) {
    if (FlexPayStore::parseInvoiceRef((string) $in, 'INV') !== $want) {
        $ok = false;
        echo "      parseInvoiceRef('{$in}') = " . var_export(FlexPayStore::parseInvoiceRef((string) $in, 'INV'), true) . "\n";
    }
}
check('parseInvoiceRef tolerates typos and refuses to guess on ambiguity', $ok);

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
check('Till payment matched by hashed payer phone + amount', invoiceStatus(4) === 'Paid');
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
check('the payer\'s receipt number applies their unmatched payment', ($res['applied'] ?? false) && invoiceStatus(8) === 'Paid', json_encode($res));
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

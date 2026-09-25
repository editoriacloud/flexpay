<?php
/**
 * Minimal WHMCS runtime for tests: Capsule on SQLite, the WHMCS tables
 * FlexPay touches, and stubs of the WHMCS functions it calls. Behaviour
 * mirrors WHMCS where it matters (e.g. addInvoicePayment marks an invoice
 * Paid once fully paid).
 */

if (!defined('WHMCS')) {
    define('WHMCS', true);
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/classes/whmcs_classes.php';

use Illuminate\Database\Capsule\Manager;

if (!class_exists('WHMCS\Database\Capsule')) {
    class_alias(Manager::class, 'WHMCS\Database\Capsule');
}

/**
 * SQLite by default; set FP_TEST_MYSQL=<database> to run against MySQL /
 * MariaDB on 127.0.0.1 (user/password from FP_TEST_MYSQL_USER/_PASS, default fp/fp).
 */
function fp_test_boot_db(string $path): void
{
    $capsule = new Manager();
    $mysqlDb = getenv('FP_TEST_MYSQL');
    if ($mysqlDb) {
        $capsule->addConnection([
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => getenv('FP_TEST_MYSQL_PORT') ?: 3306,
            'database' => $mysqlDb, 'username' => getenv('FP_TEST_MYSQL_USER') ?: 'fp', 'password' => getenv('FP_TEST_MYSQL_PASS') ?: 'fp',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
    } else {
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
    }
    $capsule->setAsGlobal();
    if (!$mysqlDb) {
        Manager::connection()->statement('PRAGMA busy_timeout = 5000');
    }
}

/** Drop and recreate the MySQL test database (no-op for SQLite). */
function fp_test_reset_mysql(): void
{
    $mysqlDb = getenv('FP_TEST_MYSQL');
    if (!$mysqlDb) {
        return;
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=' . (getenv('FP_TEST_MYSQL_PORT') ?: 3306), getenv('FP_TEST_MYSQL_USER') ?: 'fp', getenv('FP_TEST_MYSQL_PASS') ?: 'fp');
    $pdo->exec('DROP DATABASE IF EXISTS `' . $mysqlDb . '`');
    $pdo->exec('CREATE DATABASE `' . $mysqlDb . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

function fp_test_create_whmcs_tables(): void
{
    $schema = Manager::schema();

    $schema->create('tblclients', function ($t) {
        $t->increments('id');
        $t->string('firstname')->default('');
        $t->string('phonenumber')->default('');
        $t->integer('currency')->default(1);
    });
    $schema->create('tblcurrencies', function ($t) {
        $t->increments('id');
        $t->string('code');
        $t->string('prefix')->default('');
        $t->decimal('rate', 10, 5)->default(1);
    });
    $schema->create('tblinvoices', function ($t) {
        $t->increments('id');
        $t->integer('userid');
        $t->decimal('total', 10, 2);
        $t->string('invoicenum')->default('');
        $t->string('status')->default('Unpaid');
        $t->date('duedate')->nullable();
    });
    $schema->create('tblaccounts', function ($t) {
        $t->increments('id');
        $t->integer('invoiceid')->default(0);
        $t->string('gateway')->default('');
        $t->string('transid')->default('');
        $t->decimal('amountin', 10, 2)->default(0);
        $t->decimal('amountout', 10, 2)->default(0);
    });
    $schema->create('tblpaymentgateways', function ($t) {
        $t->string('gateway');
        $t->string('setting');
        $t->text('value')->nullable();
    });
    $schema->create('tbladdonmodules', function ($t) {
        $t->string('module');
        $t->string('setting');
        $t->text('value')->nullable();
    });
    $schema->create('tbladminroles', function ($t) {
        $t->increments('id');
        $t->string('name');
    });
    $schema->create('tbladmins', function ($t) {
        $t->increments('id');
        $t->integer('roleid');
        $t->string('username');
    });
    $schema->create('fp_test_log', function ($t) {
        $t->increments('id');
        $t->string('kind');
        $t->text('message');
    });
}

class App
{
    public static function getSystemURL()
    {
        return getenv('FP_SYSTEM_URL') ?: 'https://billing.example.com/';
    }

    public static function load_function($name)
    {
        // everything is already loaded in tests
    }
}

function getGatewayVariables($name)
{
    $rows = Manager::table('tblpaymentgateways')->where('gateway', $name)->get();
    if (count($rows) === 0) {
        return ['type' => ''];
    }
    $out = ['type' => 'Invoices', 'name' => $name, 'paymentmethod' => $name, 'systemurl' => App::getSystemURL(), 'companyname' => 'Test Co'];
    foreach ($rows as $r) {
        $out[$r->setting] = $r->value;
    }
    return $out;
}

function logTransaction($gateway, $data, $result)
{
    Manager::table('fp_test_log')->insert(['kind' => 'gateway', 'message' => (string) $result]);
}

function logActivity($message)
{
    Manager::table('fp_test_log')->insert(['kind' => 'activity', 'message' => (string) $message]);
}

function addInvoicePayment($invoiceId, $transId, $amount, $fees, $gateway)
{
    Manager::table('tblaccounts')->insert([
        'invoiceid' => $invoiceId, 'gateway' => $gateway, 'transid' => $transId, 'amountin' => $amount,
    ]);
    $invoice = Manager::table('tblinvoices')->where('id', $invoiceId)->first();
    $paid = (float) Manager::table('tblaccounts')->where('invoiceid', $invoiceId)->sum('amountin');
    if ($paid + 0.001 >= (float) $invoice->total) {
        Manager::table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
    }
}

function checkCbTransID($transId)
{
    throw new \LogicException('FlexPay must not call checkCbTransID() (it die()s in WHMCS)');
}

function checkCbInvoiceID($invoiceId, $gateway)
{
    throw new \LogicException('FlexPay must not call checkCbInvoiceID() (it die()s in WHMCS)');
}

function encrypt($s)
{
    return 'E' . base64_encode(strrev($s));
}

function decrypt($s)
{
    return strrev((string) base64_decode(substr($s, 1)));
}

if (!function_exists('add_hook')) {
    $GLOBALS['fp_test_hooks'] = [];
    function add_hook($name, $priority, $fn)
    {
        $GLOBALS['fp_test_hooks'][$name][] = $fn;
    }
}

/**
 * Fake Daraja: responses come from the fp_daraja_mock table (path → JSON),
 * every request is recorded in fp_daraja_calls. Works across processes
 * because it's all in the shared SQLite file.
 */
function fp_test_install_daraja_mock(): void
{
    $schema = Manager::schema();
    if (!$schema->hasTable('fp_daraja_mock')) {
        $schema->create('fp_daraja_mock', function ($t) {
            $t->string('path')->primary();
            $t->integer('status')->default(200);
            $t->text('body');
        });
        $schema->create('fp_daraja_calls', function ($t) {
            $t->increments('id');
            $t->string('path');
            $t->text('body')->nullable();
        });
    }
}

function fp_test_daraja_transport(string $method, string $url, array $headers, ?string $body): array
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    Manager::table('fp_daraja_calls')->insert(['path' => $path, 'body' => (string) $body]);

    if ($path === '/oauth/v1/generate') {
        return [200, json_encode(['access_token' => 'test-token', 'expires_in' => '3599'])];
    }

    $mock = Manager::table('fp_daraja_mock')->where('path', $path)->first();
    if (!$mock) {
        return [500, json_encode(['errorMessage' => 'no mock for ' . $path])];
    }
    return [(int) $mock->status, $mock->body];
}

// ── WHMCS 8.x APIs used by FlexPay's native integrations ─────────────────────

function localAPI($command, $params, $admin = null)
{
    Manager::table('fp_test_log')->insert(['kind' => 'localapi', 'message' => $command . ' ' . json_encode($params)]);
    if ($command === 'AddTransaction') {
        if (Manager::table('tblaccounts')->where('transid', $params['transid'])->exists()) {
            return ['result' => 'error', 'message' => 'Transaction ID must be unique'];
        }
        Manager::table('tblaccounts')->insert(['invoiceid' => (int) ($params['invoiceid'] ?? 0), 'gateway' => $params['paymentmethod'], 'transid' => $params['transid'], 'amountin' => $params['amountin']]);
        return ['result' => 'success'];
    }
    return ['result' => 'error', 'message' => 'unsupported in tests'];
}

function paymentReversed($reverseTransactionId, $originalTransactionId)
{
    $orig = Manager::table('tblaccounts')->where('transid', $originalTransactionId)->first();
    if (!$orig) {
        throw new \Exception('Original transaction not found');
    }
    Manager::table('tblaccounts')->insert(['invoiceid' => $orig->invoiceid, 'gateway' => $orig->gateway, 'transid' => $reverseTransactionId, 'amountout' => $orig->amountin]);
    Manager::table('tblinvoices')->where('id', $orig->invoiceid)->update(['status' => 'Collections']);
    Manager::table('fp_test_log')->insert(['kind' => 'reversed', 'message' => $reverseTransactionId . ' ' . $originalTransactionId]);
}

function logModuleCall($module, $action, $request, $response, $processed = '', $replace = [])
{
    Manager::table('fp_test_log')->insert(['kind' => 'modulelog', 'message' => $action . ' ' . json_encode($request) . ' REPLACE:' . count($replace)]);
}

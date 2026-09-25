<?php
/**
 * Upgrade test: builds a genuine v3.4.0 database (its original
 * sql/install.sql, MySQL only), then lets the current code migrate it.
 *
 *   FP_TEST_MYSQL=fp_migration php migration.php
 */

error_reporting(E_ALL);

if (!getenv('FP_TEST_MYSQL')) {
    echo "Set FP_TEST_MYSQL=<database> — the v3.4 schema is MySQL-only.\n";
    exit(0);
}

require __DIR__ . '/fixtures/whmcs_stubs.php';
fp_test_reset_mysql();
fp_test_boot_db('');

use Illuminate\Database\Capsule\Manager as DB;

$sql = (string) file_get_contents(__DIR__ . '/fixtures/legacy_v3.4_install.sql');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) {
    DB::statement($stmt);
}

// Data as v3.4 left it: '' identifiers, a pending STK row, a C2B row.
$now = date('Y-m-d H:i:s');
DB::table('flexpay_transactions')->insert([
    ['channel' => 'stk', 'invoice_id' => 1, 'checkout_request_id' => 'ws_CO_old', 'mpesa_receipt' => '', 'amount' => 100, 'status' => 'pending', 'created_at' => $now],
    ['channel' => 'c2b', 'invoice_id' => 2, 'checkout_request_id' => '', 'mpesa_receipt' => 'OLDC2B0001', 'amount' => 200, 'status' => 'success', 'created_at' => $now],
]);
DB::table('flexpay_settings')->insert(['setting_key' => 'c2b_registration_hash', 'setting_value' => 'abc', 'updated_at' => $now]);

require __DIR__ . '/../modules/gateways/flexpay/FlexPaySecurity.php';
require __DIR__ . '/../modules/gateways/flexpay/DarajaClient.php';
require __DIR__ . '/../modules/gateways/flexpay/FlexPayStore.php';

FlexPayStore::ensureTables();

$fail = 0;
$check = function (string $name, bool $ok) use (&$fail) {
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗ ") . $name . ($ok ? '' : "\033[0m") . "\n";
    $fail += $ok ? 0 : 1;
};

$col = function (string $table, string $column) {
    return DB::selectOne('SELECT IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH AS len FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
};

echo "\n\033[1mv3.4.0 → current schema migration (MySQL)\033[0m\n";
$check('schema_version recorded', (int) FlexPayStore::getSetting('schema_version') === FlexPayStore::SCHEMA_VERSION);
$check('checkout_request_id / mpesa_receipt now nullable', $col('flexpay_transactions', 'checkout_request_id')->IS_NULLABLE === 'YES' && $col('flexpay_transactions', 'mpesa_receipt')->IS_NULLABLE === 'YES');
$check("legacy '' identifiers converted to NULL", DB::table('flexpay_transactions')->where('mpesa_receipt', '')->count() === 0 && DB::table('flexpay_transactions')->where('checkout_request_id', '')->count() === 0);
$check('phone column widened for hashed MSISDNs', (int) $col('flexpay_transactions', 'phone')->len === 64);
$check('new columns added', DB::schema()->hasColumn('flexpay_transactions', 'last_checked_at') && DB::schema()->hasColumn('flexpay_refunds', 'originator_conversation_id')
    && DB::schema()->hasColumn('flexpay_refunds', 'retried_as') && DB::schema()->hasColumn('flexpay_unmatched_payments', 'suggested_invoice_id'));
$check('existing data and settings preserved', DB::table('flexpay_transactions')->count() === 2 && FlexPayStore::getSetting('c2b_registration_hash') === 'abc');

FlexPayStore::recordTransaction(['channel' => 'stk', 'invoice_id' => 3, 'checkout_request_id' => 'ws_CO_new1', 'amount' => 5, 'status' => 'pending']);
FlexPayStore::recordTransaction(['channel' => 'stk', 'invoice_id' => 4, 'checkout_request_id' => 'ws_CO_new2', 'amount' => 5, 'status' => 'pending']);
FlexPayStore::recordTransaction(['channel' => 'c2b', 'mpesa_receipt' => 'NEWC2B0001', 'amount' => 5, 'status' => 'success']);
FlexPayStore::recordTransaction(['channel' => 'c2b', 'mpesa_receipt' => 'NEWC2B0002', 'amount' => 5, 'status' => 'success']);
$check('several pending STK + C2B rows can now coexist', DB::table('flexpay_transactions')->count() === 6);

echo "\n" . ($fail ? "\033[31m{$fail} failed\033[0m" : "\033[32mmigration OK\033[0m") . "\n";
exit($fail ? 1 : 0);

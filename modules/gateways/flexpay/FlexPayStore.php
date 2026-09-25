<?php
/**
 * FlexPayStore — shared database access layer.
 *
 * Wraps every read/write touching the flexpay_* tables so both the
 * gateway module and the dashboard addon manipulate data identically.
 * Also owns table auto-creation and versioned migrations (idempotent,
 * safe to call every request), and every path that applies money to a
 * WHMCS invoice — so the rules for doing that safely live in one place.
 *
 * @package FlexPay\Daraja
 * @version 3.7.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class FlexPayStore
{
    /** Bump when ensureTables() gains a migration. */
    public const SCHEMA_VERSION = 3;

    /** Invoice statuses a payment can be auto-applied to without a human deciding. */
    public const OPEN_INVOICE_STATUSES = ['Unpaid', 'Overdue', 'Payment Pending'];

    public const GATEWAY = 'flexpay';

    // ─────────────────────────────────────────────────────────────────────
    // WHMCS function library loading
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ensure getGatewayVariables(), logTransaction() etc. are callable.
     * WHMCS only auto-loads includes/gatewayfunctions.php for gateway and
     * callback requests, not for addon pages or cron hooks.
     */
    public static function ensureGatewayFunctionsLoaded(): void
    {
        self::loadWhmcsLibrary('getGatewayVariables', 'gateway', 'gatewayfunctions.php');
    }

    /** Ensure addInvoicePayment() is callable (includes/invoicefunctions.php). */
    public static function ensureInvoiceFunctionsLoaded(): void
    {
        self::loadWhmcsLibrary('addInvoicePayment', 'invoice', 'invoicefunctions.php');
    }

    private static function loadWhmcsLibrary(string $probe, string $name, string $file): void
    {
        if (function_exists($probe)) {
            return;
        }

        if (class_exists('App')) {
            try {
                \App::load_function($name);
            } catch (\Throwable $e) {
                // fall through to the direct require below
            }
        }

        if (!function_exists($probe)) {
            // This file lives at modules/gateways/flexpay/, three levels
            // below the WHMCS root.
            $path = __DIR__ . '/../../../includes/' . $file;
            if (is_file($path)) {
                require_once $path;
            }
        }
    }

    /**
     * The FlexPay gateway's saved configuration. Returns ['type' => ''] if
     * the gateway library can't be loaded, so callers can check $gw['type'].
     */
    public static function getFlexPayGatewayParams(): array
    {
        self::ensureGatewayFunctionsLoaded();

        if (!function_exists('getGatewayVariables')) {
            return ['type' => ''];
        }

        $gw = getGatewayVariables(self::GATEWAY);
        return is_array($gw) ? $gw : ['type' => ''];
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Schema
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Idempotently create/migrate all flexpay_* tables. Once the stored
     * schema_version matches SCHEMA_VERSION this costs one indexed read.
     */
    public static function ensureTables(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        if (!class_exists('\WHMCS\Database\Capsule')) {
            return;
        }

        try {
            $schema = Capsule::schema();

            if ($schema->hasTable('flexpay_settings')) {
                $row = Capsule::table('flexpay_settings')->where('setting_key', 'schema_version')->first();
                if ($row && (int) $row->setting_value >= self::SCHEMA_VERSION) {
                    return;
                }
            }

            self::createTables($schema);
            self::migrate($schema);

            self::setSetting('schema_version', (string) self::SCHEMA_VERSION);
        } catch (\Throwable $e) {
            if (function_exists('logActivity')) {
                logActivity('FlexPay: table auto-creation/migration failed — ' . $e->getMessage());
            }
        }
    }

    private static function createTables($schema): void
    {
        if (!$schema->hasTable('flexpay_transactions')) {
            $schema->create('flexpay_transactions', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->enum('channel', ['stk', 'c2b', 'b2c', 'reversal']);
                $table->enum('direction', ['in', 'out'])->default('in');
                $table->unsignedInteger('invoice_id')->nullable();
                $table->unsignedInteger('client_id')->nullable();
                // NULL (not '') when absent: these carry UNIQUE indexes, and
                // MySQL allows many NULLs but only one ''.
                $table->string('checkout_request_id', 100)->nullable();
                $table->string('merchant_request_id', 100)->default('');
                $table->string('conversation_id', 100)->default('');
                $table->string('originator_conversation_id', 100)->default('');
                $table->string('mpesa_receipt', 30)->nullable();
                $table->string('phone', 64)->default('');
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('account_reference', 50)->default('');
                $table->enum('status', ['pending', 'success', 'failed', 'reversed'])->default('pending');
                $table->string('result_code', 20)->default('');
                $table->string('result_desc', 255)->default('');
                $table->string('payment_outcome', 20)->nullable();
                $table->text('raw_request')->nullable();
                $table->text('raw_response')->nullable();
                $table->dateTime('last_checked_at')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at')->nullable();

                $table->unique('checkout_request_id', 'uq_checkout_request_id');
                $table->unique('mpesa_receipt', 'uq_mpesa_receipt');
                $table->index('invoice_id');
                $table->index('client_id');
                $table->index('channel');
                $table->index('status');
                $table->index('created_at');
                $table->index('phone');
                $table->index('conversation_id');
            });
        }

        if (!$schema->hasTable('flexpay_unmatched_payments')) {
            $schema->create('flexpay_unmatched_payments', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->string('trans_id', 30)->unique();
                $table->decimal('amount', 12, 2);
                $table->string('phone', 64)->default('');
                $table->string('bill_ref', 50)->default('');
                $table->string('customer_name', 150)->default('');
                $table->text('raw_data')->nullable();
                $table->boolean('matched')->default(false);
                $table->unsignedInteger('matched_invoice_id')->nullable();
                $table->unsignedInteger('suggested_invoice_id')->nullable();
                $table->string('matched_by', 100)->default('');
                $table->string('notes', 255)->default('');
                $table->dateTime('created_at');
                $table->dateTime('matched_at')->nullable();

                $table->index('matched');
                $table->index('created_at');
            });
        }

        if (!$schema->hasTable('flexpay_refunds')) {
            $schema->create('flexpay_refunds', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->unsignedInteger('invoice_id');
                $table->string('original_trans_id', 100)->default('');
                $table->string('conversation_id', 100)->default('');
                $table->string('originator_conversation_id', 100)->default('');
                $table->string('phone', 15);
                $table->decimal('amount', 12, 2);
                $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
                $table->string('result_desc', 255)->default('');
                $table->string('initiated_by', 100)->default('');
                $table->unsignedInteger('retried_as')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at')->nullable();

                $table->index('invoice_id');
                $table->index('status');
                $table->index('conversation_id');
                $table->index('originator_conversation_id');
                $table->index('created_at');
            });
        }

        if (!$schema->hasTable('flexpay_balance_snapshots')) {
            $schema->create('flexpay_balance_snapshots', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->string('shortcode', 20);
                $table->decimal('working_account', 14, 2)->default(0);
                $table->decimal('utility_account', 14, 2)->default(0);
                $table->decimal('charges_account', 14, 2)->default(0);
                $table->text('raw_response')->nullable();
                $table->dateTime('created_at');

                $table->index('shortcode');
                $table->index('created_at');
            });
        }

        if (!$schema->hasTable('flexpay_api_log')) {
            $schema->create('flexpay_api_log', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');
                $table->string('operation', 40);
                $table->text('request_data')->nullable();
                $table->text('response_data')->nullable();
                $table->boolean('success')->default(false);
                $table->string('triggered_by', 100)->default('');
                $table->dateTime('created_at');

                $table->index('operation');
                $table->index('success');
                $table->index('created_at');
            });
        }

        if (!$schema->hasTable('flexpay_settings')) {
            $schema->create('flexpay_settings', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->string('setting_key', 100)->primary();
                $table->text('setting_value')->nullable();
                $table->dateTime('updated_at');
            });
        }
    }

    /**
     * Additive migrations for installs created by earlier versions.
     * Every step checks before it changes anything, so re-running is safe.
     */
    private static function migrate($schema): void
    {
        $addColumn = function (string $table, string $column, callable $definition) use ($schema) {
            if ($schema->hasTable($table) && !$schema->hasColumn($table, $column)) {
                $schema->table($table, $definition);
            }
        };

        $addColumn('flexpay_transactions', 'payment_outcome', function ($t) {
            $t->string('payment_outcome', 20)->nullable();
        });
        $addColumn('flexpay_transactions', 'last_checked_at', function ($t) {
            $t->dateTime('last_checked_at')->nullable();
        });
        $addColumn('flexpay_refunds', 'originator_conversation_id', function ($t) {
            $t->string('originator_conversation_id', 100)->default('');
        });
        $addColumn('flexpay_refunds', 'retried_as', function ($t) {
            $t->unsignedInteger('retried_as')->nullable();
        });
        $addColumn('flexpay_unmatched_payments', 'suggested_invoice_id', function ($t) {
            $t->unsignedInteger('suggested_invoice_id')->nullable();
        });

        // v3.4.0 and earlier declared checkout_request_id / mpesa_receipt as
        // NOT NULL DEFAULT '' with UNIQUE indexes, so only ONE pending STK
        // row (and one C2B row) could ever exist — every later insert failed
        // silently. Make them nullable and turn '' into NULL. Column type
        // changes need raw SQL (Blueprint::change() requires doctrine/dbal,
        // which WHMCS doesn't ship); fresh installs already get nullable
        // columns from createTables().
        if (Capsule::connection()->getDriverName() === 'mysql') {
            Capsule::statement(
                'ALTER TABLE `flexpay_transactions`'
                . ' MODIFY `checkout_request_id` VARCHAR(100) NULL DEFAULT NULL,'
                . ' MODIFY `mpesa_receipt` VARCHAR(30) NULL DEFAULT NULL,'
                . ' MODIFY `phone` VARCHAR(64) NOT NULL DEFAULT \'\''
            );
            Capsule::statement('ALTER TABLE `flexpay_unmatched_payments` MODIFY `phone` VARCHAR(64) NOT NULL DEFAULT \'\'');
            Capsule::statement('ALTER TABLE `flexpay_refunds` MODIFY `original_trans_id` VARCHAR(100) NOT NULL DEFAULT \'\'');
        }

        Capsule::table('flexpay_transactions')->where('checkout_request_id', '')->update(['checkout_request_id' => null]);
        Capsule::table('flexpay_transactions')->where('mpesa_receipt', '')->update(['mpesa_receipt' => null]);

        if ($schema->hasTable('flexpay_transactions') && !self::hasIndex('flexpay_transactions', 'flexpay_transactions_conversation_id_index')) {
            try {
                $schema->table('flexpay_transactions', function ($t) {
                    $t->index('conversation_id');
                });
            } catch (\Throwable $e) {
                // index already exists under another name — fine
            }
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        try {
            if (Capsule::connection()->getDriverName() !== 'mysql') {
                return true; // test/sqlite installs are always created fresh
            }
            return !empty(Capsule::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]));
        } catch (\Throwable $e) {
            return true;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_transactions
    // ─────────────────────────────────────────────────────────────────────

    /** Empty identifiers must be stored as NULL (see createTables()). */
    private static function normalizeIdentifiers(array $data): array
    {
        foreach (['checkout_request_id', 'mpesa_receipt'] as $col) {
            if (array_key_exists($col, $data) && ($data[$col] === '' || $data[$col] === null)) {
                $data[$col] = null;
            }
        }
        foreach (['result_desc' => 255, 'account_reference' => 50, 'phone' => 64] as $col => $max) {
            if (isset($data[$col]) && is_string($data[$col]) && strlen($data[$col]) > $max) {
                $data[$col] = substr($data[$col], 0, $max);
            }
        }
        return $data;
    }

    /**
     * Insert or update a transaction record, keyed by checkout_request_id
     * (STK) or mpesa_receipt (everything else).
     */
    public static function recordTransaction(array $data): void
    {
        self::ensureTables();

        $data = self::normalizeIdentifiers($data);
        $data['updated_at'] = self::now();

        try {
            $existing = null;

            if (!empty($data['checkout_request_id'])) {
                $existing = Capsule::table('flexpay_transactions')
                    ->where('checkout_request_id', $data['checkout_request_id'])
                    ->first(['id']);
            } elseif (!empty($data['mpesa_receipt'])) {
                $existing = Capsule::table('flexpay_transactions')
                    ->where('mpesa_receipt', $data['mpesa_receipt'])
                    ->first(['id']);
            }

            if ($existing) {
                unset($data['created_at']);
                Capsule::table('flexpay_transactions')->where('id', $existing->id)->update($data);
            } else {
                $data['created_at'] = $data['created_at'] ?? self::now();
                Capsule::table('flexpay_transactions')->insert($data);
            }
        } catch (\Throwable $e) {
            self::activity('recordTransaction failed — ' . $e->getMessage());
        }
    }

    /**
     * Insert a row whose unique key (receipt or checkout ID) must not exist
     * yet. The UNIQUE index turns this into an atomic "first writer wins"
     * lock, which is what stops a retried Daraja callback being applied
     * twice. Returns the new ID, or null if the row already existed.
     */
    public static function insertTransactionOnce(array $data): ?int
    {
        self::ensureTables();

        $data = self::normalizeIdentifiers($data);
        $data['created_at'] = $data['created_at'] ?? self::now();
        $data['updated_at'] = self::now();

        try {
            return (int) Capsule::table('flexpay_transactions')->insertGetId($data);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function findTransactionByReceipt(string $receipt): ?object
    {
        self::ensureTables();
        if ($receipt === '') {
            return null;
        }
        try {
            return Capsule::table('flexpay_transactions')->where('mpesa_receipt', $receipt)->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function findTransactionByCheckoutId(string $checkoutId): ?object
    {
        self::ensureTables();
        if ($checkoutId === '') {
            return null;
        }
        try {
            return Capsule::table('flexpay_transactions')->where('checkout_request_id', $checkoutId)->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Admin-only lookup by receipt, checkout request ID, or account reference.
     * NOT used by the customer-facing verify path (receipt only there).
     */
    public static function findTransactionByReference(string $reference): ?object
    {
        self::ensureTables();

        $reference = trim($reference);
        if ($reference === '') {
            return null;
        }

        try {
            return Capsule::table('flexpay_transactions')
                ->where(function ($q) use ($reference) {
                    $q->where('mpesa_receipt', $reference)
                      ->orWhere('checkout_request_id', $reference)
                      ->orWhere('account_reference', $reference);
                })
                ->orderBy('created_at', 'desc')
                ->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoices, balances, currency
    // ─────────────────────────────────────────────────────────────────────

    public static function getInvoice(int $invoiceId): ?object
    {
        if ($invoiceId <= 0) {
            return null;
        }
        try {
            return Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['id', 'userid', 'total', 'status', 'duedate']) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Sum of (amountin - amountout) already recorded against an invoice. */
    public static function getInvoicePaid(int $invoiceId): float
    {
        try {
            $row = Capsule::table('tblaccounts')
                ->where('invoiceid', $invoiceId)
                ->selectRaw('COALESCE(SUM(amountin), 0) AS paid_in, COALESCE(SUM(amountout), 0) AS paid_out')
                ->first();
            return $row ? round((float) $row->paid_in - (float) $row->paid_out, 2) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Outstanding balance in the invoice's own currency. WHMCS has no
     * `balance` column on tblinvoices (the Invoice model computes it the
     * same way), which is why the v3.2 partial-payment features never
     * worked: every query against `balance` threw and was swallowed.
     */
    public static function getInvoiceBalance(int $invoiceId): ?float
    {
        $invoice = self::getInvoice($invoiceId);
        if (!$invoice) {
            return null;
        }
        return round((float) $invoice->total - self::getInvoicePaid($invoiceId), 2);
    }

    /** @return object|null {id, code, prefix, rate} */
    public static function getCurrencyByCode(string $code): ?object
    {
        try {
            return Capsule::table('tblcurrencies')->where('code', strtoupper($code))->first(['id', 'code', 'prefix', 'rate']) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The currency an invoice is billed in (the client's currency). */
    public static function getInvoiceCurrency(int $invoiceId): ?object
    {
        try {
            $invoice = self::getInvoice($invoiceId);
            if (!$invoice) {
                return null;
            }
            $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first(['currency']);
            if (!$client) {
                return null;
            }
            return Capsule::table('tblcurrencies')->where('id', $client->currency)->first(['id', 'code', 'prefix', 'rate']) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Convert between two WHMCS currencies using their configured rates
     * (rates are relative to the WHMCS default currency, whose rate is 1).
     */
    public static function convertAmount(float $amount, ?object $from, ?object $to): float
    {
        if (!$from || !$to || (int) $from->id === (int) $to->id) {
            return $amount;
        }
        $fromRate = (float) $from->rate;
        $toRate   = (float) $to->rate;
        if ($fromRate <= 0 || $toRate <= 0) {
            return $amount;
        }
        return round($amount / $fromRate * $toRate, 2);
    }

    /** M-Pesa always settles in KES; convert a KES amount into the invoice's currency. */
    public static function kesToInvoiceCurrency(int $invoiceId, float $kes): float
    {
        $invoiceCurrency = self::getInvoiceCurrency($invoiceId);
        if (!$invoiceCurrency || strtoupper((string) $invoiceCurrency->code) === 'KES') {
            return $kes;
        }
        $kesCurrency = self::getCurrencyByCode('KES');
        return $kesCurrency ? self::convertAmount($kes, $kesCurrency, $invoiceCurrency) : $kes;
    }

    /**
     * KES amount due for an invoice, rounded UP to a whole shilling (M-Pesa
     * only moves whole shillings). Returns null if the invoice's currency
     * isn't KES and no KES currency/rate is configured in WHMCS.
     */
    public static function invoiceBalanceInKes(int $invoiceId): ?int
    {
        $balance = self::getInvoiceBalance($invoiceId);
        if ($balance === null) {
            return null;
        }

        $invoiceCurrency = self::getInvoiceCurrency($invoiceId);
        if ($invoiceCurrency && strtoupper((string) $invoiceCurrency->code) !== 'KES') {
            $kesCurrency = self::getCurrencyByCode('KES');
            if (!$kesCurrency) {
                return null;
            }
            $balance = self::convertAmount($balance, $invoiceCurrency, $kesCurrency);
        }

        // Guard against float noise (e.g. 100.0000001 → 101).
        return (int) ceil(round($balance, 2) - 0.001);
    }

    /** Would WHMCS's own checkCbTransID() consider this a duplicate? (without its die()) */
    public static function transIdExists(string $transId): bool
    {
        if ($transId === '') {
            return false;
        }
        try {
            return Capsule::table('tblaccounts')->where('transid', $transId)->exists();
        } catch (\Throwable $e) {
            return true; // fail safe: never risk a double credit
        }
    }

    /**
     * The single path through which FlexPay puts money on a WHMCS invoice.
     *
     * Replaces the old checkCbInvoiceID() → checkCbTransID() →
     * addInvoicePayment() sequence. Both of those WHMCS helpers call die()
     * on failure, which inside a Daraja callback killed the request with no
     * JSON response, and inside the admin dashboard blanked the page. They
     * could never reach the surrounding try/catch.
     *
     * @param  int         $invoiceId
     * @param  string      $transId        Ledger transaction ID (receipt, or CheckoutRequestID when the receipt isn't known yet)
     * @param  float       $kesAmount      Amount actually received, in KES
     * @param  string[]|null $allowedStatuses  Invoice statuses allowed. null = OPEN_INVOICE_STATUSES:
     *                                          money is never put on a Paid / Refunded / Collections /
     *                                          Cancelled / Draft invoice (it would silently become
     *                                          overpayment credit). Use creditUnmatchedToClient() for that.
     * @return array ['applied' => bool, 'reason' => string, 'message' => string, 'outcome' => array|null, 'invoice_id' => int]
     */
    public static function applyPaymentToInvoice(int $invoiceId, string $transId, float $kesAmount, ?array $allowedStatuses = null): array
    {
        $fail = function (string $reason, string $message) use ($invoiceId) {
            return ['applied' => false, 'reason' => $reason, 'message' => $message, 'outcome' => null, 'invoice_id' => $invoiceId];
        };

        if ($kesAmount <= 0) {
            return $fail('invalid_amount', 'Payment amount must be greater than zero.');
        }

        $invoice = self::getInvoice($invoiceId);
        if (!$invoice) {
            return $fail('invoice_not_found', "Invoice #{$invoiceId} does not exist.");
        }

        if (!in_array($invoice->status, $allowedStatuses ?? self::OPEN_INVOICE_STATUSES, true)) {
            return $fail('invoice_status', "Invoice #{$invoiceId} is {$invoice->status}, not awaiting payment — not applied. Use \"Credit to client\" to put the money on the client's account instead.");
        }

        if (self::transIdExists($transId)) {
            return $fail('duplicate', "Transaction {$transId} has already been recorded in WHMCS.");
        }

        self::ensureInvoiceFunctionsLoaded();
        if (!function_exists('addInvoicePayment')) {
            return $fail('whmcs_unavailable', 'WHMCS invoice functions could not be loaded.');
        }

        $amount = round(self::kesToInvoiceCurrency($invoiceId, $kesAmount), 2);

        // WHMCS treats an amount of 0/'' as "pay the FULL balance". Never let
        // a tiny payment that rounds to 0.00 after conversion get there.
        if ($amount < 0.01) {
            return $fail('invalid_amount', 'Payment converts to less than 0.01 in the invoice currency — not applied.');
        }

        try {
            addInvoicePayment($invoiceId, $transId, $amount, 0, self::GATEWAY);
        } catch (\Throwable $e) {
            return $fail('whmcs_error', 'WHMCS rejected the payment: ' . $e->getMessage());
        }

        $outcome = self::classifyPaymentOutcome($invoiceId, $amount);

        return ['applied' => true, 'reason' => 'applied', 'message' => $outcome['message'], 'outcome' => $outcome, 'invoice_id' => $invoiceId];
    }

    /**
     * Classify the result of a payment just applied to an invoice: exact,
     * partial, or overpaid. Observational only — WHMCS itself already
     * credited any overpayment to the client (addInvoicePayment does that).
     *
     * @param  int   $invoiceId
     * @param  float $amountPaid  Amount just applied, in the invoice's currency
     */
    public static function classifyPaymentOutcome(int $invoiceId, float $amountPaid): array
    {
        $result = [
            'outcome'        => 'unknown',
            'invoice_total'  => null,
            'balance_before' => null,
            'balance_after'  => null,
            'remaining'      => 0.0,
            'credited'       => 0.0,
            'message'        => 'Payment applied.',
        ];

        $invoice = self::getInvoice($invoiceId);
        if (!$invoice) {
            return $result;
        }

        $currency = self::getInvoiceCurrency($invoiceId);
        $code     = $currency ? strtoupper((string) $currency->code) : 'KES';
        $fmt      = function (float $v) use ($code) {
            return $code . ' ' . number_format($v, 2);
        };

        $total = (float) $invoice->total;
        // After an overpayment WHMCS adds the excess to client credit with a
        // matching amountout row, so paid never exceeds total here.
        $balanceAfter  = max(0.0, round($total - self::getInvoicePaid($invoiceId), 2));
        $balanceBefore = min($total, round($balanceAfter + $amountPaid, 2));

        $result['invoice_total']  = $total;
        $result['balance_before'] = $balanceBefore;
        $result['balance_after']  = $balanceAfter;

        if ($balanceAfter > 0.009) {
            $result['outcome']   = 'partial';
            $result['remaining'] = $balanceAfter;
            $result['message']   = sprintf('%s received and applied. %s still due on this invoice.', $fmt($amountPaid), $fmt($balanceAfter));
        } elseif ($amountPaid > $balanceBefore + 0.009) {
            $overage = round($amountPaid - $balanceBefore, 2);
            $result['outcome']  = 'overpaid';
            $result['credited'] = $overage;
            $result['message']  = sprintf(
                '%s received. %s applied to this invoice and %s credited to your account balance for future use.',
                $fmt($amountPaid), $fmt($balanceBefore), $fmt($overage)
            );
        } else {
            $result['outcome'] = 'exact';
            $result['message'] = sprintf('%s received and this invoice is now fully paid.', $fmt($amountPaid));
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // STK settlement
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Settle a successful STK Push exactly once, whichever path confirmed
     * it first: the Daraja callback, the invoice-page poller's live query,
     * or the cron sweeper.
     *
     * Only call this AFTER success has been independently confirmed
     * (authenticated callback and/or a Daraja STK query returning 0).
     *
     * Exactly-once: the pending row is claimed with a conditional UPDATE
     * (WHERE status IN ('pending','failed')), so concurrent callers can't
     * both apply the payment. The amount applied is the amount WE asked
     * Safaricom to collect (stored when the push was created) — never an
     * amount taken from the request that reported success.
     *
     * When the receipt isn't known yet (Daraja's STK query doesn't return
     * it), the CheckoutRequestID is used as the WHMCS transaction ID; when
     * the callback later delivers the receipt, attachReceiptToStk() swaps it
     * in on both our row and the WHMCS ledger entry.
     *
     * @return array ['applied' => bool, 'already_settled' => bool, 'invoice_id' => int|null, 'message' => string]
     */
    public static function settleStk(string $checkoutId, ?string $receipt, string $source, ?string $phone = null, ?float $reportedAmount = null): array
    {
        self::ensureTables();

        $receipt = ($receipt !== null && $receipt !== '') ? $receipt : null;
        $row     = self::findTransactionByCheckoutId($checkoutId);

        if (!$row) {
            // Daraja confirmed a push we have no record of (e.g. created by an
            // older version). Record it for the Reconciliation queue — never
            // guess an invoice.
            if ($receipt) {
                self::insertTransactionOnce([
                    'channel' => 'stk', 'direction' => 'in', 'checkout_request_id' => $checkoutId,
                    'mpesa_receipt' => $receipt, 'phone' => (string) $phone, 'amount' => (float) $reportedAmount,
                    'status' => 'success', 'result_code' => '0',
                    'result_desc' => 'Confirmed STK payment with no matching request on file — needs manual reconciliation.',
                ]);
                self::storeUnmatched([
                    'trans_id' => $receipt, 'amount' => (float) $reportedAmount, 'phone' => (string) $phone,
                    'bill_ref' => '(STK ' . $checkoutId . ')', 'notes' => 'STK payment with no request on file.',
                ]);
            }
            return ['applied' => false, 'already_settled' => false, 'invoice_id' => null, 'message' => 'No request on file for this checkout ID.'];
        }

        if ($receipt) {
            $holder = self::findTransactionByReceipt($receipt);
            if ($holder && (int) $holder->id !== (int) $row->id) {
                if (!self::absorbDuplicateReceipt($holder, $checkoutId)) {
                    // The receipt was already credited through another path
                    // (e.g. a C2B confirmation with the exact reference).
                    if ($row->status !== 'success') {
                        Capsule::table('flexpay_transactions')->where('id', $row->id)->whereIn('status', ['pending', 'failed'])->update([
                            'status' => 'success', 'result_code' => '0', 'updated_at' => self::now(),
                            'result_desc' => substr('Paid — already credited via receipt ' . $receipt . '.', 0, 255),
                        ]);
                    }
                    return ['applied' => false, 'already_settled' => true, 'invoice_id' => $holder->invoice_id ? (int) $holder->invoice_id : null, 'message' => 'Already credited via receipt ' . $receipt . '.'];
                }
            }
        }

        if ($row->status === 'success') {
            if ($receipt) {
                self::attachReceiptToStk($row, $receipt);
            }
            return [
                'applied' => false, 'already_settled' => true,
                'invoice_id' => $row->invoice_id ? (int) $row->invoice_id : null,
                'message' => $row->result_desc ?: 'Already settled — no action taken.',
            ];
        }

        // ── Atomic claim ──────────────────────────────────────────────────
        $claim = ['status' => 'success', 'result_code' => '0', 'updated_at' => self::now()];
        if ($receipt) {
            $claim['mpesa_receipt'] = $receipt;
        }
        if ($phone) {
            $claim['phone'] = substr($phone, 0, 64);
        }

        try {
            $claimed = Capsule::table('flexpay_transactions')
                ->where('id', $row->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update($claim);
        } catch (\Throwable $e) {
            // Most likely the receipt is already on another row (duplicate
            // callback that lost the race) — treat as already handled.
            $claimed = 0;
        }

        if ($claimed !== 1) {
            $fresh = self::findTransactionByCheckoutId($checkoutId);
            return [
                'applied' => false, 'already_settled' => true,
                'invoice_id' => ($fresh && $fresh->invoice_id) ? (int) $fresh->invoice_id : null,
                'message' => ($fresh && $fresh->result_desc) ? $fresh->result_desc : 'Already settled — no action taken.',
            ];
        }

        $invoiceId = $row->invoice_id ? (int) $row->invoice_id : 0;
        $amount    = (float) $row->amount;
        $transId   = $receipt ?: $checkoutId;

        if ($reportedAmount !== null && $reportedAmount > 0 && abs($reportedAmount - $amount) > 0.009) {
            self::logApiCall('stk_amount_mismatch', ['checkout_request_id' => $checkoutId], ['requested' => $amount, 'reported' => $reportedAmount], false, $source);
        }

        // Rigid: an STK push carries OUR reference for its invoice, but if
        // that invoice was meanwhile paid/cancelled the money is queued for
        // a human instead of silently becoming client credit.
        $apply = $invoiceId
            ? self::applyPaymentToInvoice($invoiceId, $transId, $amount, self::OPEN_INVOICE_STATUSES)
            : ['applied' => false, 'reason' => 'no_invoice', 'message' => 'No invoice linked to this request.', 'outcome' => null];

        $desc = $apply['applied']
            ? $apply['message']
            : ('Paid, but not applied automatically: ' . $apply['message']);
        if ($source !== 'callback') {
            $desc .= " [settled via {$source}]";
        }

        Capsule::table('flexpay_transactions')->where('id', $row->id)->update([
            'result_desc'     => substr($desc, 0, 255),
            'payment_outcome' => $apply['outcome']['outcome'] ?? null,
            'updated_at'      => self::now(),
        ]);

        if (!$apply['applied'] && $apply['reason'] !== 'duplicate') {
            self::storeUnmatched([
                'trans_id'             => $transId,
                'amount'               => $amount,
                'phone'                => (string) ($phone ?: $row->phone),
                'bill_ref'             => (string) $row->account_reference,
                'notes'                => substr('STK payment not auto-applied: ' . $apply['message'], 0, 255),
                'suggested_invoice_id' => $invoiceId ?: null,
            ]);
        }

        self::logApiCall(
            'stk_settlement',
            ['checkout_request_id' => $checkoutId, 'receipt' => $receipt, 'amount' => $amount, 'source' => $source],
            ['invoice_id' => $invoiceId ?: null, 'applied' => $apply['applied'], 'message' => $apply['message']],
            $apply['applied'],
            $source
        );

        return [
            'applied'         => $apply['applied'],
            'already_settled' => false,
            'invoice_id'      => $apply['applied'] ? $invoiceId : null,
            'message'         => $apply['applied'] ? $apply['message'] : $desc,
            'outcome'         => $apply['outcome'] ?? null,
        ];
    }

    /**
     * A receipt we're about to record on an STK row already sits on another
     * row. If that row is an UNAPPLIED C2B confirmation (the Till echo of
     * this very STK payment, which arrived first and was queued), remove it
     * and its Reconciliation entry so the payment exists once. Returns false
     * if the other row was already applied — the caller must not credit again.
     */
    private static function absorbDuplicateReceipt(object $holder, string $checkoutId): bool
    {
        if ($holder->channel !== 'c2b' || !empty($holder->invoice_id) || $holder->direction !== 'in') {
            return false;
        }
        try {
            Capsule::table('flexpay_transactions')->where('id', $holder->id)->whereNull('invoice_id')->delete();
            Capsule::table('flexpay_unmatched_payments')->where('trans_id', $holder->mpesa_receipt)->where('matched', 0)->update([
                'matched'    => 1,
                'matched_by' => 'system (duplicate of STK)',
                'notes'      => substr('Same payment as STK request ' . $checkoutId . ' — recorded there.', 0, 255),
                'matched_at' => self::now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * When an STK payment was settled before its receipt was known (using the
     * CheckoutRequestID as the WHMCS transaction ID), record the real receipt
     * on our row and on the WHMCS ledger entry so refunds and searches work.
     */
    public static function attachReceiptToStk(object $row, string $receipt): void
    {
        if (!empty($row->mpesa_receipt) || $receipt === '') {
            return;
        }
        $holder = self::findTransactionByReceipt($receipt);
        if ($holder && (int) $holder->id !== (int) $row->id && !self::absorbDuplicateReceipt($holder, (string) $row->checkout_request_id)) {
            return;
        }
        try {
            Capsule::table('flexpay_transactions')->where('id', $row->id)->update(['mpesa_receipt' => $receipt, 'updated_at' => self::now()]);
            Capsule::table('tblaccounts')
                ->where('transid', (string) $row->checkout_request_id)
                ->where('gateway', self::GATEWAY)
                ->update(['transid' => $receipt]);
        } catch (\Throwable $e) {
            self::activity('could not attach receipt ' . $receipt . ' — ' . $e->getMessage());
        }
    }

    /** Record an STK failure without ever downgrading a settled payment. */
    public static function markStkFailed(string $checkoutId, string $resultCode, string $resultDesc, ?string $raw = null): void
    {
        self::ensureTables();
        try {
            $data = [
                'status'      => 'failed',
                'result_code' => substr($resultCode, 0, 20),
                'result_desc' => substr($resultDesc, 0, 255),
                'updated_at'  => self::now(),
            ];
            if ($raw !== null) {
                $data['raw_response'] = $raw;
            }
            Capsule::table('flexpay_transactions')
                ->where('checkout_request_id', $checkoutId)
                ->where('status', 'pending')
                ->update($data);
        } catch (\Throwable $e) {
            self::activity('markStkFailed failed — ' . $e->getMessage());
        }
    }

    /**
     * Safaricom also sends a C2B confirmation for STK payments made to a
     * paybill/till. Find the STK push a C2B confirmation belongs to, so the
     * money is applied once (with the real receipt), not twice.
     *
     * RIGID: when the C2B carries an account reference it must equal the
     * STK push's own AccountReference exactly (Safaricom echoes it for
     * paybill STK). Only a reference-less C2B (Till echo) may be linked by
     * payer phone + amount. A manual paybill payment typed with a different
     * reference is never attached to someone's pending STK push.
     */
    public static function findStkForC2B(float $amount, string $billRef, string $msisdn, int $windowSeconds = 900): ?object
    {
        self::ensureTables();
        try {
            $rows = Capsule::table('flexpay_transactions')
                ->where('channel', 'stk')
                ->whereNotNull('checkout_request_id')
                ->whereNull('mpesa_receipt')
                ->whereIn('status', ['pending', 'success', 'failed'])
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - $windowSeconds))
                ->whereBetween('amount', [$amount - 0.009, $amount + 0.009])
                ->orderBy('created_at', 'desc')
                ->take(10)
                ->get();
        } catch (\Throwable $e) {
            return null;
        }

        $ref = self::normalizeReference($billRef);
        foreach ($rows as $row) {
            if ($ref !== '') {
                if ($ref === self::normalizeReference((string) $row->account_reference)) {
                    return $row;
                }
            } elseif ($msisdn !== '' && self::phoneMatches((string) $row->phone, $msisdn)) {
                return $row;
            }
        }
        return null;
    }

    public static function deleteTransaction(int $id): void
    {
        try {
            Capsule::table('flexpay_transactions')->where('id', $id)->delete();
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public static function updateTransaction(int $id, array $data): void
    {
        $data = self::normalizeIdentifiers($data);
        $data['updated_at'] = self::now();
        try {
            Capsule::table('flexpay_transactions')->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            self::activity('updateTransaction failed — ' . $e->getMessage());
        }
    }

    public static function touchChecked(int $rowId): void
    {
        try {
            Capsule::table('flexpay_transactions')->where('id', $rowId)->update(['last_checked_at' => self::now()]);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /** Pending STK rows due for a live status check (cron sweeper). */
    public static function listStalePendingStk(int $olderThanSeconds, int $recheckAfterSeconds, int $limit): array
    {
        self::ensureTables();
        try {
            return self::rows(Capsule::table('flexpay_transactions')
                ->where('channel', 'stk')
                ->where('status', 'pending')
                ->whereNotNull('checkout_request_id')
                ->where('created_at', '<=', date('Y-m-d H:i:s', time() - $olderThanSeconds))
                ->where(function ($q) use ($recheckAfterSeconds) {
                    $q->whereNull('last_checked_at')
                      ->orWhere('last_checked_at', '<=', date('Y-m-d H:i:s', time() - $recheckAfterSeconds));
                })
                ->orderBy('created_at', 'asc')
                ->take($limit)
                ->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // C2B matching
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalise an account reference for comparison: upper-case, with the
     * separators customers commonly type (spaces, "-", "_", "#", ".", "/",
     * ":") removed. "inv - 0042" → "INV0042".
     */
    public static function normalizeReference(string $ref): string
    {
        return strtoupper(preg_replace('/[\s\-_#.\/:]+/', '', trim($ref)));
    }

    /**
     * RIGID parse of an account reference into an invoice ID.
     *
     * Only these shapes are accepted — the whole reference must be the
     * invoice number, with nothing else in it:
     *   "{PREFIX}-42", "{prefix}42", "{PREFIX} 0042", "#42"  → 42
     *   "42", "0042"                                        → 42   (only if $allowBare)
     * Anything else — extra words or numbers ("INV-42-A", "INV 23 and 24",
     * "ACC 2 PLAN 7"), a phone number, an empty reference — returns null.
     * (Before v3.6 any run of digits anywhere in the reference was used,
     * which could credit the wrong invoice.)
     */
    public static function parseInvoiceRef(string $ref, string $prefix = 'INV', bool $allowBare = true): ?int
    {
        $norm   = self::normalizeReference($ref);
        $prefix = self::normalizeReference($prefix);

        if ($norm === '') {
            return null;
        }

        $digits = null;
        if ($prefix !== '' && strncmp($norm, $prefix, strlen($prefix)) === 0 && ctype_digit(substr($norm, strlen($prefix)))) {
            $digits = substr($norm, strlen($prefix));
        } elseif ($allowBare && ctype_digit($norm)) {
            // A phone number typed as the account number is not an invoice ID.
            if (strlen($norm) >= 9 && DarajaClient::formatPhone($norm) !== '') {
                return null;
            }
            $digits = $norm;
        }

        if ($digits === null || $digits === '') {
            return null;
        }
        $digits = ltrim($digits, '0');
        if ($digits === '' || strlen($digits) > 10) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * Resolve an account reference to exactly one WHMCS invoice.
     *
     * Candidates: the invoice ID parsed by parseInvoiceRef(), and any invoice
     * whose WHMCS invoice number (tblinvoices.invoicenum — used when Custom /
     * Sequential Invoice Numbering is on, and printed on invoice emails)
     * equals the normalised reference. If the two point at different
     * invoices the reference is AMBIGUOUS and nothing is applied.
     *
     * @return array{invoice: ?object, reason: string, candidate: ?int}
     *   reason: matched | no_reference | unrecognised | not_found | ambiguous | not_open
     */
    public static function resolveInvoiceReference(string $ref, array $gw): array
    {
        $norm = self::normalizeReference($ref);
        if ($norm === '' || preg_match('/^0+$/', $norm)) {
            return ['invoice' => null, 'reason' => 'no_reference', 'candidate' => null];
        }

        $ids = [];

        $parsed = self::parseInvoiceRef($ref, (string) ($gw['accountRefPrefix'] ?? 'INV'), ($gw['acceptBareInvoiceNumber'] ?? 'on') === 'on');
        if ($parsed !== null && self::getInvoice($parsed)) {
            $ids[$parsed] = true;
        }

        // Match WHMCS's own invoice number exactly (normalised both sides).
        if (strlen($norm) <= 60) {
            try {
                $query = Capsule::table('tblinvoices')->where('invoicenum', '!=', '');
                $raw   = trim($ref);
                $query->where(function ($q) use ($raw, $norm) {
                    $q->where('invoicenum', $raw)->orWhere('invoicenum', $norm);
                });
                foreach ($query->take(5)->pluck('id') as $id) {
                    $ids[(int) $id] = true;
                }
            } catch (\Throwable $e) {
                // column missing on very old WHMCS — ID matching still works
            }
        }

        if (count($ids) === 0) {
            return ['invoice' => null, 'reason' => $parsed !== null ? 'not_found' : 'unrecognised', 'candidate' => null];
        }
        if (count($ids) > 1) {
            return ['invoice' => null, 'reason' => 'ambiguous', 'candidate' => null];
        }

        $invoice = self::getInvoice((int) array_key_first($ids));
        if (!$invoice) {
            return ['invoice' => null, 'reason' => 'not_found', 'candidate' => null];
        }
        if (!in_array($invoice->status, self::OPEN_INVOICE_STATUSES, true)) {
            return ['invoice' => null, 'reason' => 'not_open', 'candidate' => (int) $invoice->id];
        }

        return ['invoice' => $invoice, 'reason' => 'matched', 'candidate' => (int) $invoice->id];
    }

    /**
     * Open invoices with their outstanding balance converted to KES.
     *
     * @return array<int, array{id:int, userid:int, balance_kes:float, duedate:string, phone:string}>
     */
    public static function listOpenInvoicesWithBalance(int $limit = 1000): array
    {
        try {
            $rows = Capsule::table('tblinvoices as i')
                ->leftJoin('tblclients as c', 'c.id', '=', 'i.userid')
                ->whereIn('i.status', self::OPEN_INVOICE_STATUSES)
                ->select('i.id', 'i.userid', 'i.total', 'i.duedate', 'c.currency', 'c.phonenumber')
                ->selectRaw('(i.total - COALESCE((SELECT SUM(a.amountin - a.amountout) FROM tblaccounts a WHERE a.invoiceid = i.id), 0)) AS fp_balance')
                ->orderBy('i.duedate', 'desc')
                ->take($limit)
                ->get();
        } catch (\Throwable $e) {
            return [];
        }

        $kes        = self::getCurrencyByCode('KES');
        $currencies = [];
        $out        = [];

        foreach ($rows as $r) {
            $balance = round((float) $r->fp_balance, 2);
            if ($balance <= 0.009) {
                continue;
            }
            $cid = (int) $r->currency;
            if (!array_key_exists($cid, $currencies)) {
                try {
                    $currencies[$cid] = Capsule::table('tblcurrencies')->where('id', $cid)->first(['id', 'code', 'prefix', 'rate']);
                } catch (\Throwable $e) {
                    $currencies[$cid] = null;
                }
            }
            $cur = $currencies[$cid];
            if ($cur && strtoupper((string) $cur->code) !== 'KES') {
                $balance = $kes ? self::convertAmount($balance, $cur, $kes) : -1.0; // -1: can't compare
            }
            $out[] = [
                'id'          => (int) $r->id,
                'userid'      => (int) $r->userid,
                'balance_kes' => $balance,
                'duedate'     => (string) $r->duedate,
                'phone'       => (string) $r->phonenumber,
            ];
        }

        return $out;
    }

    /**
     * Does a Daraja-reported MSISDN match a client's stored phone number?
     * Handles full numbers, masked ones ("2547******123") and the SHA-256
     * hashed MSISDN that C2B v2 callbacks carry.
     */
    public static function phoneMatches(string $clientPhone, string $observed): bool
    {
        $full = DarajaClient::formatPhone($clientPhone);
        if ($full === '') {
            return false;
        }

        $observed = trim(explode(' - ', $observed)[0]);
        if ($observed === '') {
            return false;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $observed)) {
            return hash_equals(strtolower($observed), hash('sha256', $full));
        }

        $o = preg_replace('/[^0-9*]/', '', $observed);
        if (strlen($o) === 10 && $o[0] === '0') {
            $o = '254' . substr($o, 1);
        } elseif (strlen($o) === 9) {
            $o = '254' . $o;
        }
        if (strlen($o) !== 12 || strlen(str_replace('*', '', $o)) < 6) {
            return false;
        }

        return (bool) preg_match('/^' . str_replace('*', '\d', $o) . '$/', $full);
    }

    /**
     * Decide which invoice (if any) a C2B payment belongs to — RIGIDLY.
     *
     * A payment is auto-applied ONLY when its account reference resolves to
     * exactly one open invoice (see resolveInvoiceReference()). A payment
     * whose reference is missing, different, ambiguous, or points at a
     * paid/cancelled invoice is NEVER applied automatically — whatever its
     * amount or payer phone — and goes to the Reconciliation queue.
     *
     * Before v3.6, payments with a non-matching reference could still be
     * applied when the payer's phone or the amount matched an open invoice,
     * which marked invoices paid by unrelated payments of the same amount.
     * Phone/amount now only produce a *suggestion* for the human reconciler.
     *
     * @return array ['invoice_id' => ?int, 'method' => string, 'note' => string, 'suggested' => ?int]
     */
    public static function matchC2BInvoice(float $amountKes, string $billRef, string $msisdn, string $transTime, array $gw): array
    {
        $billRef  = trim($billRef);
        $resolved = self::resolveInvoiceReference($billRef, $gw);

        if ($resolved['reason'] === 'matched') {
            return ['invoice_id' => (int) $resolved['invoice']->id, 'method' => 'account_reference', 'note' => '', 'suggested' => null];
        }

        $notes = [
            'no_reference' => 'No account reference (typical of Till/Buy Goods payments) — not applied automatically.',
            'unrecognised' => "Reference \"{$billRef}\" is not an invoice number — not applied automatically.",
            'not_found'    => "Reference \"{$billRef}\" looks like an invoice number but no such invoice exists.",
            'ambiguous'    => "Reference \"{$billRef}\" matches more than one invoice (ID and invoice number) — not applied automatically.",
            'not_open'     => "Reference \"{$billRef}\" is Invoice #{$resolved['candidate']}, which is not awaiting payment.",
        ];
        $note      = $notes[$resolved['reason']] ?? 'Not applied automatically.';
        $suggested = $resolved['candidate'];

        // Suggestions only — never applied without a human.
        if ($suggested === null) {
            $open = self::listOpenInvoicesWithBalance();
            $byPhone = $msisdn === '' ? [] : array_values(array_filter($open, function ($inv) use ($amountKes, $msisdn) {
                return abs($inv['balance_kes'] - $amountKes) < 1.0 && self::phoneMatches($inv['phone'], $msisdn);
            }));
            if (count($byPhone) === 1) {
                $suggested = $byPhone[0]['id'];
                $note .= " Suggestion: the payer's phone and the amount match open Invoice #{$suggested}.";
            } else {
                $byAmount = self::pickUniqueAmountMatch($open, $amountKes, $transTime);
                if ($byAmount !== null) {
                    $suggested = $byAmount;
                    $note .= " Suggestion: the amount matches open Invoice #{$suggested} (amount only — verify before applying).";
                }
            }
        }

        return ['invoice_id' => null, 'method' => 'none', 'note' => $note, 'suggested' => $suggested];
    }

    /**
     * Exact-amount matching with due-date disambiguation; null when ambiguous.
     *
     * @param array $open  From listOpenInvoicesWithBalance()
     */
    public static function pickUniqueAmountMatch(array $open, float $amountKes, string $transTime): ?int
    {
        $candidates = array_values(array_filter($open, function ($inv) use ($amountKes) {
            return $inv['balance_kes'] >= 0 && abs($inv['balance_kes'] - $amountKes) < 0.01;
        }));

        if (count($candidates) === 1) {
            return $candidates[0]['id'];
        }
        if (count($candidates) === 0 || !preg_match('/^\d{14}$/', $transTime)) {
            return null;
        }

        $txnDate = \DateTime::createFromFormat('!Ymd', substr($transTime, 0, 8));
        if (!$txnDate) {
            return null;
        }

        $best = null;
        $tied = [];
        foreach ($candidates as $inv) {
            $due = \DateTime::createFromFormat('!Y-m-d', substr($inv['duedate'], 0, 10));
            if (!$due) {
                continue;
            }
            $diff = (int) $txnDate->diff($due)->days;
            if ($best === null || $diff < $best) {
                $best = $diff;
                $tied = [$inv['id']];
            } elseif ($diff === $best) {
                $tied[] = $inv['id'];
            }
        }

        return count($tied) === 1 ? $tied[0] : null;
    }

    /**
     * Open invoices this amount could plausibly be a PARTIAL payment toward.
     * Informational only — shown to the human reconciling it.
     */
    public static function detectPossiblePartials(float $amountKes, string $msisdn = '', int $max = 5): array
    {
        if ($amountKes <= 0) {
            return [];
        }
        $out = [];
        foreach (self::listOpenInvoicesWithBalance() as $inv) {
            if ($inv['balance_kes'] > $amountKes) {
                $inv['phone_match'] = $msisdn !== '' && self::phoneMatches($inv['phone'], $msisdn);
                $out[] = $inv;
            }
        }
        // Invoices belonging to the payer first.
        usort($out, function ($a, $b) {
            return (int) $b['phone_match'] - (int) $a['phone_match'];
        });
        return array_slice($out, 0, $max);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reconciliation / orphan application
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Apply a successful-but-unlinked transaction to an invoice (admin
     * Verify tool, customer self-verify, status-query results).
     */
    public static function applyOrphanedTransaction($transaction, int $invoiceId, string $actor): array
    {
        self::ensureTables();

        if (empty($transaction->mpesa_receipt)) {
            return ['success' => false, 'message' => 'This transaction has no M-Pesa receipt number recorded — cannot safely apply it to an invoice.'];
        }
        if (!empty($transaction->invoice_id)) {
            return ['success' => false, 'message' => 'This payment is already applied to Invoice #' . (int) $transaction->invoice_id . '.'];
        }
        if ($transaction->status !== 'success' || $transaction->direction !== 'in') {
            return ['success' => false, 'message' => 'Only successful incoming payments can be applied to an invoice.'];
        }

        // Claim first so two admins (or an admin and a customer) can't both apply it.
        $claimed = Capsule::table('flexpay_transactions')
            ->where('id', $transaction->id)
            ->whereNull('invoice_id')
            ->update(['invoice_id' => $invoiceId, 'updated_at' => self::now()]);
        if ($claimed !== 1) {
            return ['success' => false, 'message' => 'This payment was just applied elsewhere.'];
        }

        $apply = self::applyPaymentToInvoice($invoiceId, (string) $transaction->mpesa_receipt, (float) $transaction->amount);

        if (!$apply['applied']) {
            Capsule::table('flexpay_transactions')->where('id', $transaction->id)->update(['invoice_id' => null]);
            return ['success' => false, 'message' => 'Failed to apply payment: ' . $apply['message']];
        }

        Capsule::table('flexpay_transactions')->where('id', $transaction->id)->update([
            'payment_outcome' => $apply['outcome']['outcome'] ?? null,
            'result_desc'     => substr($apply['message'], 0, 255),
            'updated_at'      => self::now(),
        ]);
        self::markUnmatchedResolved((string) $transaction->mpesa_receipt, $invoiceId, $actor);
        self::logApiCall('apply_payment', ['receipt' => $transaction->mpesa_receipt, 'invoice_id' => $invoiceId], ['message' => $apply['message']], true, $actor);
        self::adminActivity("M-Pesa payment {$transaction->mpesa_receipt} (KES " . number_format((float) $transaction->amount, 2) . ") applied to Invoice #{$invoiceId} via {$actor}", self::invoiceClientId($invoiceId));

        return ['success' => true, 'message' => $apply['message'], 'outcome' => $apply['outcome']];
    }

    /**
     * Customer self-service verification for ONE invoice (verify.php).
     *
     * Security model:
     *   - The caller has already proven, with a signed invoice token, that
     *     WHMCS showed them this invoice.
     *   - Lookups are by M-Pesa RECEIPT NUMBER ONLY — the one value only the
     *     payer knows (it's in their confirmation SMS). v3.4 also matched
     *     account references and checkout IDs, which let anyone who guessed
     *     a common reference ("RENT", a name) claim someone else's
     *     unmatched payment onto their own invoice.
     *   - A receipt that belongs to another invoice is reported as "not
     *     found" — never confirmed or described.
     *   - Every attempt is rate-limited per invoice AND per IP; the live
     *     Daraja query path has a tighter limit.
     */
    public static function verifyPaymentForInvoice(string $reference, int $invoiceId, string $clientIp = ''): array
    {
        self::ensureTables();

        $notFound = 'We could not find that payment yet. If you just paid, please wait a moment and try again, or contact support with your receipt number.';

        $receipt = FlexPaySecurity::normalizeReceipt($reference);
        if ($receipt === null || $invoiceId <= 0) {
            return ['success' => false, 'applied' => false, 'message' => 'Enter the 10-character M-Pesa receipt number from your confirmation SMS (e.g. NLJ7RT61SV).'];
        }

        if (!FlexPaySecurity::rateLimit('verify_inv_' . $invoiceId, 10, 600)
            || ($clientIp !== '' && !FlexPaySecurity::rateLimit('verify_ip_' . $clientIp, 20, 600))) {
            return ['success' => false, 'applied' => false, 'message' => 'Too many verification attempts. Please wait a few minutes and try again, or contact support.'];
        }

        $local = self::findTransactionByReceipt($receipt);

        if ($local) {
            if ($local->invoice_id && (int) $local->invoice_id !== $invoiceId) {
                return ['success' => false, 'applied' => false, 'message' => 'We could not find that receipt for this invoice. Double-check the receipt number and try again.'];
            }

            if ($local->status === 'success' && $local->direction === 'in') {
                if ($local->invoice_id) {
                    return ['success' => true, 'applied' => false, 'already' => true, 'message' => 'This payment has already been applied to this invoice. If the page hasn\'t updated yet, refresh it.'];
                }

                if (!self::selfVerifyApplies()) {
                    self::flagForInvoice($local, $invoiceId, 'Customer on Invoice #' . $invoiceId . ' submitted this receipt via "Verify your payment".');
                    return [
                        'success' => true, 'applied' => false, 'pending' => true,
                        'message' => 'Thank you — we found your payment. It was made with a different account reference, so our team will confirm it and apply it to this invoice shortly.',
                    ];
                }

                $result = self::applyOrphanedTransaction($local, $invoiceId, 'customer_self_verify');
                return [
                    'success' => $result['success'], 'applied' => $result['success'],
                    'message' => $result['success']
                        ? $result['message']
                        : 'We found your payment but could not apply it automatically. Please contact support with your receipt number.',
                ];
            }

            if ($local->status === 'pending') {
                return ['success' => false, 'applied' => false, 'message' => 'This payment is still being processed. Please wait a few seconds and try again.'];
            }

            return ['success' => false, 'applied' => false, 'message' => 'That payment did not complete successfully. Please try paying again.'];
        }

        // Nothing on file — ask Safaricom (costs an API call, so tighter limit).
        if (!FlexPaySecurity::rateLimit('verify_live_inv_' . $invoiceId, 3, 600)) {
            return ['success' => false, 'applied' => false, 'message' => $notFound];
        }

        $gw = self::getFlexPayGatewayParams();
        $initiator = DarajaClient::initiatorCredentials($gw);
        if (empty($gw['type']) || $initiator === null) {
            return ['success' => false, 'applied' => false, 'message' => $notFound];
        }

        try {
            $client    = DarajaClient::fromGatewayParams($gw);
            $systemUrl = self::systemUrl($gw);

            $response = $client->transactionStatus([
                'initiatorName'      => $initiator['name'],
                'securityCredential' => $initiator['credential'],
                'transactionId'      => $receipt,
                'partyA'             => DarajaClient::c2bShortcode($gw),
                'identifierType'     => '4',
                'remarks'            => 'Self verify inv ' . $invoiceId,
                'resultUrl'          => FlexPaySecurity::callbackUrl($systemUrl, 'status_result'),
                'timeoutUrl'         => FlexPaySecurity::callbackUrl($systemUrl, 'status_timeout'),
            ]);

            $accepted = DarajaClient::isAccepted($response);
            self::logApiCall('customer_verify_status_query', ['reference' => $receipt, 'invoice_id' => $invoiceId], $response, $accepted, 'customer_self_verify');

            if ($accepted) {
                self::storeStatusQueryContext($response, [
                    'purpose' => 'customer_verify', 'invoice_id' => $invoiceId, 'receipt' => $receipt,
                ]);
            }

            return [
                'success' => $accepted, 'applied' => false, 'pending' => $accepted,
                'message' => $accepted
                    ? 'We\'re confirming that payment with Safaricom now. This page will update automatically once it\'s confirmed.'
                    : $notFound,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'applied' => false, 'message' => $notFound];
        }
    }

    /**
     * Does customer self-verification apply payments itself ("apply"), or
     * only flag them for staff approval ("queue", the rigid default)?
     */
    public static function selfVerifyApplies(?array $gw = null): bool
    {
        $gw = $gw ?? self::getFlexPayGatewayParams();
        return ($gw['selfVerifyMode'] ?? 'queue') === 'apply';
    }

    /** Point an unapplied payment's Reconciliation entry at an invoice (suggestion only). */
    public static function flagForInvoice(object $transaction, int $invoiceId, string $note): void
    {
        $transId = (string) $transaction->mpesa_receipt;
        try {
            $entry = Capsule::table('flexpay_unmatched_payments')->where('trans_id', $transId)->first();
            if (!$entry) {
                self::storeUnmatched([
                    'trans_id' => $transId, 'amount' => (float) $transaction->amount, 'phone' => (string) $transaction->phone,
                    'bill_ref' => (string) $transaction->account_reference, 'notes' => $note, 'suggested_invoice_id' => $invoiceId,
                ]);
                return;
            }
            if (!$entry->matched) {
                Capsule::table('flexpay_unmatched_payments')->where('id', $entry->id)->update([
                    'suggested_invoice_id' => $invoiceId,
                    'notes'                => substr(trim($note . ' ' . (string) $entry->notes), 0, 255),
                ]);
            }
        } catch (\Throwable $e) {
            self::activity('flagForInvoice failed — ' . $e->getMessage());
        }
    }

    /** Clear a customer's self-verify rate limits for one invoice (admin tool). */
    public static function clearVerifyRateLimit(int $invoiceId): void
    {
        FlexPaySecurity::clearRateLimit('verify_inv_' . $invoiceId);
        FlexPaySecurity::clearRateLimit('verify_live_inv_' . $invoiceId);
        self::deleteSetting('verify_rl_invoice_' . $invoiceId); // pre-3.5 key
    }

    /**
     * Remember why an async Transaction Status Query was sent, keyed by the
     * IDs Daraja will echo back in the result callback.
     */
    public static function storeStatusQueryContext(array $response, array $context): void
    {
        $context['created'] = time();
        foreach (['ConversationID', 'OriginatorConversationID'] as $k) {
            if (!empty($response[$k])) {
                self::setSetting('sq_' . substr(hash('sha256', (string) $response[$k]), 0, 40), json_encode($context));
            }
        }
    }

    public static function takeStatusQueryContext(array $result): ?array
    {
        foreach (['ConversationID', 'OriginatorConversationID'] as $k) {
            if (empty($result[$k])) {
                continue;
            }
            $key = 'sq_' . substr(hash('sha256', (string) $result[$k]), 0, 40);
            $ctx = json_decode((string) self::getSetting($key, ''), true);
            if (is_array($ctx)) {
                self::deleteSetting($key);
                return $ctx;
            }
        }
        return null;
    }

    /**
     * Username of the logged-in WHMCS admin, for audit trails. WHMCS keeps
     * only the admin ID in the session, not the username.
     */
    public static function currentAdminUsername(string $fallback = 'system'): string
    {
        if (!empty($_SESSION['adminusername']) && is_string($_SESSION['adminusername'])) {
            return $_SESSION['adminusername'];
        }
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        if ($adminId <= 0) {
            return $fallback;
        }
        try {
            $name = Capsule::table('tbladmins')->where('id', $adminId)->value('username');
            return $name ? (string) $name : 'admin#' . $adminId;
        } catch (\Throwable $e) {
            return 'admin#' . $adminId;
        }
    }

    public static function systemUrl(array $gw = []): string
    {
        $url = (string) ($gw['systemurl'] ?? '');
        if ($url === '' && class_exists('App')) {
            try {
                $url = (string) \App::getSystemURL();
            } catch (\Throwable $e) {
                $url = '';
            }
        }
        return rtrim($url, '/');
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_unmatched_payments
    // ─────────────────────────────────────────────────────────────────────

    public static function storeUnmatched(array $data): void
    {
        self::ensureTables();
        $data['created_at'] = self::now();
        $data['matched']    = $data['matched'] ?? 0;
        foreach (['notes' => 255, 'bill_ref' => 50, 'customer_name' => 150, 'phone' => 64, 'trans_id' => 30] as $col => $max) {
            if (isset($data[$col])) {
                $data[$col] = substr((string) $data[$col], 0, $max);
            }
        }

        try {
            if (!Capsule::table('flexpay_unmatched_payments')->where('trans_id', $data['trans_id'])->exists()) {
                Capsule::table('flexpay_unmatched_payments')->insert($data);
            }
        } catch (\Throwable $e) {
            self::activity('storeUnmatched failed — ' . $e->getMessage());
        }
    }

    public static function listUnmatched(bool $onlyUnmatched = true): array
    {
        self::ensureTables();
        try {
            $query = Capsule::table('flexpay_unmatched_payments');
            if ($onlyUnmatched) {
                $query->where('matched', 0);
            }
            return self::rows($query->orderBy('created_at', 'desc')->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function countUnmatched(): int
    {
        self::ensureTables();
        try {
            return (int) Capsule::table('flexpay_unmatched_payments')->where('matched', 0)->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function markUnmatchedResolved(string $transId, ?int $invoiceId, string $actor): void
    {
        try {
            Capsule::table('flexpay_unmatched_payments')->where('trans_id', $transId)->where('matched', 0)->update([
                'matched'            => 1,
                'matched_invoice_id' => $invoiceId,
                'matched_by'         => substr($actor, 0, 100),
                'matched_at'         => self::now(),
            ]);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Admin: apply an unmatched payment to a chosen invoice.
     */
    public static function reconcileUnmatched(int $unmatchedId, int $invoiceId, string $adminUsername): array
    {
        self::ensureTables();

        try {
            $row = Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->first();
            if (!$row) {
                return ['success' => false, 'message' => 'Unmatched payment record not found.'];
            }
            if ($row->matched) {
                return ['success' => false, 'message' => 'This payment has already been reconciled.'];
            }

            // Claim the row before touching money so a double-submit can't
            // apply it twice.
            $claimed = Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->where('matched', 0)
                ->update(['matched' => 1, 'matched_by' => substr($adminUsername, 0, 100), 'matched_at' => self::now()]);
            if ($claimed !== 1) {
                return ['success' => false, 'message' => 'This payment has already been reconciled.'];
            }

            $apply = self::applyPaymentToInvoice($invoiceId, (string) $row->trans_id, (float) $row->amount);

            if (!$apply['applied']) {
                Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)
                    ->update(['matched' => 0, 'matched_by' => '', 'matched_at' => null]);
                return ['success' => false, 'message' => $apply['message']];
            }

            Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->update(['matched_invoice_id' => $invoiceId]);

            // Link the ledger row too, so the Transactions tab shows the invoice.
            $client = self::getInvoice($invoiceId);
            Capsule::table('flexpay_transactions')
                ->where(function ($q) use ($row) {
                    $q->where('mpesa_receipt', $row->trans_id)->orWhere('checkout_request_id', $row->trans_id);
                })
                ->update([
                    'invoice_id'      => $invoiceId,
                    'client_id'       => $client ? (int) $client->userid : null,
                    'payment_outcome' => $apply['outcome']['outcome'] ?? null,
                    'result_desc'     => substr('Reconciled by ' . $adminUsername . ': ' . $apply['message'], 0, 255),
                    'updated_at'      => self::now(),
                ]);

            self::logApiCall('reconcile', ['trans_id' => $row->trans_id, 'invoice_id' => $invoiceId], ['message' => $apply['message']], true, $adminUsername);
            self::adminActivity("Unmatched M-Pesa payment {$row->trans_id} (KES " . number_format((float) $row->amount, 2) . ") applied to Invoice #{$invoiceId} by {$adminUsername}", self::invoiceClientId($invoiceId));

            return ['success' => true, 'message' => $apply['message'], 'outcome' => $apply['outcome']];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Reconciliation failed: ' . $e->getMessage()];
        }
    }

    /**
     * Admin: record an unmatched payment as CREDIT on a client's account
     * (WHMCS AddTransaction with credit=true) — for money that belongs to a
     * known client but not to any single invoice. The transaction ID is the
     * M-Pesa receipt, so WHMCS's own duplicate check protects against it
     * being credited twice.
     */
    public static function creditUnmatchedToClient(int $unmatchedId, int $clientId, string $adminUsername): array
    {
        self::ensureTables();

        $row = Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->first();
        if (!$row || $row->matched) {
            return ['success' => false, 'message' => 'Payment not found or already resolved.'];
        }

        $client = Capsule::table('tblclients')->where('id', $clientId)->first(['id', 'currency']);
        if (!$client) {
            return ['success' => false, 'message' => "Client #{$clientId} does not exist."];
        }
        if (self::transIdExists((string) $row->trans_id)) {
            return ['success' => false, 'message' => "Transaction {$row->trans_id} is already recorded in WHMCS."];
        }
        if (!function_exists('localAPI')) {
            return ['success' => false, 'message' => 'WHMCS local API is unavailable.'];
        }

        // M-Pesa settles in KES; credit in the client's own currency.
        $amount   = (float) $row->amount;
        $currency = Capsule::table('tblcurrencies')->where('id', $client->currency)->first(['id', 'code', 'prefix', 'rate']);
        if ($currency && strtoupper((string) $currency->code) !== 'KES') {
            $kes = self::getCurrencyByCode('KES');
            if (!$kes) {
                return ['success' => false, 'message' => 'The client is not billed in KES and no KES currency is configured in WHMCS.'];
            }
            $amount = self::convertAmount($amount, $kes, $currency);
        }
        $amount = round($amount, 2);
        if ($amount < 0.01) {
            return ['success' => false, 'message' => 'Amount is too small to credit.'];
        }

        $claimed = Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->where('matched', 0)
            ->update(['matched' => 1, 'matched_by' => substr($adminUsername . ' (credit)', 0, 100), 'matched_at' => self::now()]);
        if ($claimed !== 1) {
            return ['success' => false, 'message' => 'This payment has already been resolved.'];
        }

        $result = localAPI('AddTransaction', [
            'paymentmethod' => self::GATEWAY,
            'userid'        => $clientId,
            'transid'       => (string) $row->trans_id,
            'amountin'      => $amount,
            'credit'        => true,
            'description'   => 'M-Pesa payment ' . $row->trans_id . ' credited to account balance',
        ], $adminUsername !== '' ? $adminUsername : null);

        if (($result['result'] ?? '') !== 'success') {
            Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->update(['matched' => 0, 'matched_by' => '', 'matched_at' => null]);
            return ['success' => false, 'message' => 'WHMCS refused the transaction: ' . ($result['message'] ?? 'unknown error')];
        }

        Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->update([
            'notes' => substr('Credited to client #' . $clientId . ' account. ' . (string) $row->notes, 0, 255),
        ]);
        Capsule::table('flexpay_transactions')->where('mpesa_receipt', $row->trans_id)->update([
            'client_id'   => $clientId,
            'result_desc' => substr('Credited to client #' . $clientId . ' account balance by ' . $adminUsername, 0, 255),
            'updated_at'  => self::now(),
        ]);
        self::logApiCall('credit_client', ['trans_id' => $row->trans_id, 'client_id' => $clientId, 'amount' => $amount], $result, true, $adminUsername);
        self::adminActivity("Unmatched M-Pesa payment {$row->trans_id} credited to client account balance by {$adminUsername}", $clientId);

        $code = $currency ? strtoupper((string) $currency->code) : 'KES';
        return ['success' => true, 'message' => "{$code} " . number_format($amount, 2) . " added to client #{$clientId}'s credit balance."];
    }

    /**
     * Did a payment from the invoice owner's own phone land in Reconciliation
     * while they were on the invoice page? Used ONLY to tell that customer
     * "it arrived, staff will confirm" instead of "waiting" forever — the
     * payment is never applied here.
     *
     * Hardened because it runs on a public endpoint with caller-influenced
     * inputs (the page's `since`, the phone on the caller's own profile):
     *  - look-back is capped at 30 minutes whatever `since` says;
     *  - only an EXACT phone match (full MSISDN or C2B v2 SHA-256 hash)
     *    counts — masked MSISDNs are too collision-prone;
     *  - payments already suggested for another invoice are ignored;
     *  - the caller learns only that "a payment" arrived — never its
     *    receipt, amount or reference;
     *  - the Reconciliation hint tells staff to verify before applying.
     */
    public static function findUnmatchedFromInvoiceOwner(int $invoiceId, ?string $sinceDate): bool
    {
        self::ensureTables();

        $floor = date('Y-m-d H:i:s', time() - 1800);
        $since = ($sinceDate !== null && $sinceDate > $floor) ? $sinceDate : $floor;

        try {
            // Cheap indexed check first: nothing queued recently → done.
            $recent = Capsule::table('flexpay_unmatched_payments')->where('matched', 0)->where('created_at', '>=', $since);
            if (!(clone $recent)->exists()) {
                return false;
            }

            $invoice = self::getInvoice($invoiceId);
            if (!$invoice) {
                return false;
            }
            $phone = DarajaClient::formatPhone((string) Capsule::table('tblclients')->where('id', $invoice->userid)->value('phonenumber'));
            if ($phone === '') {
                return false;
            }
            $hash = hash('sha256', $phone);

            $rows = $recent->where(function ($w) use ($invoiceId) {
                $w->whereNull('suggested_invoice_id')->orWhere('suggested_invoice_id', $invoiceId);
            })->orderBy('created_at', 'desc')->take(500)->get(['id', 'phone', 'suggested_invoice_id', 'notes']);

            foreach ($rows as $row) {
                $observed = strtolower(trim(explode(' - ', (string) $row->phone)[0]));
                $exact    = $observed === $hash || DarajaClient::formatPhone($observed) === $phone;
                if (!$exact) {
                    continue;
                }
                if ($row->suggested_invoice_id === null) {
                    Capsule::table('flexpay_unmatched_payments')->where('id', $row->id)->whereNull('suggested_invoice_id')->update([
                        'suggested_invoice_id' => $invoiceId,
                        'notes'                => substr(trim('Came from the phone number on the client profile of Invoice #' . $invoiceId
                            . ' while that invoice page was open — confirm with the customer before applying. ' . (string) $row->notes), 0, 255),
                    ]);
                }
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }

    /**
     * Self-healing ledger check (cron): every successful INCOMING payment that
     * isn't linked to an invoice must be visible in Reconciliation.
     *
     * A callback that dies mid-way (PHP timeout, fatal error, server restart)
     * after recording the receipt but before queueing it would otherwise make
     * the money invisible — Safaricom's retry sees the receipt and stops.
     *
     *  - Credited in the WHMCS ledger (tblaccounts) but not linked here →
     *    link our row to that invoice (never credit again).
     *  - Not credited and not queued → add to Reconciliation.
     *
     * @return array ['linked' => int, 'queued' => int]
     */
    public static function repairUnqueuedPayments(int $graceSeconds = 300, int $limit = 200, ?string $onlyReceipt = null): array
    {
        self::ensureTables();
        $out = ['linked' => 0, 'queued' => 0, 'errors' => 0];

        try {
            $query = Capsule::table('flexpay_transactions as t')
                ->leftJoin('flexpay_unmatched_payments as u', function ($j) {
                    $j->on('u.trans_id', '=', 't.mpesa_receipt');
                })
                ->where('t.direction', 'in')
                ->where('t.status', 'success')
                ->whereNull('t.invoice_id')
                ->whereNull('t.client_id')
                ->whereNotNull('t.mpesa_receipt')
                ->whereNull('u.id')
                ->where('t.updated_at', '<=', date('Y-m-d H:i:s', time() - $graceSeconds));
            if ($onlyReceipt !== null) {
                $query->where('t.mpesa_receipt', $onlyReceipt);
            }
            $rows = self::rows($query->orderBy('t.id')->take($limit)->get(['t.*']));
        } catch (\Throwable $e) {
            self::activity('repairUnqueuedPayments failed — ' . $e->getMessage());
            return $out;
        }

        foreach ($rows as $row) {
            // One bad row must not stop the rest being repaired.
            try {
                $ledger = Capsule::table('tblaccounts')->where('transid', $row->mpesa_receipt)->first(['invoiceid', 'userid']);
                if ($ledger && (int) ($ledger->invoiceid ?? 0) > 0) {
                    Capsule::table('flexpay_transactions')->where('id', $row->id)->whereNull('invoice_id')->update([
                        'invoice_id'  => (int) $ledger->invoiceid,
                        'client_id'   => self::invoiceClientId((int) $ledger->invoiceid),
                        'result_desc' => substr('Credited to Invoice #' . (int) $ledger->invoiceid . ' (link restored by ledger check).', 0, 255),
                        'updated_at'  => self::now(),
                    ]);
                    $out['linked']++;
                    continue;
                }
                if ($ledger) {
                    // Credited to a client account without an invoice — nothing to queue.
                    continue;
                }

                self::storeUnmatched([
                    'trans_id' => (string) $row->mpesa_receipt,
                    'amount'   => (float) $row->amount,
                    'phone'    => (string) $row->phone,
                    'bill_ref' => (string) $row->account_reference !== '' ? (string) $row->account_reference : '(none)',
                    'notes'    => 'Recovered by the ledger check: this payment was recorded but never queued (interrupted callback). Verify before applying.',
                ]);
                Capsule::table('flexpay_transactions')->where('id', $row->id)->update([
                    'result_desc' => 'No matching invoice found — needs manual reconciliation (recovered).',
                    'updated_at'  => self::now(),
                ]);
                $out['queued']++;
            } catch (\Throwable $e) {
                $out['errors']++;
                self::activity('ledger check failed for ' . $row->mpesa_receipt . ' — ' . $e->getMessage());
            }
        }

        if ($out['linked'] || $out['queued'] || $out['errors']) {
            self::logApiCall('ledger_repair', ['receipt' => $onlyReceipt], $out, $out['errors'] === 0, $onlyReceipt ? 'callback_retry' : 'cron');
        }
        return $out;
    }

    /** Payments in the Reconciliation queue suggested for an invoice. */
    public static function listSuggestedForInvoice(int $invoiceId): array
    {
        self::ensureTables();
        try {
            return self::rows(Capsule::table('flexpay_unmatched_payments')->where('suggested_invoice_id', $invoiceId)->where('matched', 0)
                ->orderBy('created_at', 'desc')->take(10)->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Admin: mark an unmatched payment as not belonging to any invoice. */
    public static function dismissUnmatched(int $unmatchedId, string $adminUsername, string $reason): array
    {
        self::ensureTables();
        try {
            $updated = Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->where('matched', 0)->update([
                'matched'    => 1,
                'matched_by' => substr($adminUsername . ' (dismissed)', 0, 100),
                'notes'      => substr('Dismissed: ' . ($reason !== '' ? $reason : 'no reason given'), 0, 255),
                'matched_at' => self::now(),
            ]);
            if ($updated !== 1) {
                return ['success' => false, 'message' => 'Payment not found or already resolved.'];
            }
            self::logApiCall('dismiss_unmatched', ['id' => $unmatchedId, 'reason' => $reason], ['dismissed' => true], true, $adminUsername);
            $transId = (string) Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->value('trans_id');
            self::adminActivity("Unmatched M-Pesa payment {$transId} dismissed by {$adminUsername}" . ($reason !== '' ? " ({$reason})" : ''));
            return ['success' => true, 'message' => 'Payment dismissed from the reconciliation queue.'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not dismiss payment: ' . $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Listing / stats
    // ─────────────────────────────────────────────────────────────────────

    public static function transactionQuery(array $filters = [])
    {
        $query = Capsule::table('flexpay_transactions');

        if (!empty($filters['channel']) && in_array($filters['channel'], ['stk', 'c2b', 'b2c', 'reversal'], true)) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'success', 'failed', 'reversed'], true)) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['invoice_id'])) {
            $query->where('invoice_id', (int) $filters['invoice_id']);
        }
        if (!empty($filters['search'])) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr((string) $filters['search'], 0, 60));
            $query->where(function ($q) use ($term) {
                $q->where('mpesa_receipt', 'like', "%{$term}%")
                  ->orWhere('phone', 'like', "%{$term}%")
                  ->orWhere('account_reference', 'like', "%{$term}%")
                  ->orWhere('checkout_request_id', 'like', "%{$term}%");
            });
        }
        if (!empty($filters['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
        }
        if (!empty($filters['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
        }

        return $query;
    }

    public static function listTransactions(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        self::ensureTables();

        try {
            $query = self::transactionQuery($filters);
            $total = (clone $query)->count();
            $data  = self::rows($query->orderBy('created_at', 'desc')->orderBy('id', 'desc')
                ->skip((max(1, $page) - 1) * $perPage)
                ->take($perPage)
                ->get());

            return ['data' => $data, 'total' => $total];
        } catch (\Throwable $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    public static function getStats(int $days = 30): array
    {
        self::ensureTables();

        $days  = max(1, min(3650, $days));
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        try {
            $base = Capsule::table('flexpay_transactions')->where('created_at', '>=', $since);

            return [
                'total_in'        => (float) (clone $base)->where('direction', 'in')->where('status', 'success')->sum('amount'),
                'total_out'       => (float) (clone $base)->where('direction', 'out')->where('status', 'success')->sum('amount'),
                'success_count'   => (int) (clone $base)->where('status', 'success')->count(),
                'failed_count'    => (int) (clone $base)->where('status', 'failed')->count(),
                'pending_count'   => (int) (clone $base)->where('status', 'pending')->count(),
                'stk_count'       => (int) (clone $base)->where('channel', 'stk')->where('status', 'success')->count(),
                'c2b_count'       => (int) (clone $base)->where('channel', 'c2b')->where('status', 'success')->count(),
                'unmatched_count' => self::countUnmatched(),
                'period_days'     => $days,
            ];
        } catch (\Throwable $e) {
            return [
                'total_in' => 0, 'total_out' => 0, 'success_count' => 0,
                'failed_count' => 0, 'pending_count' => 0, 'stk_count' => 0,
                'c2b_count' => 0, 'unmatched_count' => 0, 'period_days' => $days,
            ];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_refunds
    // ─────────────────────────────────────────────────────────────────────

    public static function recordRefund(array $data): int
    {
        self::ensureTables();
        $data['created_at'] = self::now();

        try {
            return (int) Capsule::table('flexpay_refunds')->insertGetId($data);
        } catch (\Throwable $e) {
            self::activity('recordRefund failed — ' . $e->getMessage());
            return 0;
        }
    }

    /** Find a refund by the IDs Daraja echoes back in a B2C result. */
    public static function findRefundByIds(string $conversationId, string $originatorId): ?object
    {
        self::ensureTables();
        try {
            $q = Capsule::table('flexpay_refunds');
            if ($conversationId !== '' && $originatorId !== '') {
                $q->where(function ($w) use ($conversationId, $originatorId) {
                    $w->where('conversation_id', $conversationId)->orWhere('originator_conversation_id', $originatorId);
                });
            } elseif ($conversationId !== '') {
                $q->where('conversation_id', $conversationId);
            } elseif ($originatorId !== '') {
                $q->where('originator_conversation_id', $originatorId);
            } else {
                return null;
            }
            return $q->orderBy('id', 'desc')->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getRefund(int $id): ?object
    {
        self::ensureTables();
        try {
            return Capsule::table('flexpay_refunds')->where('id', $id)->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function updateRefund(int $id, array $data): void
    {
        self::ensureTables();
        $data['updated_at'] = self::now();
        if (isset($data['result_desc'])) {
            $data['result_desc'] = substr((string) $data['result_desc'], 0, 255);
        }
        try {
            Capsule::table('flexpay_refunds')->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public static function listRefunds(int $limit = 50): array
    {
        self::ensureTables();
        try {
            return self::rows(Capsule::table('flexpay_refunds')->orderBy('created_at', 'desc')->orderBy('id', 'desc')->take($limit)->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_balance_snapshots
    // ─────────────────────────────────────────────────────────────────────

    public static function recordBalanceSnapshot(array $data): void
    {
        self::ensureTables();
        $data['created_at'] = self::now();
        try {
            Capsule::table('flexpay_balance_snapshots')->insert($data);
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    public static function getLatestBalance(string $shortcode): ?object
    {
        self::ensureTables();
        try {
            return Capsule::table('flexpay_balance_snapshots')->where('shortcode', $shortcode)
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')->first() ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function getBalanceHistory(string $shortcode, int $limit = 30): array
    {
        self::ensureTables();
        try {
            return self::rows(Capsule::table('flexpay_balance_snapshots')->where('shortcode', $shortcode)
                ->orderBy('created_at', 'desc')->orderBy('id', 'desc')->take($limit)->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_api_log
    // ─────────────────────────────────────────────────────────────────────

    /** Keys whose values must never be written to the API log. */
    private const REDACT_KEYS = [
        'password', 'passkey', 'securitycredential', 'consumersecret', 'consumerkey',
        'access_token', 'authorization', 'licensingsecret', 'initiatorpassword', 'localkey', 'secret',
    ];

    public static function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT_KEYS, true)) {
                $data[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $data[$k] = self::redact($v);
            }
        }
        return $data;
    }

    public static function logApiCall(string $operation, $request, $response, bool $success, string $triggeredBy = 'system'): void
    {
        self::ensureTables();

        $encode = function ($v) {
            $s = is_string($v) ? $v : json_encode(self::redact($v), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            return substr((string) $s, 0, 60000);
        };

        try {
            Capsule::table('flexpay_api_log')->insert([
                'operation'     => substr($operation, 0, 40),
                'request_data'  => $encode($request),
                'response_data' => $encode($response),
                'success'       => $success ? 1 : 0,
                'triggered_by'  => substr($triggeredBy, 0, 100),
                'created_at'    => self::now(),
            ]);
        } catch (\Throwable $e) {
            // Never let logging break the main flow
        }
    }

    public static function listApiLog(int $limit = 100, ?string $operation = null, ?bool $success = null): array
    {
        self::ensureTables();
        try {
            $query = Capsule::table('flexpay_api_log');
            if ($operation) {
                $query->where('operation', $operation);
            }
            if ($success !== null) {
                $query->where('success', $success ? 1 : 0);
            }
            return self::rows($query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->take($limit)->get());
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function listApiLogOperations(): array
    {
        self::ensureTables();
        try {
            return self::rows(Capsule::table('flexpay_api_log')->distinct()->orderBy('operation')->pluck('operation'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Housekeeping (cron)
    // ─────────────────────────────────────────────────────────────────────

    /** @return array counts of rows removed */
    public static function prune(int $logRetentionDays): array
    {
        self::ensureTables();
        $out = ['rate_limits' => 0, 'status_contexts' => 0, 'api_log' => 0, 'qr_cache' => 0];
        // Prefix match via SUBSTR, not LIKE 'rl\\_%': backslash escaping in
        // LIKE is MySQL-specific, and an unescaped "_" is a wildcard.
        $olderThan = function (array $prefixes, int $seconds) {
            return Capsule::table('flexpay_settings')
                ->where(function ($q) use ($prefixes) {
                    foreach ($prefixes as $p) {
                        $q->orWhereRaw('SUBSTR(setting_key, 1, ' . strlen($p) . ') = ?', [$p]);
                    }
                })
                ->where('updated_at', '<', date('Y-m-d H:i:s', time() - $seconds))
                ->delete();
        };
        try {
            $out['rate_limits']     = $olderThan(['rl_', 'verify_rl_'], 86400);
            $out['status_contexts'] = $olderThan(['sq_'], 7 * 86400);
            $out['qr_cache']        = $olderThan(['qr_'], 86400);
            if ($logRetentionDays > 0) {
                $out['api_log'] = Capsule::table('flexpay_api_log')
                    ->where('created_at', '<', date('Y-m-d H:i:s', time() - $logRetentionDays * 86400))
                    ->delete();
            }
        } catch (\Throwable $e) {
            self::activity('prune failed — ' . $e->getMessage());
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_settings
    // ─────────────────────────────────────────────────────────────────────

    public static function getSetting(string $key, $default = null)
    {
        self::ensureTables();
        try {
            $row = Capsule::table('flexpay_settings')->where('setting_key', $key)->first();
            return $row ? $row->setting_value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public static function setSetting(string $key, $value): void
    {
        self::ensureTables();
        try {
            $updated = Capsule::table('flexpay_settings')->where('setting_key', $key)->update([
                'setting_value' => $value,
                'updated_at'    => self::now(),
            ]);
            if ($updated === 0 && !Capsule::table('flexpay_settings')->where('setting_key', $key)->exists()) {
                self::addSettingIfAbsent($key, $value);
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /** Insert only if the key doesn't exist yet (primary key makes this atomic). */
    public static function addSettingIfAbsent(string $key, $value): void
    {
        self::ensureTables();
        try {
            Capsule::table('flexpay_settings')->insert([
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_at'    => self::now(),
            ]);
        } catch (\Throwable $e) {
            // already present — that's the point
        }
    }

    public static function deleteSetting(string $key): void
    {
        self::ensureTables();
        try {
            Capsule::table('flexpay_settings')->where('setting_key', $key)->delete();
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Normalise a query result to a plain array. Older Illuminate versions
     * bundled with some WHMCS releases return arrays; newer ones Collections.
     */
    public static function rows($result): array
    {
        if (is_array($result)) {
            return $result;
        }
        return (is_object($result) && method_exists($result, 'all')) ? $result->all() : (array) $result;
    }

    private static function activity(string $message): void
    {
        if (function_exists('logActivity')) {
            logActivity('FlexPay: ' . $message);
        }
    }

    /**
     * Record a manual money action in the WHMCS Activity Log (Utilities →
     * Logs → Activity Log), linked to the client so it also shows on their
     * profile's log. WHMCS stamps the acting admin itself.
     */
    public static function adminActivity(string $message, ?int $clientId = null): void
    {
        if (function_exists('logActivity')) {
            logActivity('FlexPay: ' . $message, (int) ($clientId ?? 0));
        }
    }

    public static function invoiceClientId(int $invoiceId): ?int
    {
        $invoice = self::getInvoice($invoiceId);
        return $invoice ? (int) $invoice->userid : null;
    }
}

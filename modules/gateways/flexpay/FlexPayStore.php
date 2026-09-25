<?php
/**
 * FlexPayStore — shared database access layer.
 *
 * Wraps every read/write touching the flexpay_* tables so both the
 * gateway module and the dashboard addon manipulate data identically.
 * Also owns table auto-creation (idempotent, safe to call every request).
 *
 * @package FlexPay\Daraja
 * @version 3.0.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

class FlexPayStore
{
    /**
     * Ensure getGatewayVariables() and friends (checkCbInvoiceID,
     * addInvoicePayment, etc.) are loaded and callable.
     *
     * WHMCS auto-loads includes/gatewayfunctions.php only for gateway
     * module and gateway callback requests — NOT for addon-module admin
     * pages. Any addon code that calls getGatewayVariables() directly
     * will fatal with "Call to undefined function" unless this is called
     * first. Safe to call repeatedly; does nothing once already loaded.
     *
     * @return void
     */
    public static function ensureGatewayFunctionsLoaded(): void
    {
        if (function_exists('getGatewayVariables')) {
            return;
        }

        if (class_exists('App')) {
            try {
                \App::load_function('gateway');
            } catch (\Throwable $e) {
                // fall through to the direct-require fallback below
            }
        }

        if (!function_exists('getGatewayVariables')) {
            // Fallback for any WHMCS version/context where App::load_function
            // isn't available — require the file directly. This file
            // (FlexPayStore.php) lives at modules/gateways/flexpay/, so
            // reaching WHMCS root's includes/ needs exactly 2 parent steps.
            $fallbackPath = __DIR__ . '/../../../includes/gatewayfunctions.php';
            if (is_file($fallbackPath)) {
                require_once $fallbackPath;
            }
        }
    }

    /**
     * Convenience wrapper: load the gateway function library if needed,
     * then return the FlexPay gateway module's saved configuration.
     * Returns ['type' => ''] (meaning "not active") if the function
     * library could not be loaded at all, so callers can check
     * $gw['type'] exactly as they would from a real gateway-context call.
     *
     * @return array
     */
    public static function getFlexPayGatewayParams(): array
    {
        self::ensureGatewayFunctionsLoaded();

        if (!function_exists('getGatewayVariables')) {
            return ['type' => ''];
        }

        return getGatewayVariables('flexpay');
    }

    /**
     * Idempotently create all flexpay_* tables. Safe to call on every
     * request — exits almost immediately once tables exist.
     *
     * @return void
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

            if (!$schema->hasTable('flexpay_transactions')) {
                $schema->create('flexpay_transactions', function ($table) {
                    /** @var \Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->enum('channel', ['stk', 'c2b', 'b2c', 'reversal']);
                    $table->enum('direction', ['in', 'out'])->default('in');
                    $table->unsignedInteger('invoice_id')->nullable();
                    $table->unsignedInteger('client_id')->nullable();
                    $table->string('checkout_request_id', 100)->default('');
                    $table->string('merchant_request_id', 100)->default('');
                    $table->string('conversation_id', 100)->default('');
                    $table->string('originator_conversation_id', 100)->default('');
                    $table->string('mpesa_receipt', 30)->default('');
                    $table->string('phone', 15)->default('');
                    $table->decimal('amount', 12, 2)->default(0);
                    $table->string('account_reference', 50)->default('');
                    $table->enum('status', ['pending', 'success', 'failed', 'reversed'])->default('pending');
                    $table->string('result_code', 20)->default('');
                    $table->string('result_desc', 255)->default('');
                    $table->string('payment_outcome', 20)->nullable()->comment('exact, partial, overpaid — observational only, see classifyPaymentOutcome()');
                    $table->text('raw_request')->nullable();
                    $table->text('raw_response')->nullable();
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
                });
            }

            if (!$schema->hasTable('flexpay_unmatched_payments')) {
                $schema->create('flexpay_unmatched_payments', function ($table) {
                    /** @var \Illuminate\Database\Schema\Blueprint $table */
                    $table->increments('id');
                    $table->string('trans_id', 30)->unique();
                    $table->decimal('amount', 12, 2);
                    $table->string('phone', 15)->default('');
                    $table->string('bill_ref', 50)->default('');
                    $table->string('customer_name', 150)->default('');
                    $table->text('raw_data')->nullable();
                    $table->boolean('matched')->default(false);
                    $table->unsignedInteger('matched_invoice_id')->nullable();
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
                    $table->string('original_trans_id', 30)->default('');
                    $table->string('conversation_id', 100)->default('');
                    $table->string('phone', 15);
                    $table->decimal('amount', 12, 2);
                    $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
                    $table->string('result_desc', 255)->default('');
                    $table->string('initiated_by', 100)->default('');
                    $table->dateTime('created_at');
                    $table->dateTime('updated_at')->nullable();

                    $table->index('invoice_id');
                    $table->index('status');
                    $table->index('conversation_id');
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

            // ── Additive migrations for installs upgrading from an earlier
            // FlexPay version, where flexpay_transactions already exists
            // but predates the partial/overpayment tracking column. Safe
            // to run on every load — hasColumn() short-circuits instantly
            // once the column exists.
            if ($schema->hasTable('flexpay_transactions') && !$schema->hasColumn('flexpay_transactions', 'payment_outcome')) {
                $schema->table('flexpay_transactions', function ($table) {
                    /** @var \Illuminate\Database\Schema\Blueprint $table */
                    $table->string('payment_outcome', 20)->nullable()->after('result_desc');
                });
            }
        } catch (\Exception $e) {
            if (function_exists('logActivity')) {
                logActivity('FlexPay: table auto-creation failed — ' . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_transactions
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Insert or update a transaction record, keyed by channel-appropriate
     * unique identifier (checkout_request_id for STK, mpesa_receipt for
     * everything else once known).
     *
     * @param  array $data
     * @return void
     */
    public static function recordTransaction(array $data): void
    {
        self::ensureTables();

        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['created_at'] = $data['created_at'] ?? date('Y-m-d H:i:s');

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
                unset($data['created_at']); // never overwrite original creation time
                Capsule::table('flexpay_transactions')->where('id', $existing->id)->update($data);
            } else {
                Capsule::table('flexpay_transactions')->insert($data);
            }
        } catch (\Exception $e) {
            if (function_exists('logActivity')) {
                logActivity('FlexPay: recordTransaction failed — ' . $e->getMessage());
            }
        }
    }

    /**
     * Classify the outcome of applying a payment to an invoice — exact,
     * partial (underpayment), or overpaid — by comparing the invoice's
     * balance BEFORE the payment against the amount paid.
     *
     * IMPORTANT: WHMCS itself has no "Partially Paid" invoice status —
     * an underpaid invoice simply stays Unpaid/Overdue with a reduced
     * balance (see WHMCS\Billing\Invoice — status is one of unpaid,
     * overdue, paid, cancelled, refunded, collections; nothing else).
     * This classifier doesn't fight that model or invent a new status;
     * it's purely an observation layer for FlexPay's own messaging and
     * dashboard display, read from the invoice's real `total`/`balance`
     * fields (the same fields WHMCS's own API exposes).
     *
     * Overpayment handling itself needs NO special code here — WHMCS's
     * addInvoicePayment() already creates the credit note and credits
     * the client's account balance automatically the moment the paid
     * amount exceeds the remaining balance (see WHMCS's own Billing
     * Logic documentation: "Creates a credit note... Credits the
     * difference between the balance and the transaction amount to the
     * client's account... Changes the credit description format to
     * Invoice # Overpayment."). This method's job is only to detect that
     * it happened, so FlexPay can say so clearly instead of leaving it
     * as a silent side effect.
     *
     * @param  int   $invoiceId      The invoice the payment was just applied to
     * @param  float $amountPaid     The exact amount that was just applied
     * @return array  [
     *   'outcome'         => 'exact'|'partial'|'overpaid'|'unknown',
     *   'invoice_total'   => float|null,
     *   'balance_before'  => float|null,
     *   'balance_after'   => float|null,
     *   'remaining'       => float,   // 0 if fully paid or overpaid
     *   'credited'        => float,   // amount sent to client credit balance, 0 if none
     *   'message'         => string,  // human-readable, customer-safe
     * ]
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

        try {
            // Read the CURRENT (post-payment) state — addInvoicePayment()
            // has already run by the time this is called, so `balance`
            // here reflects after-payment. We reconstruct the before-state
            // by adding back the amount that was just paid (capped at the
            // total, since WHMCS never lets balance exceed total).
            $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['total', 'balance', 'status']);

            if (!$invoice) {
                return $result;
            }

            $total        = (float) $invoice->total;
            $balanceAfter = (float) $invoice->balance;
            $balanceBefore = min($total, $balanceAfter + $amountPaid);

            $result['invoice_total']  = $total;
            $result['balance_before'] = $balanceBefore;
            $result['balance_after']  = $balanceAfter;

            if ($balanceAfter > 0.009) {
                // Still owing after this payment — a partial/underpayment.
                $result['outcome']   = 'partial';
                $result['remaining'] = round($balanceAfter, 2);
                $result['message']   = sprintf(
                    'KES %s received and applied. KES %s still due on this invoice.',
                    number_format($amountPaid, 2),
                    number_format($balanceAfter, 2)
                );
            } elseif ($amountPaid > $balanceBefore + 0.009) {
                // Paid more than was owed — WHMCS already auto-created the
                // credit note and credited the client; we just describe it.
                $overage = round($amountPaid - $balanceBefore, 2);
                $result['outcome']  = 'overpaid';
                $result['credited'] = $overage;
                $result['message']  = sprintf(
                    'KES %s received. KES %s applied to this invoice and KES %s credited to your account balance for future use.',
                    number_format($amountPaid, 2),
                    number_format($balanceBefore, 2),
                    number_format($overage, 2)
                );
            } else {
                $result['outcome'] = 'exact';
                $result['message'] = sprintf('KES %s received and this invoice is now fully paid.', number_format($amountPaid, 2));
            }

            return $result;
        } catch (\Exception $e) {
            return $result;
        }
    }

    /**
     * Settle a successful STK Push payment exactly once, regardless of
     * WHICH path discovered the success first — the real-time Daraja
     * callback, or the self-healing live-query fallback in poll.php for
     * cases where the callback was delayed, dropped, or never arrived
     * (a known reliability gap in Safaricom's sandbox, and occasionally
     * production too).
     *
     * This is "intelligent" in the sense your invoice always gets linked
     * the same way regardless of Till (Buy Goods) vs Paybill: we never
     * depend on Safaricom echoing back our AccountReference (which Till
     * transactions don't reliably support per Daraja's own docs) — the
     * link to the invoice is established the moment WE create the STK
     * request (checkout.php writes invoice_id against checkout_request_id
     * BEFORE the customer even sees the prompt), so settlement only ever
     * needs the CheckoutRequestID to find its way back to the right
     * invoice, which works identically for both shortcode types.
     *
     * Idempotent: if this checkout_request_id has already been marked
     * successful (e.g. the real callback got there first), calling this
     * again is a safe no-op — it will NOT call addInvoicePayment() twice
     * for the same payment. WHMCS's own checkCbTransID() also guards
     * against double-crediting the same M-Pesa receipt as a second line
     * of defense.
     *
     * @param  string      $checkoutId
     * @param  string      $receipt       M-Pesa receipt number
     * @param  float       $amount
     * @param  string      $phone
     * @param  string      $resultDesc
     * @param  string      $gatewayModuleName
     * @param  string      $source        'callback' or 'live_query_self_heal' — for logging only
     * @return array       ['applied' => bool, 'already_settled' => bool, 'invoice_id' => int|null, 'message' => string]
     */
    public static function settleStkSuccess(
        string $checkoutId,
        string $receipt,
        float $amount,
        string $phone,
        string $resultDesc,
        string $gatewayModuleName,
        string $source = 'callback'
    ): array {
        self::ensureTables();

        try {
            $existing = Capsule::table('flexpay_transactions')
                ->where('checkout_request_id', $checkoutId)
                ->first();

            // Already settled by a previous call (callback got there first,
            // or this is a duplicate/retried Daraja callback) — do nothing.
            if ($existing && $existing->status === 'success') {
                return [
                    'applied'          => false,
                    'already_settled'  => true,
                    'invoice_id'       => $existing->invoice_id ? (int) $existing->invoice_id : null,
                    'message'          => 'Already settled — no action taken.',
                ];
            }

            $invoiceId = $existing ? (int) $existing->invoice_id : null;
            $appliedInvoiceId = null;
            $applyMessage = 'No invoice link found for this checkout request — payment recorded but not auto-applied.';
            $outcome = null;

            if ($invoiceId) {
                try {
                    $normalizedInvoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
                    checkCbTransID($receipt);
                    addInvoicePayment($normalizedInvoiceId, $receipt, $amount, 0, $gatewayModuleName);
                    $appliedInvoiceId = $normalizedInvoiceId;

                    $outcome = self::classifyPaymentOutcome($normalizedInvoiceId, $amount);
                    $applyMessage = $outcome['message'];
                } catch (\Throwable $e) {
                    // checkCbTransID may legitimately die()/throw on a true
                    // duplicate receipt — that means another path already
                    // credited it, which is the safe outcome, not a failure.
                    $applyMessage = 'Invoice application skipped: ' . $e->getMessage();
                }
            }

            self::recordTransaction([
                'channel'             => 'stk',
                'direction'           => 'in',
                'invoice_id'          => $invoiceId,
                'checkout_request_id' => $checkoutId,
                'mpesa_receipt'       => $receipt,
                'phone'               => $phone,
                'amount'              => $amount,
                'status'              => 'success',
                'result_code'         => '0',
                'result_desc'         => ($outcome['message'] ?? $resultDesc) . ($source !== 'callback' ? " [settled via {$source}]" : ''),
                'payment_outcome'     => $outcome['outcome'] ?? null,
            ]);

            FlexPayStore::logApiCall(
                'stk_settlement',
                ['checkout_request_id' => $checkoutId, 'receipt' => $receipt, 'amount' => $amount, 'source' => $source],
                ['invoice_id' => $invoiceId, 'applied_invoice_id' => $appliedInvoiceId, 'message' => $applyMessage, 'outcome' => $outcome['outcome'] ?? null],
                $appliedInvoiceId !== null,
                $source
            );

            return [
                'applied'         => $appliedInvoiceId !== null,
                'already_settled' => false,
                'invoice_id'      => $appliedInvoiceId,
                'message'         => $applyMessage,
                'outcome'         => $outcome,
            ];
        } catch (\Throwable $e) {
            return ['applied' => false, 'already_settled' => false, 'invoice_id' => null, 'message' => 'Settlement error: ' . $e->getMessage()];
        }
    }

    /**
     * Fetch a paginated, optionally-filtered list of transactions for the
     * dashboard's main transaction table.
     *
     * @param  array $filters  channel, status, search (phone/receipt/ref), date_from, date_to
     * @param  int   $page
     * @param  int   $perPage
     * @return array  ['data' => [...], 'total' => int]
     */
    public static function listTransactions(array $filters = [], int $page = 1, int $perPage = 25): array
    {
        self::ensureTables();

        try {
            $query = Capsule::table('flexpay_transactions');

            if (!empty($filters['channel'])) {
                $query->where('channel', $filters['channel']);
            }
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['search'])) {
                $term = $filters['search'];
                $query->where(function ($q) use ($term) {
                    $q->where('mpesa_receipt', 'like', "%{$term}%")
                      ->orWhere('phone', 'like', "%{$term}%")
                      ->orWhere('account_reference', 'like', "%{$term}%");
                });
            }
            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00');
            }
            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59');
            }

            $total = $query->count();

            $data = $query->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return ['data' => $data, 'total' => $total];
        } catch (\Exception $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    /**
     * Aggregate stats for the dashboard summary cards + admin widget.
     *
     * @param  int $days  Lookback window
     * @return array
     */
    public static function getStats(int $days = 30): array
    {
        self::ensureTables();

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        try {
            $base = Capsule::table('flexpay_transactions')->where('created_at', '>=', $since);

            $totalIn = (clone $base)->where('direction', 'in')->where('status', 'success')->sum('amount');
            $totalOut = (clone $base)->where('direction', 'out')->where('status', 'success')->sum('amount');
            $successCount = (clone $base)->where('status', 'success')->count();
            $failedCount  = (clone $base)->where('status', 'failed')->count();
            $pendingCount = (clone $base)->where('status', 'pending')->count();

            $stkCount = (clone $base)->where('channel', 'stk')->where('status', 'success')->count();
            $c2bCount = (clone $base)->where('channel', 'c2b')->where('status', 'success')->count();

            $unmatchedCount = Capsule::table('flexpay_unmatched_payments')->where('matched', 0)->count();

            return [
                'total_in'        => (float) $totalIn,
                'total_out'       => (float) $totalOut,
                'success_count'   => (int) $successCount,
                'failed_count'    => (int) $failedCount,
                'pending_count'   => (int) $pendingCount,
                'stk_count'       => (int) $stkCount,
                'c2b_count'       => (int) $c2bCount,
                'unmatched_count' => (int) $unmatchedCount,
                'period_days'     => $days,
            ];
        } catch (\Exception $e) {
            return [
                'total_in' => 0, 'total_out' => 0, 'success_count' => 0,
                'failed_count' => 0, 'pending_count' => 0, 'stk_count' => 0,
                'c2b_count' => 0, 'unmatched_count' => 0, 'period_days' => $days,
            ];
        }
    }

    /**
     * Look up a single transaction by ANY of: M-Pesa receipt number,
     * STK checkout request ID, or account reference (e.g. "INV-42").
     * Used by the dashboard's "Verify Payment" tool to check whether a
     * payment a customer claims to have made actually exists on file,
     * before resorting to a live Daraja query.
     *
     * @param  string $reference
     * @return object|null
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
                ->where('mpesa_receipt', $reference)
                ->orWhere('checkout_request_id', $reference)
                ->orWhere('account_reference', $reference)
                ->orderBy('created_at', 'desc')
                ->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Apply a transaction that's recorded locally as successful, but was
     * never linked to a WHMCS invoice, to a specific invoice — exactly
     * the same WHMCS-native path (checkCbInvoiceID → checkCbTransID →
     * addInvoicePayment) used by automatic reconciliation, just triggered
     * manually from the "Verify Payment" tool instead of a callback.
     *
     * @param  object $transaction        Row from flexpay_transactions
     * @param  int    $invoiceId
     * @param  string $adminUsername
     * @param  string $gatewayModuleName
     * @return array  ['success' => bool, 'message' => string]
     */
    public static function applyOrphanedTransaction($transaction, int $invoiceId, string $adminUsername, string $gatewayModuleName): array
    {
        self::ensureTables();

        if (empty($transaction->mpesa_receipt)) {
            return ['success' => false, 'message' => 'This transaction has no M-Pesa receipt number recorded — cannot safely apply it to an invoice.'];
        }

        try {
            $normalizedInvoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
            checkCbTransID($transaction->mpesa_receipt);
            addInvoicePayment($normalizedInvoiceId, $transaction->mpesa_receipt, (float) $transaction->amount, 0, $gatewayModuleName);

            $outcome = self::classifyPaymentOutcome($normalizedInvoiceId, (float) $transaction->amount);

            Capsule::table('flexpay_transactions')
                ->where('id', $transaction->id)
                ->update([
                    'invoice_id'      => $normalizedInvoiceId,
                    'payment_outcome' => $outcome['outcome'],
                    'updated_at'      => date('Y-m-d H:i:s'),
                ]);

            return [
                'success' => true,
                'message' => $outcome['message'],
                'outcome' => $outcome,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Failed to apply payment: ' . $e->getMessage()];
        }
    }

    /**
     * Invoice-scoped, self-service payment verification — the core logic
     * shared by BOTH the customer-facing "Verify My Payment" box on the
     * invoice page and the admin dashboard's "Verify a Payment" tool.
     *
     * Unlike the admin tool, this is deliberately invoice-LOCKED: every
     * lookup and every auto-apply is constrained to the one invoice the
     * caller already has legitimate access to (proven by the calling
     * endpoint's own CSRF/HMAC check against that specific invoice — see
     * verify.php). This method itself never accepts an arbitrary target
     * invoice number, which is what keeps a public-facing endpoint safe
     * from being used to nudge a payment onto someone else's invoice or
     * to probe receipts belonging to other customers:
     *
     *   - A receipt/reference belonging to a DIFFERENT invoice is treated
     *     as "not found for this invoice" — never described, confirmed,
     *     or have its real owner revealed.
     *   - If found locally and successful, it is auto-applied to THIS
     *     invoice only if it isn't already linked elsewhere.
     *   - If nothing is found locally, a live Daraja Transaction Status
     *     Query is attempted, but only if a per-invoice rate limit has
     *     not been exceeded (see checkAndBumpVerifyRateLimit) — this is
     *     the one part of the flow that costs a real external API call,
     *     so it's the part that needs throttling against abuse.
     *
     * @param  string $reference   Receipt, checkout request ID, or account reference
     * @param  int    $invoiceId   The invoice this request is scoped to
     * @param  string $gatewayModuleName
     * @return array  ['success' => bool, 'message' => string, 'applied' => bool]
     */
    public static function verifyPaymentForInvoice(string $reference, int $invoiceId, string $gatewayModuleName): array
    {
        self::ensureTables();

        $reference = trim($reference);
        if ($reference === '' || $invoiceId <= 0) {
            return ['success' => false, 'applied' => false, 'message' => 'Enter a valid M-Pesa receipt number or reference.'];
        }

        $local = self::findTransactionByReference($reference);

        if ($local) {
            // Found, but it belongs to a different invoice entirely — never
            // confirm, describe, or hint at whose invoice it actually is.
            if ($local->invoice_id && (int) $local->invoice_id !== $invoiceId) {
                return [
                    'success' => false, 'applied' => false,
                    'message' => 'We could not find that reference for this invoice. Double-check the receipt number and try again.',
                ];
            }

            if ($local->status === 'success') {
                if ($local->invoice_id) {
                    return [
                        'success' => true, 'applied' => false,
                        'message' => 'This payment has already been applied to this invoice. If the page hasn\'t updated yet, refresh it.',
                    ];
                }

                // Successful, not yet linked to ANY invoice — safe to apply
                // here since the customer is verified to be on THIS invoice
                // (the calling endpoint already proved that via its own
                // per-invoice token check before ever calling this method).
                $result = self::applyOrphanedTransaction($local, $invoiceId, 'customer_self_verify', $gatewayModuleName);
                return [
                    'success' => $result['success'], 'applied' => $result['success'],
                    'message' => $result['success']
                        ? $result['message']
                        : 'We found your payment but could not apply it automatically. Please contact support with your receipt number.',
                ];
            }

            if ($local->status === 'pending') {
                return [
                    'success' => false, 'applied' => false,
                    'message' => 'We can see this payment is still being processed. Please wait a few seconds and try again.',
                ];
            }

            // failed / reversed
            return [
                'success' => false, 'applied' => false,
                'message' => 'That payment did not complete successfully (' . ($local->result_desc ?: 'declined or reversed') . '). Please try paying again.',
            ];
        }

        // Nothing on file at all for this reference — fall back to a live
        // Daraja check, but only within the per-invoice rate limit, since
        // this is the one path that costs a real external API call and is
        // reachable without a WHMCS login.
        if (!self::checkAndBumpVerifyRateLimit($invoiceId)) {
            return [
                'success' => false, 'applied' => false,
                'message' => 'Too many verification attempts for this invoice. Please wait a minute and try again, or contact support.',
            ];
        }

        if (!function_exists('getGatewayVariables')) {
            self::ensureGatewayFunctionsLoaded();
        }

        $gw = function_exists('getGatewayVariables') ? getGatewayVariables($gatewayModuleName) : ['type' => ''];

        if (!$gw['type'] || empty($gw['b2cInitiatorName']) || empty($gw['b2cSecurityCredential'])) {
            return [
                'success' => false, 'applied' => false,
                'message' => 'We could not find that payment yet. If you just paid, please wait a moment and try again, or contact support with your receipt number.',
            ];
        }

        try {
            $client    = DarajaClient::fromGatewayParams($gw);
            $systemUrl = rtrim((class_exists('App') ? \App::getSystemURL() : ''), '/');

            $response = $client->transactionStatus([
                'initiatorName'      => $gw['b2cInitiatorName'],
                'securityCredential' => $gw['b2cSecurityCredential'],
                'transactionId'      => $reference,
                'partyA'             => $gw['businessShortcode'],
                'identifierType'     => '4',
                'remarks'            => 'Customer self-verification — Invoice #' . $invoiceId,
                'resultUrl'          => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_result',
                'timeoutUrl'         => $systemUrl . '/modules/gateways/callback/flexpay.php?route=status_timeout',
            ]);

            $accepted = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
            self::logApiCall('customer_verify_status_query', ['reference' => $reference, 'invoice_id' => $invoiceId], $response, $accepted, 'customer_self_verify');

            return [
                'success' => $accepted, 'applied' => false,
                'message' => $accepted
                    ? 'We\'re checking that with Safaricom now — this can take a few seconds. Please refresh shortly, or contact support if it doesn\'t update.'
                    : 'We could not find that payment yet. If you just paid, please wait a moment and try again, or contact support with your receipt number.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false, 'applied' => false,
                'message' => 'We could not find that payment yet. If you just paid, please wait a moment and try again, or contact support with your receipt number.',
            ];
        }
    }

    /**
     * Lightweight per-invoice rate limiter for the live-Daraja-query path
     * of customer self-verification, stored in the existing flexpay_settings
     * key-value table (no new table needed). Allows a small number of
     * attempts within a rolling window, then forces a cooldown — enough
     * for a genuine customer to retry a typo, not enough for someone to
     * use the endpoint to spam Daraja or probe many random receipts.
     *
     * @param  int $invoiceId
     * @param  int $maxAttempts   Allowed attempts per window
     * @param  int $windowSeconds Window length in seconds
     * @return bool  true if this attempt is allowed (and has been counted), false if rate-limited
     */
    public static function checkAndBumpVerifyRateLimit(int $invoiceId, int $maxAttempts = 5, int $windowSeconds = 60): bool
    {
        self::ensureTables();

        $key = 'verify_rl_invoice_' . $invoiceId;
        $raw = self::getSetting($key);
        $now = time();

        $state = $raw ? json_decode((string) $raw, true) : null;

        if (!is_array($state) || !isset($state['window_start'], $state['count'])) {
            $state = ['window_start' => $now, 'count' => 0];
        }

        if ($now - (int) $state['window_start'] > $windowSeconds) {
            // Window expired — start a fresh one.
            $state = ['window_start' => $now, 'count' => 0];
        }

        if ((int) $state['count'] >= $maxAttempts) {
            return false;
        }

        $state['count']++;
        self::setSetting($key, json_encode($state));

        return true;
    }

    /**
     * Clear the self-verify rate limit for one invoice — used by the
     * admin dashboard when a genuine customer gets blocked after several
     * legitimate retries and contacts support.
     *
     * @param  int $invoiceId
     * @return void
     */
    public static function clearVerifyRateLimit(int $invoiceId): void
    {
        self::ensureTables();

        try {
            Capsule::table('flexpay_settings')->where('setting_key', 'verify_rl_invoice_' . $invoiceId)->update([
                'setting_value' => json_encode(['window_start' => time(), 'count' => 0]),
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Non-fatal — worst case the customer just waits out the window
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_unmatched_payments
    // ─────────────────────────────────────────────────────────────────────

    public static function storeUnmatched(array $data): void
    {
        self::ensureTables();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['matched']    = $data['matched'] ?? 0; // explicit, don't rely on schema default alone

        try {
            // Avoid insertOrIgnore() — only available in newer Laravel/Illuminate
            // releases, and we can't assume which version ships inside any
            // given WHMCS install. A plain existence check + insert is
            // portable across every version WHMCS has bundled.
            $exists = Capsule::table('flexpay_unmatched_payments')
                ->where('trans_id', $data['trans_id'])
                ->exists();

            if (!$exists) {
                Capsule::table('flexpay_unmatched_payments')->insert($data);
            }
        } catch (\Exception $e) {
            // Non-fatal
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
            return $query->orderBy('created_at', 'desc')->get()->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Reconcile an unmatched C2B payment to a specific invoice. Applies
     * the payment via WHMCS's native addInvoicePayment() and marks the
     * unmatched record resolved.
     *
     * @param  int    $unmatchedId
     * @param  int    $invoiceId
     * @param  string $adminUsername
     * @param  string $gatewayModuleName
     * @return array  ['success' => bool, 'message' => string]
     */
    public static function reconcileUnmatched(int $unmatchedId, int $invoiceId, string $adminUsername, string $gatewayModuleName): array
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

            // Use WHMCS's own helpers so the invoice updates exactly as if
            // the callback had matched it automatically.
            $normalizedInvoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);
            checkCbTransID($row->trans_id);
            addInvoicePayment($normalizedInvoiceId, $row->trans_id, $row->amount, 0, $gatewayModuleName);

            $outcome = self::classifyPaymentOutcome($normalizedInvoiceId, (float) $row->amount);

            Capsule::table('flexpay_unmatched_payments')->where('id', $unmatchedId)->update([
                'matched'            => 1,
                'matched_invoice_id' => $normalizedInvoiceId,
                'matched_by'         => $adminUsername,
                'matched_at'         => date('Y-m-d H:i:s'),
            ]);

            return ['success' => true, 'message' => $outcome['message'], 'outcome' => $outcome];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_refunds
    // ─────────────────────────────────────────────────────────────────────

    public static function recordRefund(array $data): int
    {
        self::ensureTables();
        $data['created_at'] = date('Y-m-d H:i:s');

        try {
            return (int) Capsule::table('flexpay_refunds')->insertGetId($data);
        } catch (\Exception $e) {
            return 0;
        }
    }

    public static function updateRefundByConversationId(string $conversationId, array $data): void
    {
        self::ensureTables();
        $data['updated_at'] = date('Y-m-d H:i:s');

        try {
            Capsule::table('flexpay_refunds')->where('conversation_id', $conversationId)->update($data);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    public static function listRefunds(int $limit = 50): array
    {
        self::ensureTables();

        try {
            return Capsule::table('flexpay_refunds')
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_balance_snapshots
    // ─────────────────────────────────────────────────────────────────────

    public static function recordBalanceSnapshot(array $data): void
    {
        self::ensureTables();
        $data['created_at'] = date('Y-m-d H:i:s');

        try {
            Capsule::table('flexpay_balance_snapshots')->insert($data);
        } catch (\Exception $e) {
            // Non-fatal
        }
    }

    public static function getLatestBalance(string $shortcode): ?object
    {
        self::ensureTables();

        try {
            return Capsule::table('flexpay_balance_snapshots')
                ->where('shortcode', $shortcode)
                ->orderBy('created_at', 'desc')
                ->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function getBalanceHistory(string $shortcode, int $limit = 30): array
    {
        self::ensureTables();

        try {
            return Capsule::table('flexpay_balance_snapshots')
                ->where('shortcode', $shortcode)
                ->orderBy('created_at', 'desc')
                ->take($limit)
                ->get()
                ->reverse()
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // flexpay_api_log
    // ─────────────────────────────────────────────────────────────────────

    public static function logApiCall(string $operation, $request, $response, bool $success, string $triggeredBy = 'system'): void
    {
        self::ensureTables();

        try {
            Capsule::table('flexpay_api_log')->insert([
                'operation'     => $operation,
                'request_data'  => is_string($request) ? $request : json_encode($request),
                'response_data' => is_string($response) ? $response : json_encode($response),
                'success'       => $success ? 1 : 0,
                'triggered_by'  => $triggeredBy,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // Non-fatal — never let logging break the main flow
        }
    }

    public static function listApiLog(int $limit = 100, ?string $operation = null): array
    {
        self::ensureTables();

        try {
            $query = Capsule::table('flexpay_api_log');
            if ($operation) {
                $query->where('operation', $operation);
            }
            return $query->orderBy('created_at', 'desc')->take($limit)->get()->toArray();
        } catch (\Exception $e) {
            return [];
        }
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
        } catch (\Exception $e) {
            return $default;
        }
    }

    public static function setSetting(string $key, $value): void
    {
        self::ensureTables();

        try {
            $exists = Capsule::table('flexpay_settings')->where('setting_key', $key)->exists();
            if ($exists) {
                Capsule::table('flexpay_settings')->where('setting_key', $key)->update([
                    'setting_value' => $value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            } else {
                Capsule::table('flexpay_settings')->insert([
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Exception $e) {
            // Non-fatal
        }
    }
}

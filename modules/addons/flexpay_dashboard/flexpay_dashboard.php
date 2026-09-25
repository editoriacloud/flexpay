<?php
/**
 * FlexPay Dashboard — WHMCS Addon Module
 *
 * Provides a full admin-area control panel for the FlexPay (Daraja)
 * payment gateway: live transaction ledger, refund tracking, C2B
 * reconciliation queue, account balance trend, and a full Daraja API
 * audit log — plus on-demand tools to trigger Account Balance Query,
 * Transaction Status Query, and Transaction Reversal directly from the
 * UI without needing the WHMCS API or shell access.
 *
 * Install location: modules/addons/flexpay_dashboard/flexpay_dashboard.php
 *
 * @package   FlexPay\Dashboard
 * @version   1.0.0
 * @link      https://developers.whmcs.com/addon-modules/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/flexpay/DarajaClient.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayStore.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayLicense.php';
require_once __DIR__ . '/lib/DashboardActions.php';
require_once __DIR__ . '/lib/Views.php';

// ─── Config ───────────────────────────────────────────────────────────────────
/**
 * Addon module configuration, shown under Setup → Addon Modules → Configure.
 *
 * @return array
 */
function flexpay_dashboard_config()
{
    return [
        'name'        => 'FlexPay Dashboard (M-Pesa / Daraja)',
        'description' => 'Unified management console for the FlexPay M-Pesa gateway: transactions, refunds, C2B reconciliation, account balance, and API logs.',
        'version'     => '1.3.0',
        'author'      => 'Editoria Cloud Systems',
        'fields'      => [
            'access_roles' => [
                'FriendlyName' => 'Restrict Access To',
                'Type'         => 'text',
                'Size'         => '50',
                'Default'      => 'Full Administrator',
                'Description'  => 'Comma-separated WHMCS admin role names allowed to view this dashboard. Leave default unless you have custom roles.',
            ],
            'default_lookback_days' => [
                'FriendlyName' => 'Default Stats Lookback (days)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '30',
                'Description'  => 'Default reporting window for the dashboard summary cards.',
            ],
        ],
    ];
}

// ─── Activation ───────────────────────────────────────────────────────────────
/**
 * Runs once when the addon is activated under Setup → Addon Modules.
 * Creates all flexpay_* tables immediately (rather than waiting for the
 * gateway module's lazy creation), so the dashboard works even if the
 * gateway module hasn't been opened yet.
 *
 * @return array  ['status' => 'success'|'error', 'description' => string]
 */
function flexpay_dashboard_activate()
{
    try {
        FlexPayStore::ensureTables();
        return ['status' => 'success', 'description' => 'FlexPay Dashboard activated. Database tables created.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/**
 * Runs when the addon is deactivated. We deliberately do NOT drop tables
 * here — transaction history must survive a deactivate/reactivate cycle.
 *
 * @return array
 */
function flexpay_dashboard_deactivate()
{
    return ['status' => 'success', 'description' => 'FlexPay Dashboard deactivated. Transaction data has been preserved.'];
}

/**
 * Handles version-to-version schema/data migrations. Currently a no-op
 * since this is the first release, but the hook is wired up so future
 * upgrades (e.g. adding a column) have a safe place to live.
 *
 * @param  array $vars
 * @return void
 */
function flexpay_dashboard_upgrade($vars)
{
    // $currentlyInstalledVersion = $vars['version'];
    // Future schema migrations go here, guarded by version checks.
}

// ─── Admin output (the dashboard itself) ───────────────────────────────────────
/**
 * Main admin-area renderer. WHMCS calls this for every page load while
 * the admin has this addon's tab open; we route internally based on
 * ?fp_tab= and ?fp_action= so the whole dashboard lives in one file
 * without needing WHMCS to know about sub-pages.
 *
 * @param  array $vars  Addon config values + WHMCS context
 * @return void  (echoes HTML directly, per WHMCS addon module convention)
 */
function flexpay_dashboard_output($vars)
{
    FlexPayStore::ensureTables();

    $modulelink = $vars['modulelink'];
    $adminUsername = $_SESSION['adminusername'] ?? 'unknown';

    $tab = $_GET['fp_tab'] ?? 'overview';

    // ── Live data feed for auto-refreshing tabs (Overview, Transactions) ──
    // This still runs through WHMCS's own authenticated admin bootstrap —
    // _output() is only ever called from within addonmodules.php after
    // WHMCS has verified the admin session — so this JSON route inherits
    // that same protection automatically, without needing its own
    // separate auth handling the way a standalone file would.
    if ($tab === 'live_data') {
        flexpay_dashboard_output_live_data($vars);
        return;
    }

    // ── Handle POST actions (refund retry, reconcile, trigger queries) ──────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fp_action'])) {
        $result = FlexPayDashboardActions::handle($_POST, $adminUsername);
        echo FlexPayViews::renderActionResult($result, $modulelink);
    }

    echo FlexPayViews::renderHeader($modulelink, $tab);

    switch ($tab) {
        case 'transactions':
            echo FlexPayViews::renderTransactions($modulelink, $_GET);
            break;

        case 'refunds':
            echo FlexPayViews::renderRefunds($modulelink);
            break;

        case 'reconciliation':
            echo FlexPayViews::renderReconciliation($modulelink);
            break;

        case 'balance':
            echo FlexPayViews::renderBalance($modulelink);
            break;

        case 'tools':
            echo FlexPayViews::renderTools($modulelink);
            break;

        case 'apilog':
            echo FlexPayViews::renderApiLog($modulelink);
            break;

        case 'overview':
        default:
            $days = (int) ($vars['default_lookback_days'] ?? 30);
            echo FlexPayViews::renderOverview($modulelink, $days);
            break;
    }

    echo FlexPayViews::renderFooter();
}

/**
 * Live data feed for client-side auto-refresh — returns fresh stats,
 * unmatched-payment count, latest balance, and the most recent
 * transactions as JSON. Polled every few seconds by the JS injected into
 * the Overview and Transactions tabs (see FlexPayViews::renderHeader's
 * polling script), so an admin watching the dashboard sees new M-Pesa
 * payments land in close to real time without ever reloading the page.
 *
 * @param  array $vars
 * @return void
 */
function flexpay_dashboard_output_live_data($vars): void
{
    // Defensive: never let an unexpected error here produce a PHP warning
    // mixed into the JSON body, which would break the JS parser on the
    // admin's open dashboard tab.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    try {
        $days  = (int) ($vars['default_lookback_days'] ?? 30);
        $stats = FlexPayStore::getStats($days);

        $gw          = FlexPayStore::getFlexPayGatewayParams();
        $latestBal   = FlexPayStore::getLatestBalance($gw['businessShortcode'] ?? '');
        $unmatched   = count(FlexPayStore::listUnmatched(true));

        $recent = FlexPayStore::listTransactions([], 1, 15);
        $rows   = [];

        foreach ($recent['data'] as $row) {
            $rows[] = [
                'id'                 => (int) $row->id,
                'created_at'         => $row->created_at,
                'channel'            => $row->channel,
                'mpesa_receipt'      => $row->mpesa_receipt,
                'phone'              => DarajaClient::toDisplayPhone($row->phone ?: ''),
                'account_reference'  => $row->account_reference,
                'amount'             => (float) $row->amount,
                'invoice_id'         => $row->invoice_id ? (int) $row->invoice_id : null,
                'status'             => $row->status,
                'payment_outcome'    => $row->payment_outcome ?? null,
            ];
        }

        echo json_encode([
            'success'         => true,
            'generated_at'    => date('Y-m-d H:i:s'),
            'stats'           => [
                'total_in'        => (float) $stats['total_in'],
                'total_out'       => (float) $stats['total_out'],
                'success_count'   => (int) $stats['success_count'],
                'failed_count'    => (int) $stats['failed_count'],
                'pending_count'   => (int) $stats['pending_count'],
                'unmatched_count' => $unmatched,
            ],
            'balance' => $latestBal ? [
                'working_account' => (float) $latestBal->working_account,
                'utility_account' => (float) $latestBal->utility_account,
                'created_at'      => $latestBal->created_at,
            ] : null,
            'transactions' => $rows,
        ]);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Live data temporarily unavailable.']);
    }
}

<?php
/**
 * FlexPay Dashboard — WHMCS Addon Module
 *
 * Admin-area control panel for the FlexPay (Daraja) payment gateway: live
 * transaction ledger, refund tracking (with retry), C2B reconciliation
 * queue, account balance trend, a full Daraja API audit log, CSV export,
 * and on-demand tools (balance, transaction status, reversal, C2B
 * registration, connection test, pending-payment sweeper).
 *
 * Install location: modules/addons/flexpay_dashboard/flexpay_dashboard.php
 *
 * @package   FlexPay\Dashboard
 * @version   1.4.0
 * @link      https://developers.whmcs.com/addon-modules/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/../../gateways/flexpay/FlexPaySecurity.php';
require_once __DIR__ . '/../../gateways/flexpay/DarajaClient.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayStore.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayLicense.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayService.php';
require_once __DIR__ . '/lib/DashboardActions.php';
require_once __DIR__ . '/lib/Views.php';

use WHMCS\Database\Capsule;

// ─── Config ───────────────────────────────────────────────────────────────────
function flexpay_dashboard_config()
{
    return [
        'name'        => 'FlexPay Dashboard (M-Pesa / Daraja)',
        'description' => 'Unified management console for the FlexPay M-Pesa gateway: transactions, refunds, C2B reconciliation, account balance, and API logs.',
        'version'     => '1.4.0',
        'author'      => 'Editoria Cloud Systems',
        'fields'      => [
            'access_roles' => [
                'FriendlyName' => 'Restrict Access To',
                'Type'         => 'text',
                'Size'         => '50',
                'Default'      => 'Full Administrator',
                'Description'  => 'Comma-separated WHMCS admin ROLE names allowed to use this dashboard (enforced in addition to WHMCS\'s own Access Control). Leave blank to allow every role granted access below.',
            ],
            'default_lookback_days' => [
                'FriendlyName' => 'Default Stats Lookback (days)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '30',
                'Description'  => 'Default reporting window for the dashboard summary cards.',
            ],
            'pending_sweeper' => [
                'FriendlyName' => 'Auto-resolve Pending STK',
                'Type'         => 'yesno',
                'Default'      => 'on',
                'Description'  => 'On every WHMCS cron run, ask Safaricom about STK payments still pending after 2 minutes and settle/fail them — so a payment is credited even if its callback was lost and the customer closed the page.',
            ],
            'daily_balance' => [
                'FriendlyName' => 'Daily Balance Snapshot',
                'Type'         => 'yesno',
                'Description'  => 'Request an Account Balance snapshot once a day during the WHMCS daily cron (needs initiator credentials).',
            ],
            'log_retention_days' => [
                'FriendlyName' => 'API Log Retention (days)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '180',
                'Description'  => 'Delete API log entries older than this during the daily cron. 0 = keep forever. (Transactions are never deleted.)',
            ],
        ],
    ];
}

// ─── Activation / upgrade ─────────────────────────────────────────────────────
function flexpay_dashboard_activate()
{
    try {
        FlexPayStore::ensureTables();
        return ['status' => 'success', 'description' => 'FlexPay Dashboard activated. Database tables created. Remember to grant your admin role access under Access Control.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
    }
}

/** Tables and history are deliberately kept on deactivation. */
function flexpay_dashboard_deactivate()
{
    return ['status' => 'success', 'description' => 'FlexPay Dashboard deactivated. Transaction data has been preserved.'];
}

/** Schema migrations are versioned inside FlexPayStore::ensureTables(). */
function flexpay_dashboard_upgrade($vars)
{
    FlexPayStore::ensureTables();
}

// ─── Access control ───────────────────────────────────────────────────────────
/**
 * Enforce the "Restrict Access To" role list (v3.4 displayed this setting
 * but never checked it).
 */
function flexpay_dashboard_admin_allowed(array $vars): bool
{
    $roles = array_values(array_filter(array_map(function ($r) {
        return strtolower(trim($r));
    }, explode(',', (string) ($vars['access_roles'] ?? '')))));

    if (empty($roles)) {
        return true;
    }

    $adminId = (int) ($_SESSION['adminid'] ?? 0);
    if ($adminId <= 0) {
        return false;
    }

    try {
        $role = Capsule::table('tbladmins as a')
            ->join('tbladminroles as r', 'r.id', '=', 'a.roleid')
            ->where('a.id', $adminId)
            ->value('r.name');
    } catch (\Throwable $e) {
        return false;
    }

    return $role !== null && in_array(strtolower(trim((string) $role)), $roles, true);
}

// ─── Admin output ─────────────────────────────────────────────────────────────
/**
 * Routes internally on ?fp_tab= and POSTed fp_action. WHMCS only calls this
 * after authenticating the admin session.
 */
function flexpay_dashboard_output($vars)
{
    FlexPayStore::ensureTables();

    $modulelink    = (string) $vars['modulelink'];
    $adminUsername = FlexPayStore::currentAdminUsername('unknown');
    $tab           = preg_replace('/[^a-z_]/', '', (string) ($_GET['fp_tab'] ?? 'overview'));

    if (!flexpay_dashboard_admin_allowed($vars)) {
        if ($tab === 'live_data') {
            flexpay_dashboard_send_json(['success' => false, 'message' => 'Access denied.']);
        }
        echo '<div class="alert alert-danger">Your admin role is not permitted to use the FlexPay Dashboard. '
            . 'A Full Administrator can change this under Setup → Addon Modules → FlexPay Dashboard → Configure → "Restrict Access To".</div>';
        return;
    }

    if ($tab === 'live_data') {
        flexpay_dashboard_output_live_data($vars);
    }
    if ($tab === 'export') {
        flexpay_dashboard_export_csv($_GET);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['fp_action'])) {
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
            echo FlexPayViews::renderApiLog($modulelink, $_GET);
            break;
        case 'overview':
        default:
            echo FlexPayViews::renderOverview($modulelink, max(1, (int) ($vars['default_lookback_days'] ?? 30)));
            break;
    }

    echo FlexPayViews::renderFooter();
}

/**
 * Discard WHMCS's buffered admin page, send JSON, and stop. v3.4 returned
 * normally here, so WHMCS appended the admin template to the JSON and the
 * dashboard's live refresh could never parse it.
 */
function flexpay_dashboard_send_json(array $body): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body);
    exit;
}

/** JSON feed polled by the dashboard every few seconds. */
function flexpay_dashboard_output_live_data($vars): void
{
    try {
        $days  = max(1, (int) ($vars['default_lookback_days'] ?? 30));
        $stats = FlexPayStore::getStats($days);
        $gw    = FlexPayStore::getFlexPayGatewayParams();
        $bal   = FlexPayStore::getLatestBalance(DarajaClient::c2bShortcode($gw));

        $rows = [];
        foreach (FlexPayStore::listTransactions([], 1, 15)['data'] as $row) {
            $rows[] = [
                'id'                => (int) $row->id,
                'created_at'        => $row->created_at,
                'channel'           => $row->channel,
                'mpesa_receipt'     => $row->mpesa_receipt,
                'amount'            => (float) $row->amount,
                'invoice_id'        => $row->invoice_id ? (int) $row->invoice_id : null,
                'status'            => $row->status,
                'payment_outcome'   => $row->payment_outcome ?? null,
            ];
        }

        flexpay_dashboard_send_json([
            'success'      => true,
            'generated_at' => date('Y-m-d H:i:s'),
            'stats'        => [
                'total_in'        => (float) $stats['total_in'],
                'total_out'       => (float) $stats['total_out'],
                'success_count'   => (int) $stats['success_count'],
                'failed_count'    => (int) $stats['failed_count'],
                'pending_count'   => (int) $stats['pending_count'],
                'unmatched_count' => (int) $stats['unmatched_count'],
            ],
            'balance' => $bal ? [
                'working_account' => (float) $bal->working_account,
                'utility_account' => (float) $bal->utility_account,
                'created_at'      => $bal->created_at,
            ] : null,
            'transactions' => $rows,
        ]);
    } catch (\Throwable $e) {
        flexpay_dashboard_send_json(['success' => false, 'message' => 'Live data temporarily unavailable.']);
    }
}

/**
 * Stream the (filtered) transaction ledger as CSV. Cells that a spreadsheet
 * would treat as formulas are prefixed with an apostrophe (CSV injection).
 */
function flexpay_dashboard_export_csv(array $get): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $filters = [
        'channel'   => (string) ($get['channel'] ?? ''),
        'status'    => (string) ($get['status'] ?? ''),
        'search'    => (string) ($get['search'] ?? ''),
        'date_from' => (string) ($get['date_from'] ?? ''),
        'date_to'   => (string) ($get['date_to'] ?? ''),
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="flexpay-transactions-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'w');
    $columns = ['id', 'created_at', 'channel', 'direction', 'status', 'mpesa_receipt', 'checkout_request_id', 'phone', 'amount', 'invoice_id', 'account_reference', 'payment_outcome', 'result_code', 'result_desc'];
    fputcsv($out, $columns);

    $safe = function ($v) {
        $v = (string) $v;
        return ($v !== '' && strpos('=+-@' . "\t\r", $v[0]) !== false) ? "'" . $v : $v;
    };

    FlexPayStore::ensureTables();
    FlexPayStore::transactionQuery($filters)->orderBy('id', 'desc')->chunk(500, function ($rows) use ($out, $columns, $safe) {
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $c) {
                $line[] = $safe($row->$c ?? '');
            }
            fputcsv($out, $line);
        }
    });

    fclose($out);
    exit;
}

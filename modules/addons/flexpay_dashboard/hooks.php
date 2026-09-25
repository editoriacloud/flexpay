<?php
/**
 * FlexPay Dashboard — Module Hooks
 *
 * WHMCS loads this file on every request while the addon is active.
 *
 *   AdminHomeWidgets            — live 7-day stats card on the admin homepage
 *   AdminInvoicesControlsOutput — M-Pesa panel on the admin invoice page
 *   EmailPreSend                — M-Pesa payment instructions merge fields
 *   EmailTplMergeFields         — …listed in the invoice email template editor
 *   AfterCronJob                — resolves STK pushes whose callback never arrived
 *   DailyCronJob                — housekeeping (rate-limit rows, old API log) and
 *                                 the optional daily Account Balance snapshot
 *
 * @see https://developers.whmcs.com/hooks/module-hooks/
 * @see https://developers.whmcs.com/addon-modules/admin-dashboard-widgets/
 */

if (!defined('WHMCS')) {
    die('This hook should not be run directly');
}

require_once __DIR__ . '/../../gateways/flexpay/FlexPaySecurity.php';
require_once __DIR__ . '/../../gateways/flexpay/DarajaClient.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayStore.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayLicense.php';
require_once __DIR__ . '/../../gateways/flexpay/FlexPayService.php';
require_once __DIR__ . '/lib/WhmcsIntegration.php';

use WHMCS\Database\Capsule;

// Admin homepage widgets exist from WHMCS 7.1.
if (class_exists('\WHMCS\Module\AbstractWidget')) {
    require_once __DIR__ . '/lib/FlexPayStatsWidget.php';

    add_hook('AdminHomeWidgets', 1, function () {
        return new FlexPayStatsWidget();
    });
}

add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    try {
        return FlexPayWhmcsIntegration::adminInvoicePanel((array) $vars);
    } catch (\Throwable $e) {
        return '';
    }
});

add_hook('EmailPreSend', 1, function ($vars) {
    try {
        return FlexPayWhmcsIntegration::emailMergeFields((array) $vars);
    } catch (\Throwable $e) {
        return [];
    }
});

add_hook('EmailTplMergeFields', 1, function ($vars) {
    return FlexPayWhmcsIntegration::emailTemplateFields((array) $vars);
});

/** This addon's saved settings (hooks don't receive $vars). */
function flexpay_dashboard_hook_settings(): array
{
    try {
        return FlexPayStore::rows(Capsule::table('tbladdonmodules')
            ->where('module', 'flexpay_dashboard')
            ->pluck('value', 'setting'));
    } catch (\Throwable $e) {
        return [];
    }
}

add_hook('AfterCronJob', 1, function () {
    try {
        $settings = flexpay_dashboard_hook_settings();
        if (($settings['pending_sweeper'] ?? 'on') !== 'on') {
            return;
        }

        $gw = FlexPayStore::getFlexPayGatewayParams();
        if (empty($gw['type'])) {
            return;
        }

        $counts = FlexPayService::sweepPendingStk($gw, 20);
        if ($counts['checked'] > 0) {
            FlexPayStore::logApiCall('stk_sweeper', ['trigger' => 'cron'], $counts, true, 'cron');
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('FlexPay: pending STK sweeper failed — ' . $e->getMessage());
        }
    }
});

add_hook('DailyCronJob', 1, function () {
    try {
        $settings = flexpay_dashboard_hook_settings();
        $removed  = FlexPayStore::prune((int) ($settings['log_retention_days'] ?? 180));
        FlexPayStore::logApiCall('housekeeping', ['trigger' => 'daily_cron'], $removed, true, 'cron');

        if (($settings['daily_balance'] ?? '') === 'on') {
            $gw = FlexPayStore::getFlexPayGatewayParams();
            $initiator = !empty($gw['type']) ? DarajaClient::initiatorCredentials($gw) : null;
            if ($initiator !== null) {
                require_once __DIR__ . '/lib/DashboardActions.php';
                FlexPayDashboardActions::requestBalance($gw, $initiator, 'cron');
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('FlexPay: daily housekeeping failed — ' . $e->getMessage());
        }
    }
});

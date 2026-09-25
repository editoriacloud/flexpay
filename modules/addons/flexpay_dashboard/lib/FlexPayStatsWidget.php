<?php
/**
 * FlexPay Admin Dashboard Widget
 *
 * Registers a small live-stats widget on the WHMCS admin homepage
 * (Configuration → System Settings → ... is not required; widgets
 * register themselves via hooks.php and WHMCS auto-discovers them).
 *
 * This file lives inside the addon module folder but is loaded directly
 * by hooks.php at WHMCS bootstrap time — addon "hooks.php" files are
 * auto-loaded by WHMCS for every active addon module, independent of
 * whether the addon's own _output() page is currently open.
 *
 * @package FlexPay\Dashboard
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Module\AbstractWidget;

require_once __DIR__ . '/../../../gateways/flexpay/FlexPayStore.php';
require_once __DIR__ . '/../../../gateways/flexpay/FlexPayLicense.php';

class FlexPayStatsWidget extends AbstractWidget
{
    protected $title = 'FlexPay (M-Pesa) — Last 7 Days';
    protected $description = '';
    protected $weight = 150;
    protected $columns = 2;
    protected $cache = true;
    protected $cacheExpiry = 5; // minutes — keep the homepage fast

    /**
     * The access control permission required to view this widget. Per
     * WHMCS's own documentation this must be a specific permission name
     * (e.g. "View Invoices"), not an admin role name — "Full Administrator"
     * is a role, not a permission, and would not match correctly. Left
     * blank so every admin who can see the addon at all can see the widget;
     * the addon's own "access_roles" setting is the real gatekeeper here.
     *
     * @var string
     */
    protected $requiredPermission = '';

    public function getData()
    {
        if (!class_exists('FlexPayStore')) {
            return [];
        }

        try {
            $stats = FlexPayStore::getStats(7);

            if (class_exists('FlexPayLicense')) {
                $gw      = FlexPayStore::getFlexPayGatewayParams();
                $license = FlexPayLicense::check($gw);
                $stats['license_valid']  = $license['valid'];
                $stats['license_status'] = $license['status'];
            }

            return $stats;
        } catch (\Throwable $e) {
            // Never let a widget data error take down the whole admin
            // homepage — fail quietly, generateOutput() shows a fallback.
            return [];
        }
    }

    public function generateOutput($data)
    {
        if (empty($data) || !is_array($data)) {
            return '<div style="padding:14px;color:#888;font-size:13px;">FlexPay data unavailable.</div>';
        }

        $html = '<div style="padding:6px 2px;">';

        if (isset($data['license_valid']) && !$data['license_valid']) {
            $html .= '<div style="margin-bottom:12px;padding:8px 10px;background:#f8d7da;color:#721c24;border-radius:5px;font-size:12px;">'
                . '&#9888; License: ' . htmlspecialchars($data['license_status'] ?? 'Invalid') . ' — M-Pesa payments are blocked.'
                . '</div>';
        }

        $rows = [
            ['Received', 'KES ' . number_format($data['total_in'] ?? 0, 2), '#007229'],
            ['Refunded', 'KES ' . number_format($data['total_out'] ?? 0, 2), '#c0392b'],
            ['Successful', number_format($data['success_count'] ?? 0), '#222'],
            ['Pending', number_format($data['pending_count'] ?? 0), '#856404'],
        ];

        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';

        foreach ($rows as [$label, $value, $color]) {
            $html .= '<div>'
                . '<div style="font-size:11px;color:#999;text-transform:uppercase;letter-spacing:.3px;">' . htmlspecialchars($label) . '</div>'
                . '<div style="font-size:18px;font-weight:700;color:' . $color . ';">' . $value . '</div>'
                . '</div>';
        }

        $html .= '</div>';

        if (($data['unmatched_count'] ?? 0) > 0) {
            $html .= '<div style="margin-top:12px;padding:8px 10px;background:#fff3cd;color:#856404;border-radius:5px;font-size:12px;">'
                . '&#9888; ' . $data['unmatched_count'] . ' unmatched C2B payment(s) need review.'
                . '</div>';
        }

        $html .= '<div style="margin-top:12px;">'
            . '<a href="addonmodules.php?module=flexpay_dashboard" style="font-size:12px;color:#007229;text-decoration:none;font-weight:600;">Open full dashboard &rarr;</a>'
            . '</div>';

        $html .= '</div>';

        return $html;
    }
}

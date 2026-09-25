<?php
/**
 * FlexPay Dashboard — Module Hooks
 *
 * WHMCS auto-loads this file on every page load once the addon module is
 * active (per WHMCS's own module hooks documentation), independent of
 * whether the addon's _output() page is currently open. This is where we
 * register the admin homepage widget.
 *
 * @see https://developers.whmcs.com/hooks/module-hooks/
 * @see https://developers.whmcs.com/addon-modules/admin-dashboard-widgets/
 */

if (!defined('WHMCS')) {
    die('This hook should not be run directly');
}

require_once __DIR__ . '/lib/FlexPayStatsWidget.php';

add_hook('AdminHomeWidgets', 1, function () {
    return new FlexPayStatsWidget();
});

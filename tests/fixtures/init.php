<?php
/**
 * Fake WHMCS init.php used when the test runner serves the module through
 * PHP's built-in web server. FP_TEST_STUBS / FP_TEST_DB come from the env.
 */
require_once getenv('FP_TEST_STUBS');

fp_test_boot_db(getenv('FP_TEST_DB'));

require_once __DIR__ . '/modules/gateways/flexpay/FlexPaySecurity.php';
require_once __DIR__ . '/modules/gateways/flexpay/DarajaClient.php';
DarajaClient::setTransport('fp_test_daraja_transport');

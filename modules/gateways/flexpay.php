<?php
/**
 * FlexPay (Daraja) Payment Gateway Module for WHMCS
 *
 * A complete Safaricom Daraja integration: STK Push checkout, fully
 * automated C2B (paybill/till) reconciliation, B2C refunds, transaction
 * reversal, transaction status queries, account balance checks and
 * Dynamic QR codes.
 *
 * Callback URLs intentionally avoid the word "mpesa" anywhere in the path
 * or query string (Daraja rejects such C2B URLs) — see
 * modules/gateways/callback/flexpay.php and the ?route= values.
 *
 * File layout (relative to WHMCS root):
 *   modules/gateways/flexpay.php                    ← this file
 *   modules/gateways/callback/flexpay.php            ← Daraja async callbacks
 *   modules/gateways/flexpay/DarajaClient.php        ← shared Daraja API client
 *   modules/gateways/flexpay/FlexPayStore.php        ← shared DB access layer
 *   modules/gateways/flexpay/FlexPaySecurity.php     ← tokens, callback auth, rate limits
 *   modules/gateways/flexpay/FlexPayLicense.php      ← commercial license enforcement
 *   modules/gateways/flexpay/FlexPayService.php      ← shared payment workflows
 *   modules/gateways/flexpay/checkout.php            ← AJAX STK Push initiator
 *   modules/gateways/flexpay/poll.php                ← AJAX status poller
 *   modules/gateways/flexpay/verify.php              ← customer self-verify endpoint
 *
 * @package   FlexPay\Gateway
 * @author    Editoria Cloud Systems <https://www.editoriaweb.co.ke>
 * @version   3.7.0
 * @link      https://developers.whmcs.com/payment-gateways/
 * @link      https://developer.safaricom.co.ke/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/flexpay/FlexPaySecurity.php';
require_once __DIR__ . '/flexpay/DarajaClient.php';
require_once __DIR__ . '/flexpay/FlexPayStore.php';
require_once __DIR__ . '/flexpay/FlexPayLicense.php';
require_once __DIR__ . '/flexpay/FlexPayService.php';

// ─── MetaData ─────────────────────────────────────────────────────────────────
function flexpay_MetaData()
{
    FlexPayStore::ensureTables();

    return [
        'DisplayName'                 => 'M-Pesa via FlexPay (Daraja)',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

// ─── Configuration ────────────────────────────────────────────────────────────
function flexpay_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'M-Pesa via FlexPay (Daraja)',
        ],

        // ── Daraja app ────────────────────────────────────────────────
        'testMode' => [
            'FriendlyName' => 'Sandbox / Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to use the Safaricom Sandbox. No real money moves in sandbox.',
        ],
        'consumerKey' => [
            'FriendlyName' => 'Consumer Key',
            'Type'         => 'password',
            'Size'         => '64',
            'Description'  => 'Daraja App Consumer Key',
        ],
        'consumerSecret' => [
            'FriendlyName' => 'Consumer Secret',
            'Type'         => 'password',
            'Size'         => '64',
            'Description'  => 'Daraja App Consumer Secret',
        ],

        // ── STK Push ──────────────────────────────────────────────────
        'businessShortcode' => [
            'FriendlyName' => 'Business Shortcode',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Your Paybill number, or for Buy Goods the Store / Head Office number (e.g. 174379)',
        ],
        'passkey' => [
            'FriendlyName' => 'Lipa Na M-Pesa Passkey',
            'Type'         => 'password',
            'Size'         => '100',
            'Description'  => 'STK Push Passkey from the Daraja portal. Never sent to the browser.',
        ],
        'transactionType' => [
            'FriendlyName' => 'STK Transaction Type',
            'Type'         => 'dropdown',
            'Options'      => 'CustomerPayBillOnline,CustomerBuyGoodsOnline',
            'Default'      => 'CustomerPayBillOnline',
            'Description'  => 'PayBillOnline for paybill numbers, BuyGoodsOnline for till numbers',
        ],
        'stkPartyB' => [
            'FriendlyName' => 'Till Number (Buy Goods only)',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'For Buy Goods: the till number customers pay (PartyB) when it differs from the Store number above. Leave blank for Paybill.',
        ],
        'accountRefPrefix' => [
            'FriendlyName' => 'Account Reference Prefix',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => 'INV',
            'Description'  => 'Prefix used in account references (e.g. INV → INV-42). Letters/digits only.',
        ],
        'showQrCode' => [
            'FriendlyName' => 'Show M-Pesa QR Code',
            'Type'         => 'yesno',
            'Description'  => 'Show a Daraja Dynamic QR code on the invoice that customers can scan in the M-Pesa app.',
        ],
        'qrMerchantName' => [
            'FriendlyName' => 'QR Merchant Name',
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'Business name shown in the M-Pesa app when the QR code is scanned (defaults to your WHMCS company name).',
        ],

        // ── C2B ───────────────────────────────────────────────────────
        'autoRegisterC2B' => [
            'FriendlyName' => 'Auto-Register C2B URLs',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Automatically (re)register C2B Validation/Confirmation URLs with Daraja whenever your shortcode or domain changes. Recommended: leave ON.',
        ],
        'c2bShortcode' => [
            'FriendlyName' => 'C2B Shortcode (optional)',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Leave blank to reuse Business Shortcode for C2B paybill payments',
        ],
        'c2bValidationMode' => [
            'FriendlyName' => 'C2B Validation Mode',
            'Type'         => 'dropdown',
            'Options'      => 'strict,lenient',
            'Default'      => 'lenient',
            'Description'  => 'strict = Safaricom rejects paybill payments whose account number is not exactly an open invoice (the customer sees the error on their phone). lenient = accept all; non-matching payments wait in Reconciliation. Either way, only exact references are ever applied. (External validation must be enabled on your shortcode by Safaricom.)',
        ],
        'acceptBareInvoiceNumber' => [
            'FriendlyName' => 'Accept Bare Invoice Number',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Also accept the plain invoice number (e.g. 42) as the account reference, in addition to INV-42 and the WHMCS invoice number. Payments are ONLY applied automatically when the reference is exactly one open invoice — never by amount or phone.',
        ],
        'selfVerifyMode' => [
            'FriendlyName' => 'Customer Self-Verify',
            'Type'         => 'dropdown',
            'Options'      => [
                'queue' => 'Queue for staff approval (recommended)',
                'apply' => 'Apply automatically',
            ],
            'Default'      => 'queue',
            'Description'  => 'When a customer submits the receipt of a payment made with a wrong/missing reference on the invoice page: queue it in Reconciliation with the invoice pre-filled, or apply it immediately.',
        ],

        // ── B2C / initiator ───────────────────────────────────────────
        'b2cShortcode' => [
            'FriendlyName' => 'B2C Shortcode',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Shortcode used for Business-to-Customer refund disbursements (defaults to Business Shortcode)',
        ],
        'b2cCommandId' => [
            'FriendlyName' => 'B2C Command',
            'Type'         => 'dropdown',
            'Options'      => 'BusinessPayment,SalaryPayment,PromotionPayment',
            'Default'      => 'BusinessPayment',
            'Description'  => 'B2C CommandID used for refunds. BusinessPayment is correct for most accounts.',
        ],
        'b2cInitiatorName' => [
            'FriendlyName' => 'Initiator Name',
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'API operator username from the M-Pesa org portal. Needed for refunds, reversals, balance and status queries.',
        ],
        'b2cSecurityCredential' => [
            'FriendlyName' => 'Security Credential',
            'Type'         => 'password',
            'Size'         => '255',
            'Description'  => 'Encrypted initiator password from the Daraja portal. Leave blank to have FlexPay generate it from the two fields below.',
        ],
        'initiatorPassword' => [
            'FriendlyName' => 'Initiator Password (optional)',
            'Type'         => 'password',
            'Size'         => '64',
            'Description'  => 'Only needed if Security Credential is blank.',
        ],
        'initiatorCertificate' => [
            'FriendlyName' => 'Safaricom Certificate (optional)',
            'Type'         => 'textarea',
            'Rows'         => '4',
            'Cols'         => '60',
            'Description'  => 'Paste Safaricom\'s sandbox or production public certificate (PEM) to auto-generate the Security Credential from the Initiator Password.',
        ],

        // ── Security ──────────────────────────────────────────────────
        'callbackSecurity' => [
            'FriendlyName' => 'Callback Security',
            'Type'         => 'dropdown',
            'Options'      => [
                'token_or_ip' => 'Secret URL key OR Safaricom IP (recommended)',
                'token_only'  => 'Secret URL key only (strictest)',
                'ip_only'     => 'Safaricom IP only',
                'off'         => 'Off — accept any caller (NOT recommended)',
            ],
            'Default'      => 'token_or_ip',
            'Description'  => 'How FlexPay authenticates Daraja callbacks. Forged callbacks could otherwise mark invoices paid.',
        ],
        'verifyStkCallbacks' => [
            'FriendlyName' => 'Double-check STK Results',
            'Type'         => 'yesno',
            'Default'      => 'on',
            'Description'  => 'Confirm every successful STK callback with Daraja\'s STK Query API before crediting the invoice. Recommended: leave ON.',
        ],
        'trustedProxies' => [
            'FriendlyName' => 'Trusted Proxies',
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => 'Comma-separated IPs/CIDRs of your own reverse proxy or CDN (e.g. Cloudflare). Only these may set X-Forwarded-For. Leave blank if WHMCS is not behind a proxy.',
        ],
        'extraCallbackIps' => [
            'FriendlyName' => 'Extra Callback IPs',
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => 'Additional Safaricom callback IPs/CIDRs, if Safaricom publishes new ones.',
        ],

        // ── Licensing ─────────────────────────────────────────────────
        'licenseKey' => [
            'FriendlyName' => 'FlexPay License Key',
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => 'Your FlexPay license key, issued by Editoria Cloud Systems when you purchased FlexPay. Required — the module will not process payments without a valid license.',
        ],
        'licensingSecret' => [
            'FriendlyName' => 'Licensing Secret Key',
            'Type'         => 'password',
            'Size'         => '60',
            'Description'  => 'The MD5 Hash Verification / Secret Key for the FlexPay licensed product. Provided with your purchase.',
        ],
    ];
}

/**
 * Validate settings when an admin saves the gateway configuration
 * (WHMCS calls {module}_config_validate and shows the exception message).
 * Catches the mistakes that otherwise only surface as failed payments.
 */
function flexpay_config_validate(array $params)
{
    $errors = [];

    foreach (['businessShortcode' => 'Business Shortcode', 'stkPartyB' => 'Till Number', 'c2bShortcode' => 'C2B Shortcode', 'b2cShortcode' => 'B2C Shortcode'] as $key => $label) {
        $value = trim((string) ($params[$key] ?? ''));
        if ($value !== '' && !preg_match('/^\d{5,8}$/', $value)) {
            $errors[] = "{$label} must be 5–8 digits.";
        }
    }

    $prefix = trim((string) ($params['accountRefPrefix'] ?? ''));
    if ($prefix !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9]{0,5}$/', $prefix)) {
        $errors[] = 'Account Reference Prefix must start with a letter and be at most 6 letters/digits (M-Pesa account references are limited to 12 characters).';
    }

    if (trim((string) ($params['consumerKey'] ?? '')) !== '' && trim((string) ($params['consumerSecret'] ?? '')) === '') {
        $errors[] = 'Consumer Secret is required when a Consumer Key is set.';
    }
    if (trim((string) ($params['businessShortcode'] ?? '')) !== '' && trim((string) ($params['passkey'] ?? '')) === '') {
        $errors[] = 'Lipa Na M-Pesa Passkey is required for STK Push.';
    }

    foreach (['trustedProxies' => 'Trusted Proxies', 'extraCallbackIps' => 'Extra Callback IPs'] as $key => $label) {
        foreach (FlexPaySecurity::parseList((string) ($params[$key] ?? '')) as $entry) {
            [$ip, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $valid = filter_var($ip, FILTER_VALIDATE_IP) !== false
                && ($bits === null || (ctype_digit($bits) && (int) $bits <= (strpos($ip, ':') !== false ? 128 : 32)));
            if (!$valid) {
                $errors[] = "{$label}: \"{$entry}\" is not a valid IP address or CIDR range.";
            }
        }
    }

    if (trim((string) ($params['b2cSecurityCredential'] ?? '')) === '' && trim((string) ($params['initiatorPassword'] ?? '')) !== ''
        && DarajaClient::securityCredentialFromPassword((string) $params['initiatorPassword'], (string) ($params['initiatorCertificate'] ?? '')) === null) {
        $errors[] = 'Could not encrypt the Initiator Password with the pasted Safaricom certificate — paste the full PEM certificate, or enter the Security Credential instead.';
    }

    if (($params['callbackSecurity'] ?? '') === 'off' && ($params['testMode'] ?? '') !== 'on') {
        $errors[] = 'Callback Security cannot be Off in live mode — anyone could post fake payment notifications.';
    }

    if ($errors) {
        $message = implode(' ', $errors);
        if (class_exists('\WHMCS\Exception\Module\InvalidConfiguration')) {
            throw new \WHMCS\Exception\Module\InvalidConfiguration($message);
        }
        throw new \Exception($message);
    }
}

/**
 * WHMCS 8.2+: show the M-Pesa account balance in WHMCS's own gateway
 * balance display. Daraja's balance API is asynchronous, so this returns
 * the latest snapshot and (at most every 30 minutes) requests a fresh one.
 */
function flexpay_account_balance(array $params = [])
{
    $shortcode = DarajaClient::c2bShortcode($params);
    $latest    = FlexPayStore::getLatestBalance($shortcode);

    $initiator = DarajaClient::initiatorCredentials($params);
    if ($initiator !== null && (!$latest || strtotime((string) $latest->created_at) < time() - 1800)
        && FlexPaySecurity::rateLimit('native_balance_refresh', 1, 1800)) {
        FlexPayService::requestBalance($params, $initiator, 'whmcs_balance_widget');
    }

    $items = [
        \WHMCS\Module\Gateway\Balance::factory($latest ? (float) $latest->working_account : 0.0, 'KES'),
    ];
    if ($latest) {
        $items[] = \WHMCS\Module\Gateway\Balance::factory((float) $latest->utility_account, 'KES', 'status.pending', '#6ecacc');
    }

    return \WHMCS\Module\Gateway\BalanceCollection::factoryFromItems(...$items);
}

/**
 * WHMCS 8.2+: details shown when an admin clicks a FlexPay transaction ID
 * under Billing → Transactions. Served from FlexPay's own ledger (no Daraja
 * call needed; the receipt or STK checkout ID is the WHMCS transaction ID).
 */
function flexpay_TransactionInformation(array $params = [])
{
    $transId = (string) ($params['transactionId'] ?? '');
    $row     = FlexPayStore::findTransactionByReceipt($transId) ?: FlexPayStore::findTransactionByCheckoutId($transId);

    $info = (new \WHMCS\Billing\Payment\Transaction\Information())->setTransactionId($transId);
    if (!$row) {
        return $info->setDescription('No FlexPay record found for this transaction ID.');
    }

    $labels = ['stk' => 'M-Pesa STK Push', 'c2b' => 'M-Pesa Paybill/Till (C2B)', 'b2c' => 'M-Pesa Refund (B2C)', 'reversal' => 'M-Pesa Reversal'];
    $phone  = preg_match('/^[a-f0-9]{64}$/i', (string) $row->phone) ? '(hashed)' : DarajaClient::toDisplayPhone((string) $row->phone);

    $info->setAmount((float) $row->amount)
        ->setCurrency('KES')
        ->setType($labels[$row->channel] ?? $row->channel)
        ->setStatus(ucfirst((string) $row->status))
        ->setDescription(trim(($row->account_reference ? 'Ref ' . $row->account_reference . ' · ' : '') . 'Phone ' . $phone . ' · ' . $row->result_desc));

    if (class_exists('\WHMCS\Carbon') && $row->created_at) {
        $info->setCreated(\WHMCS\Carbon::parse((string) $row->created_at));
    }

    return $info;
}

/** Account reference for an invoice ("INV-42"). */
function flexpay_account_reference(array $params, int $invoiceId): string
{
    return FlexPayService::accountReference($params, $invoiceId);
}

// ─── Payment link (STK Push UI) ───────────────────────────────────────────────
function flexpay_link($params)
{
    $license = FlexPayLicense::check($params);
    if (!$license['valid']) {
        return flexpay_render_license_block($license, $params);
    }

    flexpay_autoRegisterC2B($params);

    $invoiceId = (int) $params['invoiceid'];
    $amount    = FlexPayStore::invoiceBalanceInKes($invoiceId);

    if ($amount === null) {
        // Invoice in a non-KES currency and no KES currency configured.
        FlexPayStore::logApiCall('currency_unsupported', ['invoice_id' => $invoiceId, 'currency' => $params['currency'] ?? ''], [], false, 'system');
        return flexpay_render_notice('M-Pesa payments are processed in Kenyan Shillings. Please choose another payment method or contact us.');
    }
    if ($amount < 1) {
        return '';
    }

    $systemUrl = rtrim((string) $params['systemurl'], '/');
    $returnUrl = (string) $params['returnurl'];

    $cleanPhone   = DarajaClient::formatPhone((string) ($params['clientdetails']['phonenumber'] ?? ''));
    $displayPhone = DarajaClient::toDisplayPhone($cleanPhone);

    $isTill    = (($params['transactionType'] ?? '') === 'CustomerBuyGoodsOnline');
    $payNumber = $isTill ? DarajaClient::stkPartyB($params) : DarajaClient::c2bShortcode($params);
    $accRef    = flexpay_account_reference($params, $invoiceId);

    $config = [
        'checkoutUrl' => $systemUrl . '/modules/gateways/flexpay/checkout.php',
        'pollUrl'     => $systemUrl . '/modules/gateways/flexpay/poll.php',
        'verifyUrl'   => $systemUrl . '/modules/gateways/flexpay/verify.php',
        'returnUrl'   => $returnUrl,
        'invoiceId'   => (string) $invoiceId,
        // Signed, expiring proof that WHMCS showed this visitor this invoice.
        // Contains no Daraja credentials.
        'token'       => FlexPaySecurity::invoiceToken($invoiceId),
        'since'       => time(),
    ];

    $qrImage = (($params['showQrCode'] ?? '') === 'on') ? flexpay_qr_image($params, $amount, $accRef, $payNumber, $isTill) : null;

    $e = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };

    ob_start();
    ?>
    <div id="flexpay-widget" style="max-width:430px;margin:0 auto;font-family:Arial,sans-serif;text-align:left;">

      <div style="background:#007229;color:#fff;padding:14px 18px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:10px;">
        <svg width="32" height="32" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <rect width="100" height="100" rx="12" fill="#fff"/>
          <text x="50" y="68" text-anchor="middle" font-size="40" font-family="Arial" font-weight="bold" fill="#007229">M</text>
        </svg>
        <span style="font-size:18px;font-weight:700;">Pay with M-Pesa</span>
      </div>

      <div style="border:1px solid #ccc;border-top:none;padding:20px;border-radius:0 0 8px 8px;background:#fff;">

        <p style="margin:0 0 14px;font-size:14px;color:#333;">
          Amount: <strong style="font-size:16px;">KES <?php echo number_format($amount); ?></strong>
        </p>

        <label for="flexpay_phone" style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#333;">
          M-Pesa Phone Number
        </label>
        <input type="tel" id="flexpay_phone" value="<?php echo $e($displayPhone); ?>" placeholder="0712 345 678" maxlength="16" autocomplete="tel"
               style="width:100%;padding:9px 12px;border:1px solid #bbb;border-radius:5px;font-size:14px;box-sizing:border-box;" />
        <small style="color:#777;font-size:12px;">Safaricom number to receive the payment prompt</small>

        <div id="flexpay-status" role="status" aria-live="polite" style="margin-top:12px;padding:10px 14px;border-radius:5px;font-size:13px;background:#f1f3f4;color:#555;border:1px solid #e0e0e0;">
          &#128270; Watching for your payment — pay via the prompt below, or manually using the <?php echo $isTill ? 'Buy Goods till' : 'Pay Bill'; ?> details. This page updates itself automatically.
        </div>

        <button type="button" id="flexpay-pay-btn"
                style="display:block;width:100%;margin-top:16px;padding:11px;background:#007229;color:#fff;border:none;border-radius:5px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.3px;">
          &#128241; Send M-Pesa Prompt
        </button>

        <p style="text-align:center;font-size:11px;color:#999;margin:12px 0 0;">
          You will receive a push notification on your phone.<br>
          Enter your M-Pesa PIN to complete payment. Prompt expires in 60 seconds.
        </p>

        <?php if ($qrImage): ?>
        <div style="text-align:center;margin-top:14px;">
          <img src="data:image/png;base64,<?php echo $qrImage; ?>" alt="M-Pesa QR code" width="180" height="180" style="max-width:180px;height:auto;">
          <div style="font-size:11px;color:#777;">Or scan with the M-Pesa app (Lipa na M-Pesa → Scan QR)</div>
        </div>
        <?php endif; ?>

        <details style="margin-top:14px;font-size:12px;color:#888;">
          <summary style="cursor:pointer;">Prefer to pay manually?</summary>
          <?php if ($isTill): ?>
          <p style="margin:8px 0 0;">
            Go to M-Pesa menu → Lipa na M-Pesa → Buy Goods and Services.<br>
            Till Number: <strong><?php echo $e($payNumber); ?></strong><br>
            Amount: <strong>KES <?php echo number_format($amount); ?></strong><br>
            <span style="color:#b36b00;">Till payments made from the M-Pesa menu can't carry your invoice number, so they are confirmed by our team before your invoice updates. For instant confirmation use "Send M-Pesa Prompt" above. Already paid? Enter your receipt below.</span>
          </p>
          <?php else: ?>
          <p style="margin:8px 0 0;">
            Go to M-Pesa menu → Lipa Na M-Pesa → Pay Bill.<br>
            Business No: <strong><?php echo $e($payNumber); ?></strong><br>
            Account No: <strong><?php echo $e($accRef); ?></strong><br>
            Amount: <strong>KES <?php echo number_format($amount); ?></strong><br>
            <span style="color:#007229;">Enter the Account No <strong>exactly</strong> as shown. This page updates itself automatically the moment we receive your payment. Payments with a different account number are not applied automatically.</span>
          </p>
          <?php endif; ?>
        </details>

        <div style="margin-top:16px;border-top:1px solid #eee;padding-top:14px;">
          <button type="button" id="flexpay-verify-toggle"
                  style="background:none;border:none;color:#007229;font-size:12px;font-weight:600;cursor:pointer;padding:0;text-decoration:underline;">
            Already paid? Verify your payment
          </button>

          <div id="flexpay-verify-box" style="display:none;margin-top:10px;">
            <label for="flexpay_verify_ref" style="display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:#333;">
              M-Pesa Receipt Number
            </label>
            <input type="text" id="flexpay_verify_ref" placeholder="e.g. NLJ7RT61SV" maxlength="12" autocomplete="off"
                   style="width:100%;padding:8px 10px;border:1px solid #bbb;border-radius:5px;font-size:13px;box-sizing:border-box;text-transform:uppercase;" />
            <small style="color:#777;font-size:11px;">Find this in the M-Pesa confirmation SMS you received.</small>
            <button type="button" id="flexpay-verify-btn"
                    style="display:block;width:100%;margin-top:8px;padding:9px;background:#fff;color:#007229;border:1px solid #007229;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;">
              Verify Payment
            </button>
            <div id="flexpay-verify-result" role="status" aria-live="polite" style="display:none;margin-top:8px;padding:8px 10px;border-radius:5px;font-size:12px;"></div>
          </div>
        </div>
        <p style="text-align:center;font-size:10px;color:#bbb;margin:14px 0 0;">
          Powered by FlexPay &middot; Editoria Cloud Systems
        </p>
      </div>
    </div>

    <script>
    (function () {
      var CFG = <?php echo json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?>;

      var pollTimer       = null;
      var currentCheckout = null;
      var settled         = false;
      var startedAt       = Date.now();
      var FAST_INTERVAL   = 3000;          // while an STK prompt is in flight
      var IDLE_INTERVAL   = 6000;          // ambient watch for a manual payment
      var IDLE_GIVE_UP_MS = 30 * 60 * 1000; // stop ambient polling after 30 minutes
      var ICONS = { queued: '🔎', sent: '📱', processing: '⏳', confirmed: '🎉', failed: '❌', review: '📥' };
      var STYLES = {
        info:    'background:#e8f4fd;color:#004085;border:1px solid #b8daff;',
        success: 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;',
        error:   'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;'
      };

      function $(id) { return document.getElementById(id); }

      // Server messages are always inserted as text, never HTML.
      function show(el, type, msg, base) {
        el.style.cssText = (STYLES[type] || STYLES.info) + base;
        el.textContent = msg;
      }
      function status(type, msg) {
        show($('flexpay-status'), type, msg, 'display:block;border-radius:5px;padding:10px 14px;margin-top:12px;font-size:13px;');
      }
      function verifyResult(type, msg) {
        show($('flexpay-verify-result'), type, msg, 'display:block;border-radius:5px;padding:8px 10px;margin-top:8px;font-size:12px;');
      }

      function cleanPhone(p) {
        p = String(p).replace(/\D/g, '');
        if (p.length === 9 && /^[71]/.test(p))       p = '254' + p;
        else if (p.length === 10 && p.charAt(0) === '0') p = '254' + p.substring(1);
        return /^254[17]\d{8}$/.test(p) ? p : '';
      }

      function schedule(delay) {
        if (settled) return;
        clearTimeout(pollTimer);
        if (!currentCheckout && Date.now() - startedAt > IDLE_GIVE_UP_MS) return;
        pollTimer = setTimeout(checkStatus, delay);
      }

      function post(url, data) {
        return fetch(url, { method: 'POST', body: new URLSearchParams(data), credentials: 'same-origin' })
          .then(function (r) { return r.json(); });
      }

      function resetBtn() {
        var btn = $('flexpay-pay-btn');
        btn.disabled = false;
        btn.textContent = '📱 Send M-Pesa Prompt';
        currentCheckout = null;
        settled = false;
        startedAt = Date.now();
        schedule(IDLE_INTERVAL);
      }

      function checkStatus() {
        if (settled) return;
        var url = CFG.pollUrl + '?invoice_id=' + encodeURIComponent(CFG.invoiceId)
          + '&token=' + encodeURIComponent(CFG.token)
          + '&since=' + encodeURIComponent(CFG.since);
        if (currentCheckout) url += '&checkout_id=' + encodeURIComponent(currentCheckout);

        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (settled) return;
            if (d.paid) {
              settled = true;
              clearTimeout(pollTimer);
              status('success', ICONS.confirmed + ' ' + (d.message || 'Payment confirmed!') + (d.receipt ? ' Receipt: ' + d.receipt + '.' : '') + ' Reloading…');
              setTimeout(function () { window.location.href = CFG.returnUrl; }, 1200);
              return;
            }
            if (d.failed) {
              settled = true;
              clearTimeout(pollTimer);
              status('error', ICONS.failed + ' ' + (d.message || 'Payment declined or cancelled.'));
              resetBtn();
              return;
            }
            if (d.stage === 'review') {
              // Received with the wrong account number: staff will confirm.
              // Keep watching (slowly) so the page updates once they apply it.
              status('info', ICONS.review + ' ' + d.message);
              schedule(IDLE_INTERVAL * 2);
              return;
            }
            if (currentCheckout || d.stage !== 'queued') {
              status('info', (ICONS[d.stage] || ICONS.queued) + ' ' + (d.message || 'Waiting for payment…'));
            }
            schedule(currentCheckout ? FAST_INTERVAL : IDLE_INTERVAL);
          })
          .catch(function () { schedule(currentCheckout ? FAST_INTERVAL : IDLE_INTERVAL); });
      }

      $('flexpay-pay-btn').addEventListener('click', function () {
        var phone = cleanPhone($('flexpay_phone').value.trim());
        if (!phone) {
          status('error', '⚠ Please enter a valid Safaricom phone number (07XX or 01XX).');
          return;
        }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Sending prompt…';
        status('info', 'Contacting Safaricom…');

        post(CFG.checkoutUrl, { invoice_id: CFG.invoiceId, token: CFG.token, phone: phone })
          .then(function (data) {
            if (data.success) {
              currentCheckout = data.checkout_request_id;
              settled = false;
              status('info', '✅ ' + (data.message || 'Prompt sent! Enter your M-Pesa PIN within 60 seconds…'));
              schedule(FAST_INTERVAL);
            } else {
              status('error', '❌ ' + (data.message || 'Failed to send prompt. Try again.'));
              resetBtn();
            }
          })
          .catch(function () {
            status('error', '❌ Network error. Check your connection and try again.');
            resetBtn();
          });
      });

      $('flexpay-verify-toggle').addEventListener('click', function () {
        var box = $('flexpay-verify-box');
        var open = box.style.display !== 'none';
        box.style.display = open ? 'none' : 'block';
        if (!open) $('flexpay_verify_ref').focus();
      });

      $('flexpay-verify-btn').addEventListener('click', function () {
        var reference = $('flexpay_verify_ref').value.replace(/\s+/g, '').toUpperCase();
        if (!/^[A-Z0-9]{8,12}$/.test(reference)) {
          verifyResult('error', 'Please enter the M-Pesa receipt number from your confirmation SMS (e.g. NLJ7RT61SV).');
          return;
        }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Checking…';
        verifyResult('info', 'Checking your payment…');

        post(CFG.verifyUrl, { invoice_id: CFG.invoiceId, token: CFG.token, reference: reference })
          .then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Verify Payment';
            if (data.applied || data.already) {
              verifyResult('success', '✅ ' + data.message);
              settled = false;
              CFG.since = 0; // accept the payment we just applied
              checkStatus();
            } else if (data.success) {
              verifyResult('info', '⌛ ' + data.message);
              settled = false;
              CFG.since = 0;
              startedAt = Date.now();
              schedule(FAST_INTERVAL);
            } else {
              verifyResult('error', data.message || 'We could not verify that payment. Please check the receipt number and try again.');
            }
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Verify Payment';
            verifyResult('error', 'Network error — please check your connection and try again.');
          });
      });

      // Start watching immediately — catches customers who pay from the
      // M-Pesa menu without pressing "Send Prompt".
      schedule(IDLE_INTERVAL);
    }());
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Dynamic QR image (base64 PNG) for this invoice, cached for a day so the
 * invoice page doesn't call Daraja on every view. Returns null on failure —
 * the QR is a convenience, never a blocker.
 */
function flexpay_qr_image(array $params, int $amount, string $accRef, string $payNumber, bool $isTill): ?string
{
    $cacheKey = 'qr_' . substr(hash('sha256', implode('|', [$payNumber, $accRef, $amount, $isTill ? 'BG' : 'PB', ($params['testMode'] ?? '')])), 0, 40);
    $cached   = (string) FlexPayStore::getSetting($cacheKey, '');
    if ($cached === 'fail') {
        return null;
    }
    if ($cached !== '') {
        return $cached;
    }

    $merchant = trim((string) ($params['qrMerchantName'] ?? '')) ?: trim((string) ($params['companyname'] ?? '')) ?: 'Payment';

    $response = DarajaClient::fromGatewayParams($params)->generateQr([
        'merchantName' => $merchant,
        'refNo'        => $accRef,
        'amount'       => $amount,
        'trxCode'      => $isTill ? 'BG' : 'PB',
        'cpi'          => $payNumber,
        'size'         => '300',
    ]);

    $qr = (string) ($response['QRCode'] ?? '');
    if ($qr === '' || strlen($qr) > 500000 || !preg_match('/^[A-Za-z0-9+\/=]+$/', $qr)) {
        FlexPayStore::logApiCall('qr_generate', ['ref' => $accRef, 'amount' => $amount], $response, false, 'system');
        FlexPayStore::setSetting($cacheKey, 'fail'); // don't retry on every page view
        return null;
    }

    FlexPayStore::setSetting($cacheKey, $qr);
    return $qr;
}

// ─── Refund via B2C ───────────────────────────────────────────────────────────
/**
 * Triggered by WHMCS when an admin refunds a paid invoice. B2C is async:
 * "success" here means Safaricom accepted the disbursement; the final
 * outcome arrives at ?route=disbursement_result and is shown on the
 * dashboard's Refunds tab (a failed refund is also written to the WHMCS
 * activity log so it can't go unnoticed).
 */
function flexpay_refund($params)
{
    $license = FlexPayLicense::check($params);
    if (!$license['valid']) {
        return ['status' => 'error', 'rawdata' => 'FlexPay license is not valid — refund blocked. ' . $license['message']];
    }

    $initiator = DarajaClient::initiatorCredentials($params);
    if ($initiator === null) {
        return ['status' => 'error', 'rawdata' => 'B2C refunds need an Initiator Name and Security Credential in the FlexPay gateway settings.'];
    }

    $invoiceId = (int) $params['invoiceid'];
    $origTrans = (string) ($params['transid'] ?? '');

    // Refund amount arrives in the invoice currency; M-Pesa pays whole KES.
    $amount = (float) $params['amount'];
    $invoiceCurrency = FlexPayStore::getInvoiceCurrency($invoiceId);
    if ($invoiceCurrency && strtoupper((string) $invoiceCurrency->code) !== 'KES') {
        $kes = FlexPayStore::getCurrencyByCode('KES');
        if (!$kes) {
            return ['status' => 'error', 'rawdata' => 'Invoice is not in KES and no KES currency is configured in WHMCS — cannot compute the M-Pesa refund amount.'];
        }
        $amount = FlexPayStore::convertAmount($amount, $invoiceCurrency, $kes);
    }
    $amount = (int) round($amount);
    if ($amount < 1) {
        return ['status' => 'error', 'rawdata' => 'Refund amount rounds to less than KES 1.'];
    }

    // Refund to the number that actually paid, not whatever is on the
    // client profile today.
    $original = FlexPayStore::findTransactionByReceipt($origTrans) ?: FlexPayStore::findTransactionByCheckoutId($origTrans);
    $phone    = $original ? DarajaClient::formatPhone((string) $original->phone) : '';
    if ($phone === '') {
        $phone = DarajaClient::formatPhone((string) ($params['clientdetails']['phonenumber'] ?? ''));
    }
    if ($phone === '') {
        return ['status' => 'error', 'rawdata' => 'No valid Safaricom number found for this refund (paying number unknown and client profile phone is not a Kenyan mobile number).'];
    }

    $systemUrl = rtrim((string) $params['systemurl'], '/');
    $response  = DarajaClient::fromGatewayParams($params)->b2cPayment([
        'initiatorName'      => $initiator['name'],
        'securityCredential' => $initiator['credential'],
        'commandId'          => ($params['b2cCommandId'] ?? '') ?: 'BusinessPayment',
        'shortcode'          => DarajaClient::b2cShortcode($params),
        'amount'             => $amount,
        'phone'              => $phone,
        'remarks'            => 'Refund Invoice ' . $invoiceId,
        'occasion'           => 'INV-' . $invoiceId,
        'resultUrl'          => FlexPaySecurity::callbackUrl($systemUrl, 'disbursement_result'),
        'timeoutUrl'         => FlexPaySecurity::callbackUrl($systemUrl, 'disbursement_timeout'),
    ]);

    $success = DarajaClient::isAccepted($response);
    FlexPayStore::logApiCall('b2c', ['invoice' => $invoiceId, 'amount' => $amount, 'phone' => $phone], $response, $success, 'system');

    $conversationId = (string) ($response['ConversationID'] ?? '');
    $originatorId   = (string) ($response['OriginatorConversationID'] ?? '');

    FlexPayStore::recordRefund([
        'invoice_id'                 => $invoiceId,
        'original_trans_id'          => $origTrans,
        'conversation_id'            => $conversationId,
        'originator_conversation_id' => $originatorId,
        'phone'                      => $phone,
        'amount'                     => $amount,
        'status'                     => $success ? 'pending' : 'failed',
        'result_desc'                => substr($success ? 'Accepted by Safaricom — awaiting result.' : DarajaClient::errorMessage($response), 0, 255),
        'initiated_by'               => FlexPayStore::currentAdminUsername(),
    ]);

    if ($success) {
        return [
            'status'  => 'success',
            'rawdata' => $response,
            'transid' => $conversationId ?: $originatorId,
            'fees'    => 0,
        ];
    }

    return ['status' => 'declined', 'rawdata' => $response];
}

// ═════════════════════════════════════════════════════════════════════════════
// Intelligent C2B auto-registration
// ═════════════════════════════════════════════════════════════════════════════
/**
 * Registers C2B URLs whenever the shortcode, domain or environment changes.
 * See FlexPayService::registerC2B() for change detection and backoff.
 */
function flexpay_autoRegisterC2B(array $params, bool $force = false, string $actor = 'system (auto)'): array
{
    return FlexPayService::registerC2B($params, $force, $actor);
}

// ═════════════════════════════════════════════════════════════════════════════
// Customer-facing notices
// ═════════════════════════════════════════════════════════════════════════════
/**
 * Shown instead of the payment widget when FlexPay's license is invalid.
 * Generic wording for customers; the diagnostic detail is on the dashboard.
 */
function flexpay_render_license_block(array $license, array $params): string
{
    // Log at most once an hour — this runs on every invoice view.
    if (time() - (int) FlexPayStore::getSetting('license_block_logged', 0) > 3600) {
        FlexPayStore::setSetting('license_block_logged', time());
        FlexPayStore::logApiCall('license_block', ['status' => $license['status'] ?? 'unknown'], $license, false, 'system');
    }

    return flexpay_render_notice('M-Pesa payment is temporarily unavailable. Please try another payment method, or contact us if this continues.');
}

function flexpay_render_notice(string $message): string
{
    return '<div style="max-width:430px;margin:0 auto;font-family:Arial,sans-serif;">'
        . '<div style="border:1px solid #f5c6cb;background:#fff8f8;border-radius:8px;padding:20px;text-align:center;">'
        . '<div style="font-size:32px;margin-bottom:8px;">&#9888;</div>'
        . '<p style="margin:0;color:#721c24;font-size:14px;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div></div>';
}

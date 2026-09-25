<?php
/**
 * FlexPay (Daraja) Payment Gateway Module for WHMCS
 *
 * A complete Safaricom Daraja integration: STK Push checkout, fully
 * automated C2B (paybill/till) reconciliation, B2C refunds, transaction
 * reversal, transaction status queries, and account balance checks.
 *
 * Callback URLs intentionally avoid the word "mpesa" anywhere in the path
 * or query string — see modules/gateways/callback/flexpay.php and the
 * ?route= parameter values used throughout.
 *
 * File layout (relative to WHMCS root):
 *   modules/gateways/flexpay.php                    ← this file
 *   modules/gateways/callback/flexpay.php            ← Daraja async callbacks
 *   modules/gateways/flexpay/DarajaClient.php        ← shared Daraja API client
 *   modules/gateways/flexpay/FlexPayStore.php        ← shared DB access layer
 *   modules/gateways/flexpay/FlexPayLicense.php      ← commercial license enforcement
 *   modules/gateways/flexpay/checkout.php            ← AJAX STK Push initiator
 *   modules/gateways/flexpay/poll.php                ← AJAX status poller
 *   modules/gateways/flexpay/verify.php              ← customer self-verify endpoint
 *
 * @package   FlexPay\Gateway
 * @author    Editoria Cloud Systems <https://www.editoriaweb.co.ke>
 * @version   3.4.0
 * @link      https://developers.whmcs.com/payment-gateways/
 * @link      https://developer.safaricom.co.ke/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/flexpay/DarajaClient.php';
require_once __DIR__ . '/flexpay/FlexPayStore.php';
require_once __DIR__ . '/flexpay/FlexPayLicense.php';

// ─── MetaData ─────────────────────────────────────────────────────────────────
/**
 * Module metadata. Also the entry point for two pieces of automatic,
 * intelligent setup that require zero manual admin action:
 *
 *   1. Database table creation (FlexPayStore::ensureTables)
 *   2. C2B URL auto-(re)registration whenever the configured shortcode or
 *      system URL changes (flexpay_autoRegisterC2B) — see that function's
 *      docblock for how change detection avoids spamming Safaricom.
 *
 * @return array
 */
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
        'testMode' => [
            'FriendlyName' => 'Sandbox / Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Tick to use the Safaricom Sandbox. No real money moves in sandbox.',
        ],
        'consumerKey' => [
            'FriendlyName' => 'Consumer Key',
            'Type'         => 'text',
            'Size'         => '64',
            'Description'  => 'Daraja App Consumer Key',
        ],
        'consumerSecret' => [
            'FriendlyName' => 'Consumer Secret',
            'Type'         => 'password',
            'Size'         => '64',
            'Description'  => 'Daraja App Consumer Secret',
        ],
        'businessShortcode' => [
            'FriendlyName' => 'Business Shortcode',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Your Paybill or Till number (e.g. 174379)',
        ],
        'passkey' => [
            'FriendlyName' => 'Lipa Na M-Pesa Passkey',
            'Type'         => 'password',
            'Size'         => '100',
            'Description'  => 'STK Push Passkey from the Daraja portal LNM credentials tab',
        ],
        'transactionType' => [
            'FriendlyName' => 'STK Transaction Type',
            'Type'         => 'dropdown',
            'Options'      => 'CustomerPayBillOnline,CustomerBuyGoodsOnline',
            'Default'      => 'CustomerPayBillOnline',
            'Description'  => 'PayBillOnline for paybill numbers, BuyGoodsOnline for till numbers',
        ],
        'accountRefPrefix' => [
            'FriendlyName' => 'Account Reference Prefix',
            'Type'         => 'text',
            'Size'         => '10',
            'Default'      => 'INV',
            'Description'  => 'Prefix used in STK Push account references (e.g. INV → INV-42)',
        ],
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
            'Description'  => 'strict = reject payments whose account reference does not match an open invoice. lenient = accept all, reconcile later in the dashboard.',
        ],
        'b2cShortcode' => [
            'FriendlyName' => 'B2C Shortcode',
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Shortcode used for Business-to-Customer refund disbursements',
        ],
        'b2cInitiatorName' => [
            'FriendlyName' => 'B2C Initiator Name',
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'API operator username configured in the Daraja portal',
        ],
        'b2cSecurityCredential' => [
            'FriendlyName' => 'B2C Security Credential',
            'Type'         => 'password',
            'Size'         => '255',
            'Description'  => 'RSA-encrypted + base64-encoded initiator password (generated on Daraja portal)',
        ],
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

// ─── Payment link (STK Push UI) ───────────────────────────────────────────────
function flexpay_link($params)
{
    $license = FlexPayLicense::check($params);

    if (!$license['valid']) {
        return flexpay_render_license_block($license, $params);
    }

    flexpay_autoRegisterC2B($params);

    $invoiceId  = (int) $params['invoiceid'];
    $amount     = (int) ceil((float) $params['amount']);
    $systemUrl  = rtrim($params['systemurl'], '/');
    $returnUrl  = $params['returnurl'];

    $rawPhone     = $params['clientdetails']['phonenumber'] ?? '';
    $cleanPhone   = DarajaClient::formatPhone($rawPhone);
    $displayPhone = DarajaClient::toDisplayPhone($cleanPhone);

    $shortcode  = $params['businessShortcode'];
    $passkey    = $params['passkey'];
    $txnType    = $params['transactionType'] ?: 'CustomerPayBillOnline';
    $isTill     = ($txnType === 'CustomerBuyGoodsOnline');
    $accPrefix  = $params['accountRefPrefix'] ?: 'INV';
    $accRef     = $accPrefix . '-' . $invoiceId;

    $checkoutUrl = $systemUrl . '/modules/gateways/flexpay/checkout.php';
    $pollUrl     = $systemUrl . '/modules/gateways/flexpay/poll.php';
    $verifyUrl   = $systemUrl . '/modules/gateways/flexpay/verify.php';
    $callbackUrl = $systemUrl . '/modules/gateways/callback/flexpay.php?route=stk_result';

    $csrfToken = hash_hmac('sha256', $invoiceId . '|' . $amount, $passkey);

    ob_start();
    ?>
    <div id="flexpay-widget" style="max-width:430px;margin:0 auto;font-family:Arial,sans-serif;">

      <div style="background:#007229;color:#fff;padding:14px 18px;border-radius:8px 8px 0 0;display:flex;align-items:center;gap:10px;">
        <svg width="32" height="32" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect width="100" height="100" rx="12" fill="#fff"/>
          <text x="50" y="68" text-anchor="middle" font-size="40" font-family="Arial" font-weight="bold" fill="#007229">M</text>
        </svg>
        <span style="font-size:18px;font-weight:700;">Pay with M-Pesa</span>
      </div>

      <div style="border:1px solid #ccc;border-top:none;padding:20px;border-radius:0 0 8px 8px;background:#fff;">

        <p style="margin:0 0 14px;font-size:14px;color:#333;">
          Amount: <strong style="font-size:16px;">KES <?php echo number_format($amount); ?></strong>
        </p>

        <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;color:#333;">
          M-Pesa Phone Number
        </label>
        <input
          type="tel"
          id="flexpay_phone"
          value="<?php echo htmlspecialchars($displayPhone, ENT_QUOTES, 'UTF-8'); ?>"
          placeholder="0712 345 678"
          maxlength="13"
          style="width:100%;padding:9px 12px;border:1px solid #bbb;border-radius:5px;font-size:14px;box-sizing:border-box;"
        />
        <small style="color:#777;font-size:12px;">Safaricom number to receive the payment prompt</small>

        <div id="flexpay-status" style="margin-top:12px;padding:10px 14px;border-radius:5px;font-size:13px;background:#f1f3f4;color:#555;border:1px solid #e0e0e0;">
          &#128270; Watching for your payment — pay via the prompt below, or manually using the <?php echo $isTill ? 'Buy Goods till' : 'Pay Bill'; ?> details. This page updates itself automatically.
        </div>

        <button
          id="flexpay-pay-btn"
          onclick="flexpayInitiate()"
          style="display:block;width:100%;margin-top:16px;padding:11px;background:#007229;color:#fff;
                 border:none;border-radius:5px;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:.3px;"
        >
          &#128241; Send M-Pesa Prompt
        </button>

        <p style="text-align:center;font-size:11px;color:#999;margin:12px 0 0;">
          You will receive a push notification on your phone.<br>
          Enter your M-Pesa PIN to complete payment. Prompt expires in 60 seconds.
        </p>

        <details style="margin-top:14px;font-size:12px;color:#888;">
          <summary style="cursor:pointer;">Prefer to pay manually?</summary>
          <?php if ($isTill): ?>
          <p style="margin:8px 0 0;">
            Go to M-Pesa menu → Lipa na M-Pesa → Buy Goods and Services.<br>
            Till Number: <strong><?php echo htmlspecialchars($shortcode, ENT_QUOTES); ?></strong><br>
            Amount: <strong>KES <?php echo number_format($amount); ?></strong><br>
            <span style="color:#007229;">This page will try to update itself automatically once we detect your payment. If it doesn't within a minute, use "Already Paid?" below.</span>
          </p>
          <?php else: ?>
          <p style="margin:8px 0 0;">
            Go to M-Pesa menu → Lipa Na M-Pesa → Pay Bill.<br>
            Business No: <strong><?php echo htmlspecialchars($shortcode, ENT_QUOTES); ?></strong><br>
            Account No: <strong><?php echo htmlspecialchars($accRef, ENT_QUOTES); ?></strong><br>
            Amount: <strong>KES <?php echo number_format($amount); ?></strong><br>
            <span style="color:#007229;">This page will update itself automatically the moment we receive your payment — no need to reload.</span>
          </p>
          <?php endif; ?>
        </details>

        <div style="margin-top:16px;border-top:1px solid #eee;padding-top:14px;">
          <button
            type="button"
            id="flexpay-verify-toggle"
            onclick="flexpayToggleVerify()"
            style="background:none;border:none;color:#007229;font-size:12px;font-weight:600;cursor:pointer;padding:0;text-decoration:underline;"
          >
            Already paid? Verify your payment
          </button>

          <div id="flexpay-verify-box" style="display:none;margin-top:10px;">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:5px;color:#333;">
              M-Pesa Receipt Number
            </label>
            <input
              type="text"
              id="flexpay_verify_ref"
              placeholder="e.g. NLJ7RT61SV"
              maxlength="40"
              style="width:100%;padding:8px 10px;border:1px solid #bbb;border-radius:5px;font-size:13px;box-sizing:border-box;text-transform:uppercase;"
            />
            <small style="color:#777;font-size:11px;">Find this in the M-Pesa confirmation SMS you received.</small>
            <button
              type="button"
              id="flexpay-verify-btn"
              onclick="flexpayVerifyPayment()"
              style="display:block;width:100%;margin-top:8px;padding:9px;background:#fff;color:#007229;
                     border:1px solid #007229;border-radius:5px;font-size:13px;font-weight:600;cursor:pointer;"
            >
              Verify Payment
            </button>
            <div id="flexpay-verify-result" style="display:none;margin-top:8px;padding:8px 10px;border-radius:5px;font-size:12px;"></div>
          </div>
        </div>
        <p style="text-align:center;font-size:10px;color:#bbb;margin:14px 0 0;">
          Powered by FlexPay &middot; Editoria Cloud Systems
        </p>
      </div>
    </div>

    <script>
    (function () {
      var CHECKOUT_URL = <?php echo json_encode($checkoutUrl); ?>;
      var POLL_URL      = <?php echo json_encode($pollUrl); ?>;
      var VERIFY_URL    = <?php echo json_encode($verifyUrl); ?>;
      var RETURN_URL    = <?php echo json_encode($returnUrl); ?>;
      var INVOICE_ID    = <?php echo json_encode((string) $invoiceId); ?>;
      var AMOUNT        = <?php echo json_encode((string) $amount); ?>;
      var SHORTCODE     = <?php echo json_encode($shortcode); ?>;
      var PASSKEY       = <?php echo json_encode($passkey); ?>;
      var TXN_TYPE      = <?php echo json_encode($txnType); ?>;
      var ACC_REF       = <?php echo json_encode(substr($accRef, 0, 12)); ?>;
      var CALLBACK      = <?php echo json_encode($callbackUrl); ?>;
      var CSRF          = <?php echo json_encode($csrfToken); ?>;

      var pollTimer       = null;
      var currentCheckout = null;   // null until an STK push has been sent
      var settled          = false; // stops polling the instant we have a final answer
      var FAST_INTERVAL   = 3000;   // while actively waiting on an STK prompt
      var IDLE_INTERVAL    = 6000;  // ambient watch for a manual paybill payment

      // Start watching the moment the page loads — this is what catches a
      // customer who pays directly via the M-Pesa menu without ever
      // touching the "Send Prompt" button, and updates the page instantly
      // once Safaricom's C2B confirmation lands, with zero manual reload.
      flexpaySchedule(IDLE_INTERVAL);

      window.flexpayInitiate = function () {
        var rawPhone = document.getElementById('flexpay_phone').value.trim();
        var phone = flexpayCleanPhone(rawPhone);

        if (!phone) {
          flexpayStatus('error', '&#9888; Please enter a valid Safaricom phone number.');
          return;
        }

        var btn = document.getElementById('flexpay-pay-btn');
        btn.disabled = true;
        btn.textContent = 'Sending prompt…';
        flexpayStatus('info', 'Contacting Safaricom…');

        var body = new URLSearchParams({
          invoice_id:   INVOICE_ID,
          amount:       AMOUNT,
          phone:        phone,
          shortcode:    SHORTCODE,
          passkey:      PASSKEY,
          txn_type:     TXN_TYPE,
          acc_ref:      ACC_REF,
          callback_url: CALLBACK,
          csrf:         CSRF
        });

        fetch(CHECKOUT_URL, { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.success) {
              currentCheckout = data.checkout_request_id;
              flexpayStatus('info', '&#9989; ' + (data.message || 'Prompt sent! Enter your M-Pesa PIN within 60 seconds…'));
              clearTimeout(pollTimer);
              flexpaySchedule(FAST_INTERVAL); // switch to fast polling now that a prompt is in flight
            } else {
              flexpayStatus('error', '&#10060; ' + (data.message || 'Failed to send prompt. Try again.'));
              flexpayResetBtn();
            }
          })
          .catch(function () {
            flexpayStatus('error', '&#10060; Network error reaching Safaricom. Check your connection and try again.');
            flexpayResetBtn();
          });
      };

      function flexpaySchedule(delay) {
        if (settled) return;
        clearTimeout(pollTimer);
        pollTimer = setTimeout(flexpayCheckStatus, delay);
      }

      function flexpayCheckStatus() {
        if (settled) return;

        var url = POLL_URL + '?invoice_id=' + encodeURIComponent(INVOICE_ID);
        if (currentCheckout) {
            url += '&checkout_id=' + encodeURIComponent(currentCheckout);
        }

        fetch(url, { cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (settled) return;

            if (d.paid) {
              settled = true;
              clearTimeout(pollTimer);
              var receiptLine = d.receipt ? (' Receipt: ' + d.receipt + '.') : '';
              flexpayStatus('success', '&#127881; ' + (d.message || 'Payment confirmed!') + receiptLine + ' Reloading…');
              // Instant reload — no artificial delay. A brief pause only
              // long enough for the success message to register visually.
              setTimeout(function () { window.location.href = RETURN_URL; }, 600);
              return;
            }

            if (d.failed) {
              settled = true;
              clearTimeout(pollTimer);
              flexpayStatus('error', '&#10060; ' + (d.message || 'Payment declined or cancelled.'));
              flexpayResetBtn();
              return;
            }

            // Still pending — show the real stage/message and keep watching.
            flexpayStatus('info', flexpayStageIcon(d.stage) + ' ' + (d.message || 'Waiting for payment…'));
            flexpaySchedule(currentCheckout ? FAST_INTERVAL : IDLE_INTERVAL);
          })
          .catch(function () {
            // Transient network hiccup — don't alarm the customer, just retry.
            flexpaySchedule(currentCheckout ? FAST_INTERVAL : IDLE_INTERVAL);
          });
      }

      function flexpayStageIcon(stage) {
        var icons = {
          queued:     '&#128270;',
          sent:       '&#128241;',
          processing: '&#9203;',
          confirmed:  '&#127881;',
          failed:     '&#10060;',
          unknown:    '&#128270;'
        };
        return icons[stage] || '&#128270;';
      }

      function flexpayCleanPhone(p) {
        p = p.replace(/\D/g, '');
        if (p.length === 9)                          return '254' + p;
        if (p.length === 10 && p.charAt(0) === '0')  return '254' + p.substring(1);
        if (p.length === 12 && p.substring(0, 3) === '254') return p;
        if (p.length === 13 && p.charAt(0) === '+')  return p.substring(1);
        return '';
      }

      function flexpayStatus(type, msg) {
        var el = document.getElementById('flexpay-status');
        var styles = {
          info:    'background:#e8f4fd;color:#004085;border:1px solid #b8daff;',
          success: 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;',
          error:   'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;',
          warning: 'background:#fff3cd;color:#856404;border:1px solid #ffeeba;'
        };
        el.style.cssText = (styles[type] || styles.info) + 'display:block;border-radius:5px;padding:10px 14px;margin-top:12px;font-size:13px;';
        el.innerHTML = msg;
      }

      function flexpayResetBtn() {
        var btn = document.getElementById('flexpay-pay-btn');
        btn.disabled = false;
        btn.textContent = '\uD83D\uDCF1 Send M-Pesa Prompt';
        settled = false;
        flexpaySchedule(IDLE_INTERVAL); // resume ambient watching after a failure, in case they pay manually instead
      }

      window.flexpayToggleVerify = function () {
        var box = document.getElementById('flexpay-verify-box');
        var isOpen = box.style.display !== 'none';
        box.style.display = isOpen ? 'none' : 'block';
        if (!isOpen) {
          document.getElementById('flexpay_verify_ref').focus();
        }
      };

      window.flexpayVerifyPayment = function () {
        var refInput = document.getElementById('flexpay_verify_ref');
        var reference = refInput.value.trim();
        var resultEl = document.getElementById('flexpay-verify-result');
        var btn = document.getElementById('flexpay-verify-btn');

        if (!reference) {
          flexpayVerifyResult('error', 'Please enter your M-Pesa receipt number.');
          return;
        }

        btn.disabled = true;
        btn.textContent = 'Checking…';
        flexpayVerifyResult('info', 'Checking your payment…');

        var body = new URLSearchParams({
          invoice_id: INVOICE_ID,
          amount:     AMOUNT,
          passkey:    PASSKEY,
          csrf:       CSRF,
          reference:  reference
        });

        fetch(VERIFY_URL, { method: 'POST', body: body })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            btn.disabled = false;
            btn.textContent = 'Verify Payment';

            if (data.applied || (data.success && /already been applied/i.test(data.message || ''))) {
              flexpayVerifyResult('success', '&#9989; ' + data.message);
              // A payment was just applied (or already was) — trigger an
              // immediate status check so the whole page reflects it,
              // same instant-reload behaviour as the main payment flow.
              settled = false;
              flexpayCheckStatus();
            } else if (data.success) {
              flexpayVerifyResult('info', '&#8987; ' + data.message);
            } else {
              flexpayVerifyResult('error', data.message || 'We could not verify that payment. Please check the receipt number and try again.');
            }
          })
          .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Verify Payment';
            flexpayVerifyResult('error', 'Network error — please check your connection and try again.');
          });
      };

      function flexpayVerifyResult(type, msg) {
        var el = document.getElementById('flexpay-verify-result');
        var styles = {
          info:    'background:#e8f4fd;color:#004085;border:1px solid #b8daff;',
          success: 'background:#d4edda;color:#155724;border:1px solid #c3e6cb;',
          error:   'background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;'
        };
        el.style.cssText = (styles[type] || styles.info) + 'display:block;border-radius:5px;padding:8px 10px;margin-top:8px;font-size:12px;';
        el.innerHTML = msg;
      }
    }());
    </script>
    <?php
    return ob_get_clean();
}

// ─── Refund via B2C ───────────────────────────────────────────────────────────
/**
 * Triggered automatically by WHMCS when an admin issues a refund on a
 * paid invoice. Delegates to DarajaClient::b2cPayment and records the
 * attempt in flexpay_refunds for dashboard visibility regardless of the
 * eventual async result.
 *
 * @param array $params
 * @return array
 */
function flexpay_refund($params)
{
    $license = FlexPayLicense::check($params);
    if (!$license['valid']) {
        return ['status' => 'error', 'rawdata' => 'FlexPay license is not valid — refund blocked. ' . $license['message']];
    }

    $invoiceId = (int) $params['invoiceid'];
    $amount    = (int) ceil((float) $params['amount']);
    $phone     = DarajaClient::formatPhone($params['clientdetails']['phonenumber'] ?? '');
    $systemUrl = rtrim($params['systemurl'], '/');
    $origTrans = (string) ($params['transid'] ?? '');

    $b2cShort   = $params['b2cShortcode'] ?: $params['businessShortcode'];
    $resultUrl  = $systemUrl . '/modules/gateways/callback/flexpay.php?route=disbursement_result';
    $timeoutUrl = $systemUrl . '/modules/gateways/callback/flexpay.php?route=disbursement_timeout';

    if (strlen($phone) !== 12) {
        return ['status' => 'error', 'rawdata' => 'Invalid customer phone number: ' . $phone];
    }

    $client = DarajaClient::fromGatewayParams($params);

    $response = $client->b2cPayment([
        'initiatorName'      => $params['b2cInitiatorName'],
        'securityCredential' => $params['b2cSecurityCredential'],
        'shortcode'          => $b2cShort,
        'amount'             => $amount,
        'phone'              => $phone,
        'remarks'            => 'Refund Invoice #' . $invoiceId,
        'occasion'           => 'INV-' . $invoiceId,
        'resultUrl'          => $resultUrl,
        'timeoutUrl'         => $timeoutUrl,
    ]);

    $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';

    FlexPayStore::logApiCall('b2c', ['invoice' => $invoiceId, 'amount' => $amount, 'phone' => $phone], $response, $success, 'system');

    $conversationId = $response['ConversationID'] ?? '';

    FlexPayStore::recordRefund([
        'invoice_id'         => $invoiceId,
        'original_trans_id'  => $origTrans,
        'conversation_id'    => $conversationId,
        'phone'              => $phone,
        'amount'             => $amount,
        'status'             => $success ? 'pending' : 'failed',
        'result_desc'        => $response['ResponseDescription'] ?? ($response['errorMessage'] ?? ''),
        'initiated_by'       => $_SESSION['adminusername'] ?? 'system',
    ]);

    if ($success) {
        return [
            'status'  => 'success',
            'rawdata' => $response,
            'transid' => $conversationId,
            'fees'    => 0,
        ];
    }

    return ['status' => 'declined', 'rawdata' => $response];
}

// ═════════════════════════════════════════════════════════════════════════════
// Intelligent C2B auto-registration
// ═════════════════════════════════════════════════════════════════════════════
/**
 * Automatically (re)registers C2B Validation/Confirmation URLs with Daraja
 * whenever the relevant configuration changes — no manual "click to
 * register" step required, unlike a typical static integration.
 *
 * Change detection: we hash {shortcode}|{systemUrl}|{sandbox-flag} and
 * compare against the last-registered hash stored in flexpay_settings.
 * Registration only fires Safaricom-side when that hash differs, which
 * means:
 *   - First page load after activation → registers once.
 *   - Admin changes shortcode or domain → re-registers automatically
 *     on the next invoice view, with zero manual steps.
 *   - Every other page load → a single fast DB read, no Daraja call.
 *
 * Failures are logged to flexpay_api_log but never interrupt invoice
 * rendering — registration retries automatically on the next load.
 *
 * @param  array $params  WHMCS gateway params
 * @return void
 */
function flexpay_autoRegisterC2B(array $params): void
{
    if (($params['autoRegisterC2B'] ?? 'on') !== 'on') {
        return; // admin explicitly disabled auto-registration
    }

    $shortcode = $params['c2bShortcode'] ?: $params['businessShortcode'];
    if (empty($shortcode) || empty($params['consumerKey']) || empty($params['consumerSecret'])) {
        return; // not configured yet — nothing to register
    }

    $systemUrl = rtrim($params['systemurl'], '/');
    $sandboxFlag = ($params['testMode'] === 'on') ? 'sandbox' : 'live';
    $currentHash = md5($shortcode . '|' . $systemUrl . '|' . $sandboxFlag);

    $lastHash = FlexPayStore::getSetting('c2b_registration_hash');

    if ($lastHash === $currentHash) {
        return; // already registered for this exact configuration
    }

    $client = DarajaClient::fromGatewayParams($params);

    $validationUrl  = $systemUrl . '/modules/gateways/callback/flexpay.php?route=c2b_check';
    $confirmationUrl = $systemUrl . '/modules/gateways/callback/flexpay.php?route=c2b_receipt';

    $response = $client->c2bRegisterUrl([
        'shortcode'       => $shortcode,
        'responseType'    => 'Completed',
        'validationUrl'   => $validationUrl,
        'confirmationUrl' => $confirmationUrl,
    ]);

    $success = isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';

    FlexPayStore::logApiCall(
        'c2b_register',
        ['shortcode' => $shortcode, 'validationUrl' => $validationUrl, 'confirmationUrl' => $confirmationUrl],
        $response,
        $success,
        'system (auto)'
    );

    // Only remember success — a failed attempt should retry on next load
    // rather than being silently treated as "done".
    if ($success) {
        FlexPayStore::setSetting('c2b_registration_hash', $currentHash);
        FlexPayStore::setSetting('c2b_registration_last_success', date('Y-m-d H:i:s'));
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// License enforcement display
// ═════════════════════════════════════════════════════════════════════════════
/**
 * Renders a clear, non-technical message in place of the payment widget
 * when FlexPay's own commercial license is invalid, expired, suspended,
 * or unconfigured. Shown to the SITE ADMIN's customers, so the wording
 * is deliberately generic (never exposes licensing internals) while the
 * real diagnostic detail goes to the admin via the dashboard instead.
 *
 * @param  array $license  Result from FlexPayLicense::check()
 * @param  array $params   WHMCS gateway params
 * @return string
 */
function flexpay_render_license_block(array $license, array $params): string
{
    FlexPayStore::logApiCall('license_block', ['status' => $license['status'] ?? 'unknown'], $license, false, 'system');

    ob_start();
    ?>
    <div style="max-width:430px;margin:0 auto;font-family:Arial,sans-serif;">
        <div style="border:1px solid #f5c6cb;background:#fff8f8;border-radius:8px;padding:20px;text-align:center;">
            <div style="font-size:32px;margin-bottom:8px;">&#9888;</div>
            <p style="margin:0;color:#721c24;font-size:14px;">
                M-Pesa payment is temporarily unavailable. Please try another payment method, or contact us if this continues.
            </p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

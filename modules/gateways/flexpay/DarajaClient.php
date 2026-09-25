<?php
/**
 * DarajaClient — Core Safaricom Daraja API client.
 *
 * Single source of truth for every Daraja operation used across the
 * FlexPay gateway module and the FlexPay Dashboard addon module:
 *
 *   - OAuth token acquisition + caching
 *   - STK Push (Lipa Na M-Pesa Online)              + status query
 *   - C2B URL registration                          + simulate (sandbox)
 *   - B2C (business payment / refund disbursement)
 *   - Transaction Reversal
 *   - Transaction Status Query
 *   - Account Balance Query
 *
 * Both the gateway module (modules/gateways/flexpay.php) and the
 * dashboard addon (modules/addons/flexpay_dashboard/flexpay_dashboard.php)
 * load this same file, so credentials, retry logic, and error handling
 * never drift between the two.
 *
 * @package   FlexPay\Daraja
 * @version   3.0.0
 * @link      https://developer.safaricom.co.ke/Documentation
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class DarajaClient
{
    private const SANDBOX_URL = 'https://sandbox.safaricom.co.ke';
    private const LIVE_URL    = 'https://api.safaricom.co.ke';

    /**
     * Official Safaricom Daraja outbound IP ranges that send callbacks.
     * Used by FlexPayCallbackGuard to optionally restrict inbound requests.
     * Source: Safaricom Daraja documentation / community-verified IP list.
     *
     * @var string[]
     */
    public const SAFARICOM_CALLBACK_IPS = [
        '196.201.214.200',
        '196.201.214.206',
        '196.201.213.114',
        '196.201.214.207',
        '196.201.214.208',
        '196.201.213.44',
        '196.201.212.127',
        '196.201.212.128',
        '196.201.212.129',
        '196.201.212.132',
        '196.201.212.136',
        '196.201.212.138',
    ];

    private bool   $sandbox;
    private string $consumerKey;
    private string $consumerSecret;

    public function __construct(string $consumerKey, string $consumerSecret, bool $sandbox = true)
    {
        $this->consumerKey    = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        $this->sandbox        = $sandbox;
    }

    /**
     * Factory: build a client directly from WHMCS gateway parameters.
     *
     * @param  array $gw  Result of getGatewayVariables('flexpay')
     * @return self
     */
    public static function fromGatewayParams(array $gw): self
    {
        return new self(
            (string) ($gw['consumerKey'] ?? ''),
            (string) ($gw['consumerSecret'] ?? ''),
            (($gw['testMode'] ?? '') === 'on')
        );
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_URL : self::LIVE_URL;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    // ─────────────────────────────────────────────────────────────────────
    // OAuth
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Obtain a cached or fresh OAuth 2.0 Bearer token.
     *
     * @return string|null  Null on failure (logged internally by caller)
     */
    public function getAccessToken(): ?string
    {
        $cacheKey  = md5($this->consumerKey . '|' . ($this->sandbox ? 'sbx' : 'live'));
        $cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'flexpay_tok_' . $cacheKey . '.json';

        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (!empty($cached['token']) && !empty($cached['exp']) && time() < (int) $cached['exp'] - 60) {
                return $cached['token'];
            }
        }

        $credential = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

        $result = $this->curlGet(
            $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization: Basic ' . $credential]
        );

        if (empty($result['access_token'])) {
            return null;
        }

        @file_put_contents($cacheFile, json_encode([
            'token' => $result['access_token'],
            'exp'   => time() + (int) ($result['expires_in'] ?? 3600),
        ]));

        return $result['access_token'];
    }

    // ─────────────────────────────────────────────────────────────────────
    // STK Push (Lipa Na M-Pesa Online)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Initiate an STK Push prompt to a customer's phone.
     *
     * @param  array $opts  shortcode, passkey, amount, phone, txnType,
     *                      accountRef, transactionDesc, callbackUrl
     * @return array        Decoded Daraja response
     */
    public function stkPush(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $timestamp = date('YmdHis');
        $password  = base64_encode($opts['shortcode'] . $opts['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $opts['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => $opts['txnType'] ?? 'CustomerPayBillOnline',
            'Amount'            => (int) $opts['amount'],
            'PartyA'            => $opts['phone'],
            'PartyB'            => $opts['shortcode'],
            'PhoneNumber'       => $opts['phone'],
            'CallBackURL'       => $opts['callbackUrl'],
            'AccountReference'  => substr((string) $opts['accountRef'], 0, 12),
            'TransactionDesc'   => substr((string) ($opts['transactionDesc'] ?? 'Payment'), 0, 13),
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/stkpush/v1/processrequest', $token, $payload);
    }

    /**
     * Query the live status of a previously-initiated STK Push.
     *
     * @param  array $opts  shortcode, passkey, checkoutRequestId
     * @return array
     */
    public function stkQuery(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $timestamp = date('YmdHis');
        $password  = base64_encode($opts['shortcode'] . $opts['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $opts['shortcode'],
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $opts['checkoutRequestId'],
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/stkpushquery/v1/query', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // C2B (Customer to Business — paybill/till manual payments)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Register Validation + Confirmation URLs for C2B payments.
     * Idempotent — safe to call repeatedly (e.g. on every module load,
     * guarded by a "last registered" timestamp check by the caller).
     *
     * @param  array $opts  shortcode, validationUrl, confirmationUrl, responseType
     * @return array
     */
    public function c2bRegisterUrl(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'ShortCode'       => $opts['shortcode'],
            'ResponseType'    => $opts['responseType'] ?? 'Completed',
            'ConfirmationURL' => $opts['confirmationUrl'],
            'ValidationURL'   => $opts['validationUrl'],
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/c2b/v2/registerurl', $token, $payload);
    }

    /**
     * Simulate an incoming C2B payment. Sandbox only — Safaricom rejects
     * this call in production. Useful for the dashboard's "Test C2B" tool.
     *
     * @param  array $opts  shortcode, amount, phone, billRefNumber, commandId
     * @return array
     */
    public function c2bSimulate(array $opts): array
    {
        if (!$this->sandbox) {
            return ['_error' => 'simulate_not_allowed_in_production'];
        }

        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'ShortCode'     => $opts['shortcode'],
            'CommandID'     => $opts['commandId'] ?? 'CustomerPayBillOnline',
            'Amount'        => (int) $opts['amount'],
            'Msisdn'        => $opts['phone'],
            'BillRefNumber' => $opts['billRefNumber'] ?? '',
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/c2b/v2/simulate', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // B2C (Business to Customer — refunds / disbursements)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Send a B2C payment (e.g. refund) to a customer's phone.
     *
     * @param  array $opts  initiatorName, securityCredential, shortcode,
     *                      amount, phone, remarks, occasion, commandId,
     *                      resultUrl, timeoutUrl
     * @return array
     */
    public function b2cPayment(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'InitiatorName'      => $opts['initiatorName'],
            'SecurityCredential' => $opts['securityCredential'],
            'CommandID'          => $opts['commandId'] ?? 'BusinessPayment',
            'Amount'             => (int) $opts['amount'],
            'PartyA'             => $opts['shortcode'],
            'PartyB'             => $opts['phone'],
            'Remarks'            => substr((string) ($opts['remarks'] ?? 'Payment'), 0, 100),
            'QueueTimeOutURL'    => $opts['timeoutUrl'],
            'ResultURL'          => $opts['resultUrl'],
            'Occasion'           => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/b2c/v3/paymentrequest', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transaction Reversal
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reverse a completed transaction (full or partial amount).
     *
     * @param  array $opts  initiatorName, securityCredential, transactionId,
     *                      amount, receiverParty, receiverIdentifierType,
     *                      remarks, occasion, resultUrl, timeoutUrl
     * @return array
     */
    public function reverseTransaction(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'Initiator'              => $opts['initiatorName'],
            'SecurityCredential'     => $opts['securityCredential'],
            'CommandID'              => 'TransactionReversal',
            'TransactionID'          => $opts['transactionId'],
            'Amount'                 => (int) $opts['amount'],
            'ReceiverParty'          => $opts['receiverParty'],
            'RecieverIdentifierType' => (string) ($opts['receiverIdentifierType'] ?? '11'),
            'ResultURL'              => $opts['resultUrl'],
            'QueueTimeOutURL'        => $opts['timeoutUrl'],
            'Remarks'                => substr((string) ($opts['remarks'] ?? 'Reversal'), 0, 100),
            'Occasion'               => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/reversal/v1/request', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transaction Status Query
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Query the current status of any M-Pesa transaction by receipt number.
     *
     * @param  array $opts  initiatorName, securityCredential, transactionId,
     *                      partyA (shortcode), identifierType, resultUrl, timeoutUrl
     * @return array
     */
    public function transactionStatus(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'Initiator'          => $opts['initiatorName'],
            'SecurityCredential' => $opts['securityCredential'],
            'CommandID'          => 'TransactionStatusQuery',
            'TransactionID'      => $opts['transactionId'],
            'PartyA'             => $opts['partyA'],
            'IdentifierType'     => (string) ($opts['identifierType'] ?? '4'),
            'ResultURL'          => $opts['resultUrl'],
            'QueueTimeOutURL'    => $opts['timeoutUrl'],
            'Remarks'            => substr((string) ($opts['remarks'] ?? 'Status check'), 0, 100),
            'Occasion'           => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/transactionstatus/v1/query', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Account Balance Query
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Request the current account balance for the business shortcode.
     * Response arrives asynchronously at resultUrl.
     *
     * @param  array $opts  initiatorName, securityCredential, partyA,
     *                      identifierType, resultUrl, timeoutUrl
     * @return array
     */
    public function accountBalance(array $opts): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed'];
        }

        $payload = [
            'Initiator'          => $opts['initiatorName'],
            'SecurityCredential' => $opts['securityCredential'],
            'CommandID'          => 'AccountBalance',
            'PartyA'             => $opts['partyA'],
            'IdentifierType'     => (string) ($opts['identifierType'] ?? '4'),
            'Remarks'            => substr((string) ($opts['remarks'] ?? 'Balance check'), 0, 100),
            'QueueTimeOutURL'    => $opts['timeoutUrl'],
            'ResultURL'          => $opts['resultUrl'],
        ];

        return $this->curlPost($this->baseUrl() . '/mpesa/accountbalance/v1/query', $token, $payload);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP transport helpers
    // ─────────────────────────────────────────────────────────────────────

    private function curlGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'FlexPay-Daraja-Client/3.0',
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            return ['_curl_error' => $err ?: 'unknown_curl_error'];
        }

        return json_decode($raw, true) ?? ['_raw' => $raw];
    }

    private function curlPost(string $url, string $token, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'FlexPay-Daraja-Client/3.0',
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            return ['_curl_error' => $err ?: 'unknown_curl_error'];
        }

        return json_decode($raw, true) ?? ['_raw' => $raw];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Static utility helpers (phone formatting, etc.) — used everywhere
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalize any Kenyan phone format to 2547XXXXXXXX / 2541XXXXXXXX.
     *
     * @param  string $phone
     * @return string  Empty string if unrecognisable
     */
    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 9 && $digits[0] !== '0') {
            return '254' . $digits;
        }
        if (strlen($digits) === 10 && $digits[0] === '0') {
            return '254' . substr($digits, 1);
        }
        if (strlen($digits) === 12 && substr($digits, 0, 3) === '254') {
            return $digits;
        }
        if (strlen($digits) === 13 && $digits[0] === '+') {
            return substr($digits, 1);
        }

        return '';
    }

    /**
     * Convert 2547XXXXXXXX back to local display format 07XXXXXXXX.
     */
    public static function toDisplayPhone(string $phone): string
    {
        if (strncmp($phone, '254', 3) === 0) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }

    /**
     * Human-readable description for a known Daraja ResultCode.
     * Covers the most common STK/B2C/Reversal result codes.
     *
     * @param  int|string $code
     * @return string
     */
    public static function describeResultCode($code): string
    {
        $map = [
            '0'    => 'Success',
            '1'    => 'Insufficient balance in the customer account',
            '1032' => 'Request cancelled by the user',
            '1037' => 'No response from the user (timeout)',
            '1025' => 'An error occurred while sending the push request',
            '2001' => 'Invalid initiator information',
            '1019' => 'Transaction has expired',
            '1001' => 'Unable to lock subscriber, a transaction is already in process for the current subscriber',
            '17'   => 'Internal failure — please try again',
            '20'   => 'Unresolved system error — please try again',
            '26'   => 'Invalid amount',
            '2'    => 'Less funds than the transaction amount',
        ];

        return $map[(string) $code] ?? ('Unknown result code: ' . $code);
    }
}

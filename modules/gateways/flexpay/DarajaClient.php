<?php
/**
 * DarajaClient — Core Safaricom Daraja API client.
 *
 * Single source of truth for every Daraja operation used across the
 * FlexPay gateway module and the FlexPay Dashboard addon module:
 *
 *   - OAuth token acquisition + caching (encrypted, in the database)
 *   - STK Push (Lipa Na M-Pesa Online)              + status query
 *   - C2B URL registration                          + simulate (sandbox)
 *   - B2C (business payment / refund disbursement, v3)
 *   - Transaction Reversal
 *   - Transaction Status Query
 *   - Account Balance Query
 *   - Dynamic QR code generation
 *
 * @package   FlexPay\Daraja
 * @version   3.6.0
 * @link      https://developer.safaricom.co.ke/Documentation
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class DarajaClient
{
    private const SANDBOX_URL = 'https://sandbox.safaricom.co.ke';
    private const LIVE_URL    = 'https://api.safaricom.co.ke';
    private const USER_AGENT  = 'FlexPay-Daraja-Client/3.6';

    /** @deprecated Use FlexPaySecurity::SAFARICOM_CALLBACK_IPS */
    public const SAFARICOM_CALLBACK_IPS = FlexPaySecurity::SAFARICOM_CALLBACK_IPS;

    private bool   $sandbox;
    private string $consumerKey;
    private string $consumerSecret;
    private ?string $memoryToken = null;

    /** @var callable|null  Test hook: fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private static $transport = null;

    public function __construct(string $consumerKey, string $consumerSecret, bool $sandbox = true)
    {
        $this->consumerKey    = trim($consumerKey);
        $this->consumerSecret = trim($consumerSecret);
        $this->sandbox        = $sandbox;
    }

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

    /** Test hook: route all HTTP through a fake transport. Pass null to restore cURL. */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gateway-config helpers shared by every caller
    // ─────────────────────────────────────────────────────────────────────

    /** Shortcode that receives C2B/paybill payments (and is queried for them). */
    public static function c2bShortcode(array $gw): string
    {
        return trim((string) (($gw['c2bShortcode'] ?? '') ?: ($gw['businessShortcode'] ?? '')));
    }

    /** Shortcode B2C refunds are paid from. */
    public static function b2cShortcode(array $gw): string
    {
        return trim((string) (($gw['b2cShortcode'] ?? '') ?: ($gw['businessShortcode'] ?? '')));
    }

    /**
     * For Buy Goods (till) STK pushes Safaricom expects BusinessShortCode =
     * the store/head-office number and PartyB = the till number. For paybill
     * both are the paybill number.
     */
    public static function stkPartyB(array $gw): string
    {
        $till = trim((string) ($gw['stkPartyB'] ?? ''));
        return $till !== '' ? $till : trim((string) ($gw['businessShortcode'] ?? ''));
    }

    /**
     * Initiator name + security credential for B2C/Reversal/Status/Balance.
     * Uses the pre-encrypted credential if provided, otherwise encrypts the
     * initiator password with the pasted Safaricom certificate.
     *
     * @return array{name:string, credential:string}|null
     */
    public static function initiatorCredentials(array $gw): ?array
    {
        $name = trim((string) ($gw['b2cInitiatorName'] ?? ''));
        if ($name === '') {
            return null;
        }

        $credential = trim((string) ($gw['b2cSecurityCredential'] ?? ''));
        if ($credential === '') {
            $credential = (string) self::securityCredentialFromPassword(
                (string) ($gw['initiatorPassword'] ?? ''),
                (string) ($gw['initiatorCertificate'] ?? '')
            );
        }

        return $credential !== '' ? ['name' => $name, 'credential' => $credential] : null;
    }

    /**
     * Encrypt the initiator password with Safaricom's public certificate
     * (RSA PKCS#1 v1.5, base64) — the same thing the Daraja portal's
     * "generate security credential" tool does.
     */
    public static function securityCredentialFromPassword(string $password, string $certificatePem): ?string
    {
        if ($password === '' || trim($certificatePem) === '' || !function_exists('openssl_public_encrypt')) {
            return null;
        }

        $pem = trim($certificatePem);
        if (strpos($pem, '-----BEGIN') === false) {
            $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(preg_replace('/\s+/', '', $pem), 64, "\n") . "-----END CERTIFICATE-----";
        }

        $key = @openssl_pkey_get_public($pem);
        if ($key === false) {
            return null;
        }

        $encrypted = '';
        if (!@openssl_public_encrypt($password, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            return null;
        }

        return base64_encode($encrypted);
    }

    /** Daraja accepted an async request (ResponseCode "0"). */
    public static function isAccepted(array $response): bool
    {
        return isset($response['ResponseCode']) && (string) $response['ResponseCode'] === '0';
    }

    /** Best human-readable error from any Daraja/transport failure shape. */
    public static function errorMessage(array $response, string $fallback = 'Request failed.'): string
    {
        foreach (['errorMessage', 'ResponseDescription', 'ResultDesc', 'error_description', '_curl_error'] as $k) {
            if (!empty($response[$k]) && is_string($response[$k])) {
                return $response[$k];
            }
        }
        if (($response['_error'] ?? '') === 'oauth_failed') {
            return 'Could not authenticate with Daraja — check the Consumer Key/Secret and Sandbox setting.';
        }
        return $fallback;
    }

    /** Timestamp in Safaricom's timezone, as their password check expects. */
    public static function timestamp(): string
    {
        return (new \DateTime('now', new \DateTimeZone('Africa/Nairobi')))->format('YmdHis');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    // ─────────────────────────────────────────────────────────────────────
    // OAuth
    // ─────────────────────────────────────────────────────────────────────

    private function tokenCacheKey(): string
    {
        return 'oauth_' . substr(hash('sha256', $this->consumerKey . '|' . ($this->sandbox ? 'sbx' : 'live')), 0, 32);
    }

    /**
     * Obtain a cached or fresh OAuth bearer token.
     *
     * v3.4 cached tokens as plain JSON files in sys_get_temp_dir() — on
     * shared hosting that's readable by every other account on the server.
     * Tokens now live in flexpay_settings, encrypted with WHMCS's own
     * encrypt() when available.
     */
    public function getAccessToken(bool $forceRefresh = false): ?string
    {
        if ($this->consumerKey === '' || $this->consumerSecret === '') {
            return null;
        }

        if (!$forceRefresh && $this->memoryToken !== null) {
            return $this->memoryToken;
        }

        $cacheKey = $this->tokenCacheKey();

        if (!$forceRefresh && class_exists('FlexPayStore')) {
            $raw    = (string) FlexPayStore::getSetting($cacheKey, '');
            $cached = json_decode(self::unprotect($raw), true);
            if (!empty($cached['token']) && !empty($cached['exp']) && time() < (int) $cached['exp'] - 60) {
                return $this->memoryToken = (string) $cached['token'];
            }
        }

        [$status, $body] = $this->request(
            'GET',
            $this->baseUrl() . '/oauth/v1/generate?grant_type=client_credentials',
            ['Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret)],
            null,
            2
        );

        $result = json_decode((string) $body, true);
        if (!is_array($result) || empty($result['access_token'])) {
            return null;
        }

        $token = (string) $result['access_token'];
        if (class_exists('FlexPayStore')) {
            FlexPayStore::setSetting($cacheKey, self::protect(json_encode([
                'token' => $token,
                'exp'   => time() + max(60, (int) ($result['expires_in'] ?? 3599)),
            ])));
        }

        return $this->memoryToken = $token;
    }

    private static function protect(string $plain): string
    {
        if (function_exists('encrypt')) {
            try {
                return 'enc:' . encrypt($plain);
            } catch (\Throwable $e) {
                // fall through
            }
        }
        return $plain;
    }

    private static function unprotect(string $stored): string
    {
        if (strncmp($stored, 'enc:', 4) === 0) {
            if (!function_exists('decrypt')) {
                return '';
            }
            try {
                return (string) decrypt(substr($stored, 4));
            } catch (\Throwable $e) {
                return '';
            }
        }
        return $stored;
    }

    // ─────────────────────────────────────────────────────────────────────
    // STK Push (Lipa Na M-Pesa Online)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array $opts  shortcode, passkey, amount, phone, txnType, partyB,
     *                     accountRef, transactionDesc, callbackUrl
     */
    public function stkPush(array $opts): array
    {
        $timestamp = self::timestamp();

        $payload = [
            'BusinessShortCode' => (string) $opts['shortcode'],
            'Password'          => base64_encode($opts['shortcode'] . $opts['passkey'] . $timestamp),
            'Timestamp'         => $timestamp,
            'TransactionType'   => $opts['txnType'] ?? 'CustomerPayBillOnline',
            'Amount'            => (int) $opts['amount'],
            'PartyA'            => (string) $opts['phone'],
            'PartyB'            => (string) (($opts['partyB'] ?? '') ?: $opts['shortcode']),
            'PhoneNumber'       => (string) $opts['phone'],
            'CallBackURL'       => (string) $opts['callbackUrl'],
            'AccountReference'  => substr((string) $opts['accountRef'], 0, 12),
            'TransactionDesc'   => substr((string) ($opts['transactionDesc'] ?? 'Payment'), 0, 13),
        ];

        // Never retried automatically: a retry could send the customer a
        // second PIN prompt for the same invoice.
        return $this->post('/mpesa/stkpush/v1/processrequest', $payload, 0);
    }

    /** @param array $opts  shortcode, passkey, checkoutRequestId */
    public function stkQuery(array $opts): array
    {
        $timestamp = self::timestamp();

        return $this->post('/mpesa/stkpushquery/v1/query', [
            'BusinessShortCode' => (string) $opts['shortcode'],
            'Password'          => base64_encode($opts['shortcode'] . $opts['passkey'] . $timestamp),
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => (string) $opts['checkoutRequestId'],
        ], 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // C2B
    // ─────────────────────────────────────────────────────────────────────

    /** @param array $opts  shortcode, validationUrl, confirmationUrl, responseType */
    public function c2bRegisterUrl(array $opts): array
    {
        return $this->post('/mpesa/c2b/v2/registerurl', [
            'ShortCode'       => (string) $opts['shortcode'],
            'ResponseType'    => $opts['responseType'] ?? 'Completed',
            'ConfirmationURL' => (string) $opts['confirmationUrl'],
            'ValidationURL'   => (string) $opts['validationUrl'],
        ], 1);
    }

    /** Sandbox only. @param array $opts shortcode, amount, phone, billRefNumber, commandId */
    public function c2bSimulate(array $opts): array
    {
        if (!$this->sandbox) {
            return ['_error' => 'simulate_not_allowed_in_production', 'errorMessage' => 'C2B simulation is only available in the sandbox.'];
        }

        return $this->post('/mpesa/c2b/v2/simulate', [
            'ShortCode'     => (string) $opts['shortcode'],
            'CommandID'     => $opts['commandId'] ?? 'CustomerPayBillOnline',
            'Amount'        => (int) $opts['amount'],
            'Msisdn'        => (string) $opts['phone'],
            'BillRefNumber' => (string) ($opts['billRefNumber'] ?? ''),
        ], 0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // B2C
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array $opts  initiatorName, securityCredential, shortcode, amount,
     *                     phone, remarks, occasion, commandId, resultUrl,
     *                     timeoutUrl, originatorConversationId (generated if absent)
     */
    public function b2cPayment(array $opts): array
    {
        $originatorId = (string) (($opts['originatorConversationId'] ?? '') ?: self::uuid());

        $response = $this->post('/mpesa/b2c/v3/paymentrequest', [
            'OriginatorConversationID' => $originatorId,
            'InitiatorName'            => (string) $opts['initiatorName'],
            'SecurityCredential'       => (string) $opts['securityCredential'],
            'CommandID'                => $opts['commandId'] ?? 'BusinessPayment',
            'Amount'                   => (int) $opts['amount'],
            'PartyA'                   => (string) $opts['shortcode'],
            'PartyB'                   => (string) $opts['phone'],
            'Remarks'                  => substr((string) ($opts['remarks'] ?? 'Payment'), 0, 100),
            'QueueTimeOutURL'          => (string) $opts['timeoutUrl'],
            'ResultURL'                => (string) $opts['resultUrl'],
            'Occasion'                 => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ], 0);

        if (empty($response['OriginatorConversationID'])) {
            $response['OriginatorConversationID'] = $originatorId;
        }

        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transaction Reversal
    // ─────────────────────────────────────────────────────────────────────

    public function reverseTransaction(array $opts): array
    {
        return $this->post('/mpesa/reversal/v1/request', [
            'Initiator'              => (string) $opts['initiatorName'],
            'SecurityCredential'     => (string) $opts['securityCredential'],
            'CommandID'              => 'TransactionReversal',
            'TransactionID'          => (string) $opts['transactionId'],
            'Amount'                 => (int) $opts['amount'],
            'ReceiverParty'          => (string) $opts['receiverParty'],
            // Daraja's own spelling of this field is "Reciever".
            'RecieverIdentifierType' => (string) ($opts['receiverIdentifierType'] ?? '11'),
            'ResultURL'              => (string) $opts['resultUrl'],
            'QueueTimeOutURL'        => (string) $opts['timeoutUrl'],
            'Remarks'                => substr((string) ($opts['remarks'] ?? 'Reversal'), 0, 100),
            'Occasion'               => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ], 0);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transaction Status Query
    // ─────────────────────────────────────────────────────────────────────

    public function transactionStatus(array $opts): array
    {
        return $this->post('/mpesa/transactionstatus/v1/query', [
            'Initiator'          => (string) $opts['initiatorName'],
            'SecurityCredential' => (string) $opts['securityCredential'],
            'CommandID'          => 'TransactionStatusQuery',
            'TransactionID'      => (string) $opts['transactionId'],
            'PartyA'             => (string) $opts['partyA'],
            'IdentifierType'     => (string) ($opts['identifierType'] ?? '4'),
            'ResultURL'          => (string) $opts['resultUrl'],
            'QueueTimeOutURL'    => (string) $opts['timeoutUrl'],
            'Remarks'            => substr((string) ($opts['remarks'] ?? 'Status check'), 0, 100),
            'Occasion'           => substr((string) ($opts['occasion'] ?? ''), 0, 100),
        ], 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Account Balance Query
    // ─────────────────────────────────────────────────────────────────────

    public function accountBalance(array $opts): array
    {
        return $this->post('/mpesa/accountbalance/v1/query', [
            'Initiator'          => (string) $opts['initiatorName'],
            'SecurityCredential' => (string) $opts['securityCredential'],
            'CommandID'          => 'AccountBalance',
            'PartyA'             => (string) $opts['partyA'],
            'IdentifierType'     => (string) ($opts['identifierType'] ?? '4'),
            'Remarks'            => substr((string) ($opts['remarks'] ?? 'Balance check'), 0, 100),
            'QueueTimeOutURL'    => (string) $opts['timeoutUrl'],
            'ResultURL'          => (string) $opts['resultUrl'],
        ], 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dynamic QR
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Generate a Dynamic M-Pesa QR code the customer scans in the M-Pesa app.
     *
     * @param array $opts merchantName, refNo, amount, trxCode (PB|BG), cpi, size
     * @return array  Includes 'QRCode' (base64 PNG) on success
     */
    public function generateQr(array $opts): array
    {
        return $this->post('/mpesa/qrcode/v1/generate', [
            'MerchantName' => substr((string) $opts['merchantName'], 0, 50),
            'RefNo'        => substr((string) $opts['refNo'], 0, 12),
            'Amount'       => (int) $opts['amount'],
            'TrxCode'      => (string) ($opts['trxCode'] ?? 'PB'),
            'CPI'          => (string) $opts['cpi'],
            'Size'         => (string) ($opts['size'] ?? '300'),
        ], 1);
    }

    // ─────────────────────────────────────────────────────────────────────
    // HTTP transport
    // ─────────────────────────────────────────────────────────────────────

    /**
     * POST a JSON payload with a bearer token. Refreshes the token once if
     * Daraja reports it invalid/expired; retries transient failures
     * (network errors, 5xx) up to $retries times for idempotent calls.
     */
    private function post(string $path, array $payload, int $retries): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            return ['_error' => 'oauth_failed', 'errorMessage' => self::errorMessage(['_error' => 'oauth_failed'])];
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            [$status, $raw] = $this->request('POST', $this->baseUrl() . $path, [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ], $body, $retries);

            if ($raw === null) {
                return ['_curl_error' => 'Could not reach Safaricom (network error).', '_http_code' => $status];
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $decoded = ['_raw' => substr($raw, 0, 2000), 'errorMessage' => 'Unexpected response from Safaricom (HTTP ' . $status . ').'];
            }
            $decoded['_http_code'] = $status;

            self::moduleLog($path, $payload, $decoded, $token);

            $tokenRejected = $status === 401
                || in_array((string) ($decoded['errorCode'] ?? ''), ['404.001.03', '401.003.01'], true);

            if ($tokenRejected && $attempt === 0) {
                $token = $this->getAccessToken(true);
                if (!$token) {
                    return ['_error' => 'oauth_failed', 'errorMessage' => self::errorMessage(['_error' => 'oauth_failed'])];
                }
                continue;
            }

            return $decoded;
        }

        return ['errorMessage' => 'Daraja rejected the access token.'];
    }

    /**
     * Record the call in WHMCS's Module Log (Utilities → Logs → Module Log;
     * only written while module debug logging is switched on). Credentials
     * are redacted before logging and also passed as WHMCS "replace vars".
     */
    private static function moduleLog(string $path, array $payload, array $response, string $token): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $redacted = class_exists('FlexPayStore') ? FlexPayStore::redact($payload) : $payload;
        $secrets  = array_values(array_filter([
            $token, $payload['Password'] ?? null, $payload['SecurityCredential'] ?? null,
        ]));
        try {
            logModuleCall('flexpay', $path, $redacted, $response, $response, $secrets);
        } catch (\Throwable $e) {
            // logging must never break a payment
        }
    }

    /**
     * @return array{0:int, 1:?string}  [HTTP status, body|null on network failure]
     */
    private function request(string $method, string $url, array $headers, ?string $body, int $retries): array
    {
        $attempt = 0;
        do {
            if (self::$transport !== null) {
                [$status, $raw] = call_user_func(self::$transport, $method, $url, $headers, $body);
            } else {
                [$status, $raw] = self::curl($method, $url, $headers, $body);
            }

            $transient = $raw === null || $status >= 500 || $status === 429;
            if (!$transient || $attempt >= $retries) {
                return [$status, $raw];
            }

            usleep((int) (250000 * (2 ** $attempt)));
            $attempt++;
        } while (true);
    }

    private static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = (string) $body;
        }
        curl_setopt_array($ch, $opts);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $raw === false) {
            return [$status, null];
        }
        return [$status, (string) $raw];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Static utility helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalize a Kenyan mobile number to 2547XXXXXXXX / 2541XXXXXXXX.
     * Returns '' for anything that isn't a Kenyan mobile number.
     */
    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 9 && in_array($digits[0], ['7', '1'], true)) {
            $digits = '254' . $digits;
        } elseif (strlen($digits) === 10 && $digits[0] === '0') {
            $digits = '254' . substr($digits, 1);
        }

        return preg_match('/^254[17]\d{8}$/', $digits) ? $digits : '';
    }

    public static function toDisplayPhone(string $phone): string
    {
        if (preg_match('/^254\d{9}$/', $phone)) {
            return '0' . substr($phone, 3);
        }
        return $phone;
    }

    /**
     * Human-readable description for a Daraja ResultCode (STK, B2C,
     * Reversal, Status).
     */
    public static function describeResultCode($code): string
    {
        $map = [
            '0'    => 'Success',
            '1'    => 'Insufficient M-Pesa balance',
            '2'    => 'Amount is below the minimum allowed',
            '3'    => 'Amount is above the maximum allowed',
            '4'    => 'Daily transfer limit exceeded',
            '8'    => 'Maximum account balance exceeded',
            '11'   => 'Debit party is in an invalid state',
            '17'   => 'Internal failure — please try again',
            '20'   => 'Unresolved system error — please try again',
            '21'   => 'Initiator is not allowed to initiate this request',
            '26'   => 'Traffic blocking condition in place — please try again shortly',
            '1001' => 'Another M-Pesa transaction is already in progress on this phone — please wait and try again',
            '1019' => 'Transaction expired before it was completed',
            '1025' => 'An error occurred while sending the payment prompt',
            '1032' => 'Request cancelled by the user',
            '1037' => 'Could not reach the phone (no response / phone offline)',
            '2001' => 'Wrong M-Pesa PIN entered (or invalid initiator credentials)',
            '2006' => 'Customer account is inactive or blocked',
            '2028' => 'The shortcode is not allowed to perform this transaction',
            '9999' => 'Error sending the payment prompt',
            '8006' => 'The customer\'s M-Pesa account is locked (security credential)',
            'SFC_IC0003' => 'Operator does not exist',
        ];

        return $map[(string) $code] ?? ('Unknown result code: ' . $code);
    }
}

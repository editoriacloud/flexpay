<?php
/**
 * FlexPayLicense — WHMCS Software Licensing Addon integration.
 *
 * FlexPay is developed by Editoria Cloud Systems (https://www.editoriaweb.co.ke).
 * This class validates FlexPay's own commercial license against Editoria
 * Cloud Systems' WHMCS installation, using the same protocol WHMCS's own
 * "Software Licensing" addon module expects from any licensed PHP
 * application — local key caching + periodic remote verification,
 * exactly as documented for that addon.
 *
 * This is the ENFORCEMENT layer: every payment-critical entry point in
 * FlexPay (the STK widget, checkout.php, the callback router, and the
 * dashboard) calls FlexPayLicense::check() before doing anything else.
 * An invalid, expired, or suspended license blocks the module from
 * processing payments — that's the actual mechanism that makes licensing
 * meaningful, not just a checkbox in settings.
 *
 * The licensing server URL is intentionally hardcoded (LICENSING_URL
 * below), not a customer-editable gateway setting — a licensed copy of
 * FlexPay always checks in with Editoria Cloud Systems, the same way any
 * commercial WHMCS module's licensing endpoint isn't something the
 * customer is meant to repoint elsewhere.
 *
 * PROTOCOL NOTES (verified against WHMCS's own documented behavior and
 * the official integration code sample for the Software Licensing addon):
 *
 *   - Remote endpoint: POST {LICENSING_URL}/modules/servers/licensing/verify.php
 *   - POST fields: licensekey, domain, ip, dir, check_token
 *   - Response: XML-style tags <tag>value</tag>, parsed into an array.
 *     Key fields: status (Active/Invalid/Expired/Suspended), description,
 *     validdomain, validip, validdirectory, md5hash, localkey.
 *   - The response's md5hash = md5(secretKey . check_token) — verified
 *     here to detect a tampered/spoofed response before trusting it.
 *   - On success, the addon returns a "local key" blob the customer's
 *     server caches, so the module keeps working through brief outages
 *     of the licensing server (a real production resilience need — you
 *     don't want a network hiccup on Editoria's server to stop a
 *     customer's M-Pesa payments from processing).
 *   - This implementation encodes the local key as JSON rather than
 *     PHP's serialize()/unserialize() — a safer, well-established
 *     variant of the same protocol that avoids any PHP object
 *     deserialization surface, even on data that only ever round-trips
 *     through our own server.
 *
 * @package   FlexPay\Licensing
 * @author    Editoria Cloud Systems <https://www.editoriaweb.co.ke>
 * @version   1.2.0
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPayLicense
{
    /**
     * FlexPay's licensing server — Editoria Cloud Systems' own WHMCS
     * installation. Hardcoded deliberately: this is not a customer
     * setting. Every legitimate copy of FlexPay checks in here.
     *
     * @var string
     */
    private const LICENSING_URL = 'https://www.editoriaweb.co.ke';

    /**
     * Cached validation result for the lifetime of this request, so
     * requireValid() can be called from multiple entry points in the
     * same page load without re-running the whole check repeatedly.
     *
     * @var array|null
     */
    private static ?array $cachedResult = null;

    /**
     * Validate the configured FlexPay license, enforcing it at every
     * call site. Returns the validation result array; callers that need
     * to actively BLOCK on an invalid license should check
     * $result['valid'] themselves (see the convenience wrappers below)
     * rather than relying on this method to halt execution, since some
     * call sites (e.g. rendering a friendly error to the customer) need
     * to handle the failure gracefully rather than via a hard die().
     *
     * @param  array $gw           Gateway params (licenseKey, licensingSecret)
     * @param  bool  $allowRemote  false = local cache only, never a network call
     *                             (used by Daraja callbacks, which must answer fast)
     * @return array  ['valid' => bool, 'status' => string, 'message' => string, 'source' => string]
     */
    public static function check(array $gw, bool $allowRemote = true): array
    {
        if (self::$cachedResult !== null) {
            return self::$cachedResult;
        }

        $licenseKey      = trim((string) ($gw['licenseKey'] ?? ''));
        $licensingUrl    = self::LICENSING_URL;
        $licensingSecret = trim((string) ($gw['licensingSecret'] ?? ''));

        if ($licenseKey === '' || $licensingSecret === '') {
            return self::$cachedResult = [
                'valid'   => false,
                'status'  => 'Unconfigured',
                'message' => 'FlexPay license is not configured. Enter your License Key and Licensing Secret Key in the gateway settings.',
                'source'  => 'config',
            ];
        }

        $localKeyDays      = 3;  // remote re-check frequency
        $allowCheckFailDays = 5; // grace period if the licensing server is unreachable

        $domain  = self::currentDomain($gw);
        $usersIp = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '');
        $dirPath = __DIR__;

        $cachedLocalKey = FlexPayStore::getSetting('license_local_key');

        $localValid     = false;  // true only if fresh AND domain/IP-bound correctly
        $localDecoded   = null;   // the decoded payload, regardless of freshness — used for grace-period evaluation too
        $domainIpOk     = false;  // tracks domain/IP binding independently of freshness, so grace period can't bypass it

        if ($cachedLocalKey) {
            $decoded = self::decodeLocalKey($cachedLocalKey, $licensingSecret);

            if ($decoded !== null) {
                $localDecoded = $decoded;

                // Domain/IP binding is checked unconditionally here —
                // independently of whether the key is still within its
                // normal freshness window — specifically so the grace
                // period below can never trust a key that's bound to a
                // different domain/IP than this install. A license
                // copy-pasted onto an unauthorized install must fail
                // both the normal check AND the grace-period fallback.
                $domainIpOk = self::matchesAny($domain, $decoded['validdomain'] ?? '')
                    && ($usersIp === '' || self::matchesAny($usersIp, $decoded['validip'] ?? ''));

                $checkDate   = (string) ($decoded['checkdate'] ?? '');
                $localExpiry = date('Ymd', strtotime("-{$localKeyDays} days"));

                if ($domainIpOk && $checkDate > $localExpiry) {
                    $localValid = true;
                }
            }
        }

        if ($localValid && ($localDecoded['status'] ?? '') === 'Active') {
            return self::$cachedResult = [
                'valid'   => true,
                'status'  => 'Active',
                'message' => 'License valid (cached).',
                'source'  => 'local_cache',
            ];
        }

        if (!$allowRemote) {
            // Callers that must not block on the network get the grace-period
            // answer from the local key, without caching it for the request.
            $graceOk = $localDecoded !== null && $domainIpOk && ($localDecoded['status'] ?? '') === 'Active'
                && (string) ($localDecoded['checkdate'] ?? '') > date('Ymd', strtotime('-' . ($localKeyDays + $allowCheckFailDays) . ' days'));
            return [
                'valid'   => $graceOk,
                'status'  => $graceOk ? 'Active' : 'Unverified',
                'message' => $graceOk ? 'License valid (cached).' : 'License not verified recently (no remote check performed here).',
                'source'  => 'local_only',
            ];
        }

        // Local cache missing, expired, or invalid — perform a live
        // remote check against the licensing WHMCS install.
        $remote = self::performRemoteCheck($licenseKey, $licensingUrl, $licensingSecret, $domain, $usersIp, $dirPath);

        if ($remote !== null) {
            if (($remote['status'] ?? '') === 'Active') {
                if (!empty($remote['localkey'])) {
                    FlexPayStore::setSetting('license_local_key', $remote['localkey']);
                }
                FlexPayStore::setSetting('license_last_valid_check', date('Y-m-d H:i:s'));

                return self::$cachedResult = [
                    'valid'   => true,
                    'status'  => 'Active',
                    'message' => 'License valid (remote check).',
                    'source'  => 'remote',
                ];
            }

            $status = $remote['status'] ?? 'Invalid';
            $desc   = $remote['description'] ?? '';

            return self::$cachedResult = [
                'valid'   => false,
                'status'  => $status,
                'message' => self::describeStatus($status, $desc),
                'source'  => 'remote',
            ];
        }

        // Remote check failed outright (network/server issue) — fall back
        // to the grace period if we have ANY local key data, however
        // stale, within the extended allow-check-fail window. This is
        // the "your customer's M-Pesa payments don't stop working just
        // because YOUR server had a 10-minute outage" resilience case.
        //
        // Critically, this still requires $domainIpOk — a cached key
        // bound to a different domain/IP than this install must NEVER
        // be trusted, grace period or not. Domain/IP binding is the one
        // check that grace period cannot waive, since waiving it would
        // let a license be copy-pasted onto an unauthorized install the
        // instant that install's network access to the licensing server
        // is blocked (trivially achievable by firewalling outbound
        // requests) — the exact bypass grace period must not create.
        if ($localDecoded !== null && $domainIpOk && ($localDecoded['status'] ?? '') === 'Active') {
            $checkDate      = (string) ($localDecoded['checkdate'] ?? '');
            $extendedExpiry = date('Ymd', strtotime('-' . ($localKeyDays + $allowCheckFailDays) . ' days'));

            if ($checkDate > $extendedExpiry) {
                return self::$cachedResult = [
                    'valid'   => true,
                    'status'  => 'Active',
                    'message' => 'License valid (grace period — remote licensing server unreachable).',
                    'source'  => 'grace_period',
                ];
            }
        }

        return self::$cachedResult = [
            'valid'   => false,
            'status'  => 'CheckFailed',
            'message' => 'Could not verify your FlexPay license — the licensing server is unreachable and no valid cached license was found. Please check your internet connection or contact support.',
            'source'  => 'failed',
        ];
    }

    /**
     * The domain this install runs on. Prefers the web server's name; in
     * CLI/cron (no SERVER_NAME) uses the WHMCS System URL host rather than
     * the machine hostname, which never matches the licensed domain.
     */
    private static function currentDomain(array $gw): string
    {
        $name = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($name !== '') {
            return $name;
        }
        $url  = (string) ($gw['systemurl'] ?? '');
        if ($url === '' && class_exists('App')) {
            try {
                $url = (string) \App::getSystemURL();
            } catch (\Throwable $e) {
                $url = '';
            }
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        return $host !== '' ? $host : php_uname('n');
    }

    /**
     * Convenience wrapper for payment-processing entry points: returns
     * true/false and never throws, so a license failure degrades into a
     * clear, customer-safe message rather than a fatal error mid-payment.
     *
     * @param  array $gw
     * @return bool
     */
    public static function isValid(array $gw): bool
    {
        return self::check($gw)['valid'] ?? false;
    }

    /**
     * Human-readable explanation for why FlexPay is blocked, intended for
     * display to the SITE ADMIN (not the customer) — e.g. in the gateway
     * widget's fallback state or the dashboard. Never exposes the secret
     * key or raw protocol details.
     *
     * @param  array $gw
     * @return string
     */
    public static function blockedMessage(array $gw): string
    {
        $result = self::check($gw);
        return $result['message'] ?? 'FlexPay license could not be verified.';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Remote check
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Perform the actual remote verification call against the licensing
     * WHMCS install's verify.php endpoint, per the Software Licensing
     * addon's documented protocol.
     *
     * @return array|null  Parsed response, or null on outright network failure
     */
    private static function performRemoteCheck(
        string $licenseKey,
        string $licensingUrl,
        string $licensingSecret,
        string $domain,
        string $usersIp,
        string $dirPath
    ): ?array {
        $checkToken = time() . bin2hex(random_bytes(16));

        $postFields = [
            'licensekey'  => $licenseKey,
            'domain'      => $domain,
            'ip'          => $usersIp,
            'dir'         => $dirPath,
            'check_token' => $checkToken,
        ];

        $url = rtrim($licensingUrl, '/') . '/modules/servers/licensing/verify.php';

        $raw = self::httpPost($url, $postFields);

        if ($raw === null) {
            return null;
        }

        $results = self::parseXmlTags($raw);

        if (empty($results)) {
            return null;
        }

        // Verify the response wasn't tampered with / spoofed — the
        // licensing server signs its response with
        // md5(secretKey . check_token), which only the real server (which
        // knows the secret) and we (who sent the token) can compute.
        // The hash is REQUIRED: v3.4 skipped the check when it was absent,
        // so a spoofed response (DNS/hosts-file redirect) that simply
        // omitted md5hash was trusted.
        $expected = md5($licensingSecret . $checkToken);
        if (empty($results['md5hash']) || !hash_equals($expected, (string) $results['md5hash'])) {
            return [
                'status'      => 'Invalid',
                'description' => 'License server response failed integrity verification.',
            ];
        }

        if (($results['status'] ?? '') === 'Active') {
            $results['checkdate'] = date('Ymd');
            $results['localkey']  = self::encodeLocalKey($results, $licensingSecret);
        }

        return $results;
    }

    private static function httpPost(string $url, array $fields): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'FlexPay-License-Client/1.1',
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno || $response === false) {
            return null;
        }

        return $response;
    }

    /**
     * Parse the licensing addon's XML-style tag response format
     * (<tag>value</tag>...) into an associative array — the same parsing
     * approach used by WHMCS's own official integration sample.
     *
     * @param  string $raw
     * @return array
     */
    private static function parseXmlTags(string $raw): array
    {
        if (!preg_match_all('/<([a-zA-Z0-9_]+)>(.*?)<\/\1>/s', $raw, $matches)) {
            return [];
        }

        $out = [];
        foreach ($matches[1] as $i => $tag) {
            $out[$tag] = $matches[2][$i];
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Local key encoding / decoding
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Encode a successful remote-check result into a tamper-evident local
     * key blob, cached so subsequent requests don't need a network call
     * every time. Layered: JSON → base64 → prefix integrity hash →
     * reverse the whole string → append a second integrity hash.
     *
     * Using JSON instead of PHP's serialize()/unserialize() avoids any
     * PHP object-injection surface entirely, which is the safer modern
     * variant of this same long-established protocol.
     *
     * @param  array  $results
     * @param  string $secret
     * @return string
     */
    private static function encodeLocalKey(array $results, string $secret): string
    {
        $checkDate = (string) ($results['checkdate'] ?? date('Ymd'));

        $payload = json_encode($results);
        $encoded = base64_encode($payload);
        $encoded = md5($checkDate . $secret) . $encoded;
        $encoded = strrev($encoded);
        $encoded = $encoded . md5($encoded . $secret);

        return $encoded;
    }

    /**
     * Decode and integrity-check a cached local key blob. Returns null
     * on ANY failure (wrong secret, corrupted data, tampering) — a
     * failed decode is treated identically to "no local key at all",
     * which safely forces a fresh remote check rather than trusting
     * unverifiable data.
     *
     * @param  string $localKey
     * @param  string $secret
     * @return array|null
     */
    private static function decodeLocalKey(string $localKey, string $secret): ?array
    {
        $localKey = str_replace(["\r", "\n", ' '], '', $localKey);

        if (strlen($localKey) < 64) {
            return null;
        }

        $outerHash = substr($localKey, -32);
        $body      = substr($localKey, 0, -32);

        if (!hash_equals(md5($body . $secret), $outerHash)) {
            return null;
        }

        $reversed = strrev($body);
        $innerHash = substr($reversed, 0, 32);
        $encoded   = substr($reversed, 32);

        $payload = base64_decode($encoded, true);
        if ($payload === false) {
            return null;
        }

        $results = json_decode($payload, true);
        if (!is_array($results) || !isset($results['checkdate'])) {
            return null;
        }

        if (!hash_equals(md5((string) $results['checkdate'] . $secret), $innerHash)) {
            return null;
        }

        return $results;
    }

    /**
     * Check whether $needle matches any entry in a comma-separated list
     * of valid values (domains/IPs) from the license record. An empty
     * valid-list is treated as "no restriction" — matching the addon's
     * own behavior when a field is left blank in the license record.
     *
     * @param  string $needle
     * @param  string $csvList
     * @return bool
     */
    private static function matchesAny(string $needle, string $csvList): bool
    {
        $csvList = trim($csvList);
        if ($csvList === '') {
            return true;
        }

        $values = array_map('trim', explode(',', $csvList));
        return in_array($needle, $values, true);
    }

    /**
     * Human-readable status descriptions for the admin-facing message.
     *
     * @param  string $status
     * @param  string $description
     * @return string
     */
    private static function describeStatus(string $status, string $description): string
    {
        $map = [
            'Invalid'   => 'Your FlexPay license key is invalid. Please check the License Key in your gateway settings, or contact your reseller.',
            'Expired'   => 'Your FlexPay license has expired. Please renew your license to continue processing M-Pesa payments.',
            'Suspended' => 'Your FlexPay license has been suspended. Please contact your reseller to resolve this.',
        ];

        $base = $map[$status] ?? ('FlexPay license check failed: ' . $status);

        return $description !== '' ? "{$base} ({$description})" : $base;
    }

    /**
     * Reset the in-request cache — used by tests and by the dashboard's
     * "Re-check License Now" tool so a fresh check isn't suppressed by
     * the per-request cache.
     *
     * @return void
     */
    public static function clearRequestCache(): void
    {
        self::$cachedResult = null;
    }
}

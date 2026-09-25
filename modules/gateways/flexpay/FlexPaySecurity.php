<?php
/**
 * FlexPaySecurity — every trust decision FlexPay makes lives here.
 *
 *   - Per-install signing secret (generated once, stored server-side only)
 *   - Signed, expiring invoice tokens for the public AJAX endpoints
 *     (checkout.php / poll.php / verify.php). These replace the old scheme
 *     that shipped the Daraja passkey to the browser and keyed the "CSRF"
 *     token on it, which let anyone forge requests.
 *   - Authenticated Daraja callback URLs (secret per-route key in the URL,
 *     optionally combined with Safaricom's published source IPs)
 *   - Admin dashboard CSRF tokens
 *   - A small DB-backed rate limiter for abuse-prone public paths
 *   - Client IP resolution that only trusts forwarding headers from
 *     explicitly configured reverse proxies
 *
 * @package FlexPay\Security
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPaySecurity
{
    /** Invoice tokens stay valid this long — long enough for a customer who leaves the tab open. */
    public const INVOICE_TOKEN_TTL = 172800; // 48 hours

    /**
     * Safaricom's published Daraja callback source addresses. Used only when
     * the callback security mode allows IP-based trust (see verifyCallback).
     * Admins can extend this list with the "Extra Callback IPs" setting when
     * Safaricom adds new addresses.
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
        '196.201.212.69',
        '196.201.212.74',
    ];

    private static ?string $secret = null;

    // ─────────────────────────────────────────────────────────────────────
    // Install secret + generic signing
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The per-install signing secret. Generated with a CSPRNG the first time
     * it's needed and never sent to the browser or to Safaricom in raw form.
     */
    public static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $stored = (string) FlexPayStore::getSetting('security_secret', '');
        if (strlen($stored) < 64) {
            // addSettingIfAbsent + re-read so two concurrent first requests
            // converge on the same secret instead of each keeping their own.
            FlexPayStore::addSettingIfAbsent('security_secret', bin2hex(random_bytes(32)));
            $stored = (string) FlexPayStore::getSetting('security_secret', '');
        }

        if (strlen($stored) < 64) {
            // Database unavailable. Fail closed: a random per-request secret
            // makes every token/callback check fail rather than pass.
            $stored = bin2hex(random_bytes(32));
        }

        return self::$secret = $stored;
    }

    public static function sign(string $purpose, string $data): string
    {
        return hash_hmac('sha256', $purpose . '|' . $data, self::secret());
    }

    /** Test hook: forget the cached secret. */
    public static function resetCache(): void
    {
        self::$secret = null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Invoice tokens (public AJAX endpoints)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Token proving the caller was shown this invoice's payment widget by
     * WHMCS (which already enforced invoice access). Format: "{expiry}.{mac}".
     */
    public static function invoiceToken(int $invoiceId, ?int $now = null): string
    {
        $expiry = ($now ?? time()) + self::INVOICE_TOKEN_TTL;
        return $expiry . '.' . substr(self::sign('invoice', $invoiceId . '|' . $expiry), 0, 48);
    }

    public static function verifyInvoiceToken(int $invoiceId, string $token, ?int $now = null): bool
    {
        if ($invoiceId <= 0 || !preg_match('/^(\d{10,12})\.([a-f0-9]{48})$/', $token, $m)) {
            return false;
        }

        if ((int) $m[1] < ($now ?? time())) {
            return false;
        }

        $expected = substr(self::sign('invoice', $invoiceId . '|' . $m[1]), 0, 48);
        return hash_equals($expected, $m[2]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Callback authentication
    // ─────────────────────────────────────────────────────────────────────

    public static function callbackKey(string $route): string
    {
        // Hex only: Safaricom rejects C2B URLs containing words such as
        // "exec", "sql" or "cmd", which a hex string can never spell.
        return substr(self::sign('callback', strtolower($route)), 0, 32);
    }

    /**
     * Build the full callback URL for a route, including its secret key.
     * The path deliberately never contains "mpesa"/"safaricom" (Daraja
     * rejects such C2B URLs).
     */
    public static function callbackUrl(string $systemUrl, string $route): string
    {
        return rtrim($systemUrl, '/') . '/modules/gateways/callback/flexpay.php?route='
            . rawurlencode($route) . '&k=' . self::callbackKey($route);
    }

    /**
     * Decide whether an inbound callback request is authentic.
     *
     * Modes (gateway setting "Callback Security"):
     *   token_or_ip (default) — valid URL key OR a Safaricom source IP.
     *                            Keeps C2B working on production shortcodes
     *                            whose URLs were registered before keys
     *                            existed (Safaricom only lets production
     *                            URLs be registered once).
     *   token_only            — valid URL key required (strongest).
     *   ip_only               — Safaricom source IP required.
     *   off                   — accept everything (NOT recommended; logged).
     *
     * @return array ['ok' => bool, 'via' => string, 'ip' => string]
     */
    public static function verifyCallback(string $route, array $gw, ?string $providedKey = null, ?array $server = null): array
    {
        $server = $server ?? $_SERVER;
        $mode   = (string) ($gw['callbackSecurity'] ?? 'token_or_ip');
        $key    = (string) ($providedKey ?? ($_GET['k'] ?? ''));
        $ip     = self::clientIp($gw, $server);

        $tokenOk = $key !== '' && hash_equals(self::callbackKey($route), $key);
        $ipOk    = self::isSafaricomIp($ip, $gw);

        switch ($mode) {
            case 'off':
                return ['ok' => true, 'via' => 'disabled', 'ip' => $ip];
            case 'token_only':
                return ['ok' => $tokenOk, 'via' => $tokenOk ? 'token' : 'rejected', 'ip' => $ip];
            case 'ip_only':
                return ['ok' => $ipOk, 'via' => $ipOk ? 'ip' : 'rejected', 'ip' => $ip];
            case 'token_or_ip':
            default:
                if ($tokenOk) {
                    return ['ok' => true, 'via' => 'token', 'ip' => $ip];
                }
                return ['ok' => $ipOk, 'via' => $ipOk ? 'ip' : 'rejected', 'ip' => $ip];
        }
    }

    public static function isSafaricomIp(string $ip, array $gw = []): bool
    {
        if ($ip === '') {
            return false;
        }

        $list  = self::SAFARICOM_CALLBACK_IPS;
        $extra = self::parseList((string) ($gw['extraCallbackIps'] ?? ''));

        return self::ipInList($ip, array_merge($list, $extra));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Client IP
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The caller's IP. Forwarding headers are honoured ONLY when the direct
     * peer is listed in the "Trusted Proxies" gateway setting, so nobody can
     * spoof a Safaricom IP with a hand-written X-Forwarded-For header.
     */
    public static function clientIp(array $gw = [], ?array $server = null): string
    {
        $server = $server ?? $_SERVER;
        $remote = trim((string) ($server['REMOTE_ADDR'] ?? ''));

        $trusted = self::parseList((string) ($gw['trustedProxies'] ?? ''));
        if ($remote === '' || empty($trusted) || !self::ipInList($remote, $trusted)) {
            return $remote;
        }

        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP'] as $header) {
            $value = trim((string) ($server[$header] ?? ''));
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }

        $xff = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            // Walk right-to-left, skipping our own trusted proxies; the first
            // untrusted hop is the real client.
            $hops = array_reverse(array_map('trim', explode(',', $xff)));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!self::ipInList($hop, $trusted)) {
                    return $hop;
                }
            }
        }

        return $remote;
    }

    /**
     * @param string   $ip
     * @param string[] $list  Plain IPs or CIDR ranges (IPv4 or IPv6)
     */
    public static function ipInList(string $ip, array $list): bool
    {
        $ipBin = @inet_pton($ip);
        if ($ipBin === false) {
            return false;
        }

        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }

            if (strpos($entry, '/') === false) {
                $entryBin = @inet_pton($entry);
                if ($entryBin !== false && $entryBin === $ipBin) {
                    return true;
                }
                continue;
            }

            [$net, $bits] = explode('/', $entry, 2);
            $netBin = @inet_pton(trim($net));
            $bits   = (int) $bits;
            if ($netBin === false || strlen($netBin) !== strlen($ipBin) || $bits < 0 || $bits > strlen($ipBin) * 8) {
                continue;
            }

            $bytes = intdiv($bits, 8);
            $rem   = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = chr((0xFF << (8 - $rem)) & 0xFF);
            if ((($ipBin[$bytes] & $mask) === ($netBin[$bytes] & $mask))) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    public static function parseList(string $csv): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $csv) ?: [])));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Admin CSRF
    // ─────────────────────────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // No session: the token can never validate (fail closed).
            return '';
        }
        if (empty($_SESSION['flexpay_csrf']) || !is_string($_SESSION['flexpay_csrf'])) {
            $_SESSION['flexpay_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['flexpay_csrf'];
    }

    public static function checkCsrf(?string $token): bool
    {
        $expected = $_SESSION['flexpay_csrf'] ?? '';
        return is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token);
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="fp_csrf" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Rate limiting
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Fixed-window limiter stored in flexpay_settings (rows pruned by the
     * cron hook). Returns true if this attempt is allowed and counts it.
     */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): bool
    {
        $key   = 'rl_' . substr(hash('sha256', $bucket), 0, 40);
        $now   = time();
        $state = json_decode((string) FlexPayStore::getSetting($key, ''), true);

        if (!is_array($state) || !isset($state['s'], $state['c']) || $now - (int) $state['s'] >= $windowSeconds) {
            $state = ['s' => $now, 'c' => 0];
        }

        if ((int) $state['c'] >= $max) {
            return false;
        }

        $state['c']++;
        FlexPayStore::setSetting($key, json_encode($state));
        return true;
    }

    public static function clearRateLimit(string $bucket): void
    {
        FlexPayStore::deleteSetting('rl_' . substr(hash('sha256', $bucket), 0, 40));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Input helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Normalise a customer-typed M-Pesa receipt number. Real receipts are 10
     * uppercase alphanumerics (e.g. NLJ7RT61SV); we accept 8–12 to allow for
     * future format changes but nothing else.
     */
    public static function normalizeReceipt(string $value): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value));
        return preg_match('/^[A-Z0-9]{8,12}$/', $value) ? $value : null;
    }

    /** Emit JSON response headers for the public AJAX endpoints. */
    public static function jsonHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex');
    }
}

<?php
/**
 * FlexPayViews — renders every screen of the dashboard addon.
 *
 * Kept as static methods returning HTML strings (rather than separate
 * template files) so the whole UI ships as plain, dependency-free PHP —
 * consistent with how WHMCS addon modules conventionally echo output
 * directly from _output().
 *
 * @package FlexPay\Dashboard
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPayViews
{
    // ─────────────────────────────────────────────────────────────────────
    // Shared chrome (header nav + footer + shared styles)
    // ─────────────────────────────────────────────────────────────────────

    public static function renderHeader(string $modulelink, string $activeTab): string
    {
        $tabs = [
            'overview'       => 'Overview',
            'transactions'   => 'Transactions',
            'refunds'        => 'Refunds',
            'reconciliation' => 'Reconciliation',
            'balance'        => 'Balance',
            'tools'          => 'Tools',
            'apilog'         => 'API Log',
        ];

        $nav = '';
        foreach ($tabs as $key => $label) {
            $isActive = ($key === $activeTab);
            $badge = '';

            if ($key === 'reconciliation') {
                $count = FlexPayStore::countUnmatched();
                $badge = ' <span id="fp-nav-reconcile-badge" style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:4px;'
                    . ($count > 0 ? '' : 'display:none;') . '">' . $count . '</span>';
            }

            $nav .= '<a href="' . htmlspecialchars($modulelink) . '&fp_tab=' . $key . '" style="'
                . 'display:inline-block;padding:10px 16px;text-decoration:none;font-size:14px;font-weight:'
                . ($isActive ? '700' : '500') . ';color:' . ($isActive ? '#007229' : '#555') . ';'
                . 'border-bottom:3px solid ' . ($isActive ? '#007229' : 'transparent') . ';">'
                . htmlspecialchars($label) . $badge . '</a>';
        }

        return '
        <style>
            .fp-wrap { font-family: -apple-system, "Segoe UI", Arial, sans-serif; color:#222; }
            .fp-card { background:#fff; border:1px solid #e3e6e8; border-radius:8px; padding:18px; }
            .fp-grid { display:grid; gap:16px; }
            .fp-stat-value { font-size:26px; font-weight:700; margin:4px 0; }
            .fp-stat-label { font-size:12px; color:#888; text-transform:uppercase; letter-spacing:.4px; }
            .fp-table { width:100%; border-collapse:collapse; font-size:13px; }
            .fp-table th { text-align:left; padding:9px 10px; background:#f7f8f9; border-bottom:2px solid #e3e6e8; font-weight:600; color:#555; }
            .fp-table td { padding:9px 10px; border-bottom:1px solid #eee; vertical-align:middle; }
            .fp-table tr:hover td { background:#fafbfc; }
            .fp-badge { display:inline-block; padding:2px 9px; border-radius:10px; font-size:11px; font-weight:600; }
            .fp-badge-success { background:#d4edda; color:#155724; }
            .fp-badge-failed  { background:#f8d7da; color:#721c24; }
            .fp-badge-pending { background:#fff3cd; color:#856404; }
            .fp-badge-reversed { background:#e2e3e5; color:#383d41; }
            .fp-btn { display:inline-block; padding:7px 14px; background:#007229; color:#fff; border:none;
                      border-radius:5px; font-size:13px; cursor:pointer; text-decoration:none; }
            .fp-btn-outline { background:#fff; color:#007229; border:1px solid #007229; }
            .fp-btn-danger { background:#c0392b; }
            .fp-input { padding:8px 10px; border:1px solid #ccc; border-radius:5px; font-size:13px; }
            .fp-alert { padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:14px; }
            .fp-alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
            .fp-alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
            .fp-live-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#28a745; margin-right:5px; animation: fp-pulse 2s infinite; }
            @keyframes fp-pulse { 0%, 100% { opacity:1; } 50% { opacity:.35; } }
        </style>
        <div class="fp-wrap">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:6px;flex-wrap:wrap;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:38px;height:38px;background:#007229;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;">M</div>
                    <div>
                        <div style="font-size:19px;font-weight:700;">FlexPay Dashboard</div>
                        <div style="font-size:12px;color:#888;">M-Pesa / Daraja transaction management</div>
                    </div>
                </div>
                <div id="fp-live-updated-at" style="font-size:11px;color:#999;"><span class="fp-live-dot"></span>Live — connecting…</div>
            </div>
            <div style="border-bottom:1px solid #e3e6e8;margin:14px 0 20px;">' . $nav . '</div>
        ' . self::renderLivePollingScript($modulelink) . '
        ';
    }

    /**
     * Lightweight polling script injected on every tab load. Polls
     * ?fp_tab=live_data every few seconds and updates:
     *   - The Overview tab's stat cards + balance, if present on the page
     *   - The reconciliation nav badge count, always
     *   - The Transactions tab's "new since you opened this page" banner
     *     (rather than silently reordering rows under the admin's cursor)
     *
     * Pure vanilla JS, no dependencies — consistent with the rest of this
     * addon's dependency-free approach. Safe to include on every tab:
     * each consumer checks for its own target elements before touching
     * anything, so tabs without a live-updatable element simply ignore
     * the poll responses they don't need.
     */
    public static function renderLivePollingScript(string $modulelink): string
    {
        // Raw URL, JSON-encoded for a JS string. (v3.4 HTML-escaped it first,
        // so the browser requested "...&amp;fp_tab=live_data" and live
        // updates never worked.)
        $liveUrl = $modulelink . '&fp_tab=live_data';

        return '
        <script>
        (function () {
            var FP_LIVE_URL = ' . json_encode($liveUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) . ';
            var FP_POLL_MS = 8000;
            var fpSeenLatestId = null;
            var fpNewCount = 0;

            function fpFormatMoney(n) {
                return "KES " + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            function fpPoll() {
                fetch(FP_LIVE_URL, { cache: "no-store", credentials: "same-origin" })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.success) return;
                        if (document.hidden) return;
                        fpUpdateOverview(data);
                        fpUpdateBadge(data.stats.unmatched_count);
                        fpUpdateTransactionsBanner(data.transactions);
                        fpUpdateTimestamp(data.generated_at);
                    })
                    .catch(function () { /* silent - just try again next interval */ })
                    .finally(function () { setTimeout(fpPoll, FP_POLL_MS); });
            }

            function fpUpdateOverview(data) {
                var map = {
                    "fp-live-total-in": fpFormatMoney(data.stats.total_in),
                    "fp-live-total-out": fpFormatMoney(data.stats.total_out),
                    "fp-live-success-count": data.stats.success_count,
                    "fp-live-failed-count": data.stats.failed_count,
                    "fp-live-pending-count": data.stats.pending_count,
                    "fp-live-unmatched-count": data.stats.unmatched_count
                };
                Object.keys(map).forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.textContent = map[id];
                });

                if (data.balance) {
                    var w = document.getElementById("fp-live-balance-working");
                    var u = document.getElementById("fp-live-balance-utility");
                    if (w) w.textContent = fpFormatMoney(data.balance.working_account);
                    if (u) u.textContent = fpFormatMoney(data.balance.utility_account);
                }
            }

            function fpUpdateBadge(count) {
                var badge = document.getElementById("fp-nav-reconcile-badge");
                if (!badge) return;
                if (count > 0) {
                    badge.style.display = "inline-block";
                    badge.textContent = count;
                } else {
                    badge.style.display = "none";
                }
            }

            function fpUpdateTransactionsBanner(transactions) {
                var banner = document.getElementById("fp-new-transactions-banner");
                if (!banner || !transactions || !transactions.length) return;

                var latestId = transactions[0].id;
                if (fpSeenLatestId === null) {
                    fpSeenLatestId = latestId;
                    return;
                }
                if (latestId > fpSeenLatestId) {
                    fpNewCount += (latestId - fpSeenLatestId);
                    fpSeenLatestId = latestId;
                    banner.style.display = "flex";
                    banner.querySelector(".fp-new-count").textContent = fpNewCount;
                }
            }

            function fpUpdateTimestamp(ts) {
                var el = document.getElementById("fp-live-updated-at");
                if (el) el.textContent = "Live — last updated " + ts;
            }

            fpPoll();
        })();
        </script>
        ';
    }

    public static function renderFooter(): string
    {
        return '</div>'; // closes .fp-wrap
    }

    public static function renderActionResult(array $result, string $modulelink): string
    {
        $class = $result['success'] ? 'fp-alert-success' : 'fp-alert-error';
        $icon  = $result['success'] ? '&#9989;' : '&#10060;';
        return '<div class="fp-alert ' . $class . '">' . $icon . ' ' . htmlspecialchars($result['message']) . '</div>';
    }

    /** Shorthand HTML escaper. */
    private static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Phones may be full numbers, masked, or (C2B v2) SHA-256 hashes. */
    private static function displayPhone(string $phone): string
    {
        if (preg_match('/^[a-f0-9]{64}$/i', $phone)) {
            return 'hashed …' . substr($phone, -6);
        }
        return DarajaClient::toDisplayPhone($phone);
    }

    private static function invoiceLink($invoiceId): string
    {
        return $invoiceId
            ? '<a href="invoices.php?action=edit&amp;id=' . (int) $invoiceId . '" target="_blank" rel="noopener">#' . (int) $invoiceId . '</a>'
            : '<span style="color:#999;">—</span>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Overview tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderOverview(string $modulelink, int $days): string
    {
        $stats = FlexPayStore::getStats($days);
        $gw    = FlexPayStore::getFlexPayGatewayParams();
        $link  = self::e($modulelink);

        $html = '';

        $license = FlexPayLicense::check($gw);
        if (!$license['valid']) {
            $html .= '<div class="fp-card" style="border-color:#f5c6cb;background:#fff8f8;margin-bottom:16px;">'
                . '<strong style="color:#721c24;">&#9888; FlexPay License Issue: ' . self::e($license['status']) . '</strong><br>'
                . '<span style="font-size:13px;color:#721c24;">' . self::e($license['message']) . '</span> '
                . '<a href="' . $link . '&amp;fp_tab=tools" style="font-size:13px;color:#007229;font-weight:600;">Go to Tools to re-check &rarr;</a>'
                . '</div>';
        }

        if (($gw['callbackSecurity'] ?? 'token_or_ip') === 'off') {
            $html .= '<div class="fp-alert fp-alert-error">&#9888; <strong>Callback security is OFF.</strong> Anyone can send FlexPay a fake "payment received" notification. Change <em>Callback Security</em> in the gateway settings.</div>';
        }

        $note = (string) FlexPayStore::getSetting('c2b_registration_note', '');
        if ($note !== '') {
            $html .= '<div class="fp-alert" style="background:#fff3cd;color:#856404;border:1px solid #ffeeba;">&#9432; ' . self::e($note) . '</div>';
        }

        $html .= '<div style="margin-bottom:16px;">' . ((($gw['testMode'] ?? '') === 'on')
            ? '<span class="fp-badge fp-badge-pending">SANDBOX MODE</span>'
            : '<span class="fp-badge fp-badge-success">LIVE MODE</span>') . '</div>';

        $html .= '<div class="fp-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));margin-bottom:24px;">';

        $cards = [
            ['Received (in)', 'KES ' . number_format($stats['total_in'], 2), '#007229', 'fp-live-total-in'],
            ['Refunded (out)', 'KES ' . number_format($stats['total_out'], 2), '#c0392b', 'fp-live-total-out'],
            ['Successful Txns', number_format($stats['success_count']), '#222', 'fp-live-success-count'],
            ['Failed Txns', number_format($stats['failed_count']), '#c0392b', 'fp-live-failed-count'],
            ['Pending Txns', number_format($stats['pending_count']), '#856404', 'fp-live-pending-count'],
            ['Unmatched C2B', number_format($stats['unmatched_count']), $stats['unmatched_count'] > 0 ? '#c0392b' : '#222', 'fp-live-unmatched-count'],
        ];

        foreach ($cards as [$label, $value, $color, $liveId]) {
            $html .= '<div class="fp-card"><div class="fp-stat-label">' . self::e($label) . '</div>'
                . '<div class="fp-stat-value" style="color:' . $color . ';" id="' . $liveId . '">' . $value . '</div></div>';
        }

        $html .= '</div>';

        $html .= '<div style="font-size:13px;color:#888;margin-bottom:18px;">Showing activity for the last ' . (int) $days . ' days. STK Push successes: <strong>' . (int) $stats['stk_count'] . '</strong> &nbsp;|&nbsp; C2B successes: <strong>' . (int) $stats['c2b_count'] . '</strong></div>';

        $lastBalance = FlexPayStore::getLatestBalance(DarajaClient::c2bShortcode($gw));
        if ($lastBalance) {
            $html .= '<div class="fp-card" style="margin-bottom:18px;">'
                . '<div style="font-weight:600;margin-bottom:10px;">Latest Account Balance <span style="font-size:11px;color:#999;font-weight:400;">(as of ' . self::e($lastBalance->created_at) . ')</span></div>'
                . '<div style="display:flex;gap:30px;flex-wrap:wrap;">'
                . '<div><div class="fp-stat-label">Working Account</div><div style="font-size:18px;font-weight:700;" id="fp-live-balance-working">KES ' . number_format((float) $lastBalance->working_account, 2) . '</div></div>'
                . '<div><div class="fp-stat-label">Utility Account</div><div style="font-size:18px;font-weight:700;" id="fp-live-balance-utility">KES ' . number_format((float) $lastBalance->utility_account, 2) . '</div></div>'
                . '</div></div>';
        } else {
            $html .= '<div class="fp-card" style="margin-bottom:18px;color:#888;font-size:13px;">No balance snapshot yet. Visit the <a href="' . $link . '&amp;fp_tab=balance">Balance tab</a> to request one.</div>';
        }

        if ($stats['unmatched_count'] > 0) {
            $html .= '<div class="fp-card" style="border-color:#f5c6cb;background:#fff8f8;">'
                . '<strong>&#9888; ' . (int) $stats['unmatched_count'] . ' payment(s) need reconciliation.</strong> '
                . '<a href="' . $link . '&amp;fp_tab=reconciliation" class="fp-btn" style="margin-left:10px;">Review now</a>'
                . '</div>';
        }

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transactions tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderTransactions(string $modulelink, array $get): string
    {
        $perPage = 25;
        $page    = max(1, (int) ($get['page'] ?? 1));
        $filters = [
            'channel'   => (string) ($get['channel'] ?? ''),
            'status'    => (string) ($get['status'] ?? ''),
            'search'    => substr((string) ($get['search'] ?? ''), 0, 60),
            'date_from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['date_from'] ?? '')) ? $get['date_from'] : '',
            'date_to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($get['date_to'] ?? '')) ? $get['date_to'] : '',
        ];

        $result = FlexPayStore::listTransactions($filters, $page, $perPage);
        $rows   = $result['data'];
        $total  = $result['total'];
        $pages  = max(1, (int) ceil($total / $perPage));
        $link   = self::e($modulelink);

        $html = '<div id="fp-new-transactions-banner" style="display:none;align-items:center;justify-content:space-between;background:#e8f4fd;border:1px solid #b8daff;color:#004085;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;">'
            . '<span>&#128276; <span class="fp-new-count">0</span> new transaction(s) have arrived since you opened this page.</span>'
            . '<a href="' . $link . '&amp;fp_tab=transactions" class="fp-btn" style="padding:5px 12px;">Refresh</a>'
            . '</div>';

        $html .= '<form method="get" action="addonmodules.php" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">'
            . '<input type="hidden" name="module" value="flexpay_dashboard">'
            . '<input type="hidden" name="fp_tab" value="transactions">'
            . '<input class="fp-input" type="text" name="search" placeholder="Search receipt, phone, reference…" value="' . self::e($filters['search']) . '">';

        $html .= '<select class="fp-input" name="channel"><option value="">All Channels</option>';
        foreach (['stk' => 'STK Push', 'c2b' => 'C2B', 'b2c' => 'B2C / Refund', 'reversal' => 'Reversal'] as $val => $label) {
            $html .= '<option value="' . $val . '"' . ($filters['channel'] === $val ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select><select class="fp-input" name="status"><option value="">All Statuses</option>';
        foreach (['success' => 'Success', 'failed' => 'Failed', 'pending' => 'Pending', 'reversed' => 'Reversed'] as $val => $label) {
            $html .= '<option value="' . $val . '"' . ($filters['status'] === $val ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select>'
            . '<input class="fp-input" type="date" name="date_from" value="' . self::e($filters['date_from']) . '" title="From">'
            . '<input class="fp-input" type="date" name="date_to" value="' . self::e($filters['date_to']) . '" title="To">'
            . '<button type="submit" class="fp-btn">Filter</button>'
            . '<a class="fp-btn fp-btn-outline" href="addonmodules.php?' . self::e(http_build_query(array_merge($filters, ['module' => 'flexpay_dashboard', 'fp_tab' => 'export']))) . '">&#11015; Export CSV</a>'
            . '<span style="font-size:12px;color:#888;">' . number_format($total) . ' result(s)</span>'
            . '</form>';

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Channel</th><th>Receipt</th><th>Phone</th><th>Reference</th>'
            . '<th>Amount</th><th>Invoice</th><th>Status</th><th>Outcome</th></tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">No transactions found for the selected filters.</td></tr>';
        }

        foreach ($rows as $row) {
            $html .= '<tr title="' . self::e($row->result_desc) . '">'
                . '<td>' . self::e($row->created_at) . '</td>'
                . '<td>' . self::e(strtoupper($row->channel)) . ($row->direction === 'out' ? ' <span style="color:#c0392b;">&#8599;</span>' : '') . '</td>'
                . '<td>' . self::e($row->mpesa_receipt ?: '—') . '</td>'
                . '<td>' . self::e(self::displayPhone((string) $row->phone)) . '</td>'
                . '<td>' . self::e($row->account_reference ?: '—') . '</td>'
                . '<td>KES ' . number_format((float) $row->amount, 2) . '</td>'
                . '<td>' . self::invoiceLink($row->invoice_id) . '</td>'
                . '<td>' . self::statusBadge((string) $row->status) . '</td>'
                . '<td>' . self::outcomeBadge($row->payment_outcome ?? null) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        if ($pages > 1) {
            $html .= '<div style="margin-top:16px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
            // Windowed pagination: first, last, and ±3 around the current page.
            $shown = array_unique(array_merge([1, $pages], range(max(1, $page - 3), min($pages, $page + 3))));
            sort($shown);
            $prev = 0;
            foreach ($shown as $p) {
                if ($prev && $p > $prev + 1) {
                    $html .= '<span style="color:#999;">…</span>';
                }
                $qs = http_build_query(array_merge($filters, ['page' => $p, 'module' => 'flexpay_dashboard', 'fp_tab' => 'transactions']));
                $html .= '<a href="addonmodules.php?' . self::e($qs) . '" class="fp-btn ' . ($p === $page ? '' : 'fp-btn-outline') . '" style="padding:5px 10px;">' . $p . '</a>';
                $prev = $p;
            }
            $html .= '</div>';
        }

        return $html;
    }

    public static function statusBadge(string $status): string
    {
        $map = [
            'success'  => 'fp-badge-success',
            'failed'   => 'fp-badge-failed',
            'pending'  => 'fp-badge-pending',
            'reversed' => 'fp-badge-reversed',
        ];
        return '<span class="fp-badge ' . ($map[$status] ?? 'fp-badge-pending') . '">' . self::e(strtoupper($status)) . '</span>';
    }

    /** Badge for partial / overpaid / possible-partial outcomes; blank for exact. */
    public static function outcomeBadge(?string $outcome): string
    {
        $map = [
            'partial'          => ['fp-badge-pending', 'PARTIAL — BALANCE DUE'],
            'overpaid'         => ['fp-badge-success', 'OVERPAID — CREDITED'],
            'possible_partial' => ['fp-badge-failed', 'POSSIBLE PARTIAL'],
        ];

        if (!$outcome || !isset($map[$outcome])) {
            return '<span style="color:#bbb;">—</span>';
        }

        [$class, $label] = $map[$outcome];
        return '<span class="fp-badge ' . $class . '">' . self::e($label) . '</span>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Refunds tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderRefunds(string $modulelink): string
    {
        $refunds = FlexPayStore::listRefunds(100);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">Refunds are sent automatically (M-Pesa B2C) when you refund a paid M-Pesa invoice in WHMCS (Billing → Invoices → Refund), to the phone number that originally paid. '
            . 'A <strong>failed</strong> refund means WHMCS recorded it but no money reached the customer — use <em>Retry</em> once the cause is fixed (for example, after topping up your B2C account).</p>';

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Invoice</th><th>Phone</th><th>Amount</th><th>Original Receipt</th>'
            . '<th>Status</th><th>Result</th><th>Initiated By</th><th></th></tr></thead><tbody>';

        if (empty($refunds)) {
            $html .= '<tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">No refunds have been processed yet.</td></tr>';
        }

        foreach ($refunds as $r) {
            $action = '';
            if ($r->status === 'failed' && $r->retried_as === null) {
                $action = '<form method="post" style="margin:0;">' . FlexPaySecurity::csrfField()
                    . '<input type="hidden" name="fp_action" value="retry_refund">'
                    . '<input type="hidden" name="refund_id" value="' . (int) $r->id . '">'
                    . '<button type="submit" class="fp-btn" style="padding:4px 10px;" onclick="return confirm(\'Send KES ' . number_format((float) $r->amount, 2) . ' to ' . self::e(DarajaClient::toDisplayPhone((string) $r->phone)) . ' again?\');">Retry</button>'
                    . '</form>';
            } elseif ($r->retried_as) {
                $action = '<span style="font-size:11px;color:#888;">retried</span>';
            }

            $html .= '<tr>'
                . '<td>' . self::e($r->created_at) . '</td>'
                . '<td>' . self::invoiceLink($r->invoice_id) . '</td>'
                . '<td>' . self::e(DarajaClient::toDisplayPhone((string) $r->phone)) . '</td>'
                . '<td>KES ' . number_format((float) $r->amount, 2) . '</td>'
                . '<td>' . self::e($r->original_trans_id ?: '—') . '</td>'
                . '<td>' . self::statusBadge((string) $r->status) . '</td>'
                . '<td style="font-size:11px;color:#666;max-width:260px;">' . self::e($r->result_desc ?: '—') . '</td>'
                . '<td>' . self::e($r->initiated_by ?: '—') . '</td>'
                . '<td>' . $action . '</td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reconciliation tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderReconciliation(string $modulelink): string
    {
        $items = FlexPayStore::listUnmatched(true);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">These payments could not be matched to an open invoice automatically (typo\'d account number, Till payment from an unknown phone, already-paid invoice, …). '
            . 'Apply each one to the right invoice — it is recorded exactly as if it had matched automatically — or dismiss payments that aren\'t for any invoice.</p>';

        if (empty($items)) {
            return $html . '<div class="fp-card" style="text-align:center;color:#888;padding:30px;">&#9989; No unmatched payments. Everything is reconciled.</div>';
        }

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Receipt</th><th>Phone</th><th>Customer Name</th><th>Entered Reference</th>'
            . '<th>Amount</th><th>Match to Invoice</th><th></th></tr></thead><tbody>';

        foreach ($items as $item) {
            $hint      = trim((string) ($item->notes ?? ''));
            $suggested = $item->suggested_invoice_id ? (int) $item->suggested_invoice_id : '';

            $html .= '<tr>'
                . '<td>' . self::e($item->created_at) . '</td>'
                . '<td>' . self::e($item->trans_id) . '</td>'
                . '<td>' . self::e(self::displayPhone((string) $item->phone)) . '</td>'
                . '<td>' . self::e($item->customer_name ?: '—') . '</td>'
                . '<td><code>' . self::e($item->bill_ref) . '</code></td>'
                . '<td>KES ' . number_format((float) $item->amount, 2) . '</td>'
                . '<td>'
                . '<form method="post" style="display:flex;gap:6px;margin:0;">' . FlexPaySecurity::csrfField()
                . '<input type="hidden" name="fp_action" value="reconcile">'
                . '<input type="hidden" name="unmatched_id" value="' . (int) $item->id . '">'
                . '<input class="fp-input" style="width:90px;" type="number" min="1" name="invoice_id" placeholder="Inv #" value="' . self::e($suggested) . '" required>'
                . '<button type="submit" class="fp-btn" style="padding:6px 12px;" onclick="return confirm(\'Apply KES ' . number_format((float) $item->amount, 2) . ' to this invoice?\');">Apply</button>'
                . '</form>'
                . '</td><td>'
                . '<form method="post" style="margin:0;" onsubmit="var r=prompt(\'Why dismiss this payment? (e.g. not a billing payment)\');if(r===null)return false;this.dismiss_reason.value=r;return true;">' . FlexPaySecurity::csrfField()
                . '<input type="hidden" name="fp_action" value="dismiss_unmatched">'
                . '<input type="hidden" name="unmatched_id" value="' . (int) $item->id . '">'
                . '<input type="hidden" name="dismiss_reason" value="">'
                . '<button type="submit" class="fp-btn fp-btn-outline" style="padding:6px 10px;">Dismiss</button>'
                . '</form>'
                . '</td></tr>';

            if ($hint) {
                $html .= '<tr><td></td><td colspan="7" style="font-size:11px;color:#856404;background:#fffbf0;padding:6px 10px;">&#128161; ' . self::e($hint) . '</td></tr>';
            }
        }

        return $html . '</tbody></table>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Balance tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderBalance(string $modulelink): string
    {
        $gw        = FlexPayStore::getFlexPayGatewayParams();
        $shortcode = DarajaClient::c2bShortcode($gw);
        $history   = FlexPayStore::getBalanceHistory($shortcode, 30);
        $latest    = $history[0] ?? null;

        $html = '<form method="post" style="margin-bottom:20px;">' . FlexPaySecurity::csrfField()
            . '<input type="hidden" name="fp_action" value="trigger_balance">'
            . '<button type="submit" class="fp-btn">&#128260; Request Fresh Balance from Daraja</button>'
            . ' <span style="font-size:12px;color:#999;margin-left:8px;">Shortcode ' . self::e($shortcode ?: '(not set)') . '. Requires initiator credentials. The result arrives asynchronously — refresh after a few seconds.</span>'
            . '</form>';

        if ($latest) {
            $html .= '<div class="fp-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));margin-bottom:24px;">'
                . '<div class="fp-card"><div class="fp-stat-label">Working Account</div><div class="fp-stat-value">KES ' . number_format((float) $latest->working_account, 2) . '</div></div>'
                . '<div class="fp-card"><div class="fp-stat-label">Utility Account</div><div class="fp-stat-value">KES ' . number_format((float) $latest->utility_account, 2) . '</div></div>'
                . '<div class="fp-card"><div class="fp-stat-label">Charges Paid Account</div><div class="fp-stat-value">KES ' . number_format((float) $latest->charges_account, 2) . '</div></div>'
                . '</div><p style="font-size:12px;color:#999;">Last updated: ' . self::e($latest->created_at) . '</p>';
        } else {
            $html .= '<div class="fp-card" style="color:#888;">No balance data yet — click the button above to request one.</div>';
        }

        if (!empty($history)) {
            $html .= '<h4 style="margin-top:28px;">Balance History</h4>'
                . '<table class="fp-table"><thead><tr><th>Date</th><th>Working</th><th>Utility</th><th>Charges</th></tr></thead><tbody>';
            foreach ($history as $h) {
                $html .= '<tr><td>' . self::e($h->created_at) . '</td>'
                    . '<td>KES ' . number_format((float) $h->working_account, 2) . '</td>'
                    . '<td>KES ' . number_format((float) $h->utility_account, 2) . '</td>'
                    . '<td>KES ' . number_format((float) $h->charges_account, 2) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tools tab
    // ─────────────────────────────────────────────────────────────────────

    private static function toolCard(string $title, string $intro, string $body, string $style = ''): string
    {
        return '<div class="fp-card"' . ($style ? ' style="' . $style . '"' : '') . '>'
            . '<h4 style="margin:0 0 10px;">' . $title . '</h4>'
            . ($intro !== '' ? '<p style="font-size:12px;color:#777;margin:0 0 12px;">' . $intro . '</p>' : '')
            . $body . '</div>';
    }

    private static function actionForm(string $action, string $fields, string $button, string $buttonClass = 'fp-btn', string $confirm = ''): string
    {
        return '<form method="post">' . FlexPaySecurity::csrfField()
            . '<input type="hidden" name="fp_action" value="' . self::e($action) . '">'
            . $fields
            . '<button type="submit" class="' . $buttonClass . '"' . ($confirm !== '' ? ' onclick="return confirm(\'' . self::e($confirm) . '\');"' : '') . '>' . $button . '</button>'
            . '</form>';
    }

    private static function field(string $type, string $name, string $placeholder, bool $required = true): string
    {
        return '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="' . $type . '" name="' . $name . '" placeholder="' . self::e($placeholder) . '"'
            . ($type === 'number' ? ' min="1"' : '') . ($required ? ' required' : '') . '>';
    }

    public static function renderTools(string $modulelink): string
    {
        $gw        = FlexPayStore::getFlexPayGatewayParams();
        $isSandbox = (($gw['testMode'] ?? '') === 'on');
        $lastReg   = (string) FlexPayStore::getSetting('c2b_registration_last_success', '');
        $regNote   = (string) FlexPayStore::getSetting('c2b_registration_note', '');
        $license   = FlexPayLicense::check($gw);

        $html = '<div class="fp-grid" style="grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));">';

        $html .= self::toolCard(
            'FlexPay License',
            '',
            '<p style="font-size:13px;margin:0 0 10px;">Status: <span class="fp-badge ' . ($license['valid'] ? 'fp-badge-success' : 'fp-badge-failed') . '">' . self::e($license['status']) . '</span></p>'
            . '<p style="font-size:12px;color:#666;margin:0 0 12px;">' . self::e($license['message']) . '</p>'
            . self::actionForm('recheck_license', '', 'Re-check License Now', 'fp-btn fp-btn-outline')
        );

        $html .= self::toolCard(
            '&#129658; Test Configuration',
            'Checks your Daraja credentials, HTTPS System URL, initiator credentials and callback security — without moving any money.',
            self::actionForm('test_connection', '', 'Run Test', 'fp-btn fp-btn-outline')
        );

        $html .= self::toolCard(
            '&#128269; Verify a Payment',
            'Customer says they paid but the invoice didn\'t update? Enter their M-Pesa receipt (e.g. <code>NLJ7RT61SV</code>), the checkout request ID, or the account reference. We check our records first, then ask Safaricom. With an invoice number, a confirmed payment is applied automatically.',
            self::actionForm('verify_payment', self::field('text', 'verify_reference', 'Receipt, checkout ID, or reference') . self::field('number', 'verify_invoice_id', 'Invoice # (optional — applies payment if found)', false), 'Verify Payment'),
            'border-color:#007229;background:#f6fbf7;'
        );

        $html .= self::toolCard(
            '&#9203; Resolve Pending STK Payments',
            'Asks Safaricom for the final result of every STK push still pending after 2 minutes, and credits or fails it. Also runs automatically on each WHMCS cron run.',
            self::actionForm('run_sweeper', '', 'Check Pending Now', 'fp-btn fp-btn-outline')
        );

        $html .= self::toolCard(
            'Reset Customer Verify Limit',
            'The invoice-page "Verify your payment" box is rate-limited to prevent abuse. If a genuine customer gets blocked after a few retries, reset their limit here.',
            self::actionForm('reset_verify_limit', self::field('number', 'reset_invoice_id', 'Invoice #'), 'Reset Limit', 'fp-btn fp-btn-outline')
        );

        $html .= self::toolCard(
            'Transaction Status Query',
            'Ask Safaricom for the status of any M-Pesa receipt. A confirmed payment into your shortcode that isn\'t on file yet is added to the Reconciliation queue.',
            self::actionForm('trigger_status', self::field('text', 'transaction_id', 'e.g. NLJ7RT61SV'), 'Check Status')
        );

        $html .= self::toolCard(
            'Transaction Reversal',
            'Reverse a completed incoming payment. This moves real money back to the customer and cannot be undone. Record the matching refund on the invoice in WHMCS afterwards.',
            self::actionForm('trigger_reversal', self::field('text', 'transaction_id', 'Transaction ID, e.g. NLJ7RT61SV') . self::field('number', 'amount', 'Amount (KES)'), 'Reverse Transaction', 'fp-btn fp-btn-danger', 'Reverse this transaction? This cannot be undone.')
        );

        $systemUrl = FlexPayStore::systemUrl($gw);
        $html .= self::toolCard(
            'C2B URL Registration',
            'C2B URLs auto-register whenever your shortcode or domain changes. Each URL carries a secret key so FlexPay can tell Safaricom\'s notifications from forgeries.',
            '<p style="font-size:12px;color:#777;margin:0 0 8px;">Last successful registration: <strong>' . self::e($lastReg !== '' ? $lastReg : 'never') . '</strong></p>'
            . '<p style="font-size:11px;color:#999;margin:0 0 12px;word-break:break-all;">Confirmation URL: <code>' . self::e(preg_replace('/k=[a-f0-9]+/', 'k=••••', FlexPaySecurity::callbackUrl($systemUrl, 'c2b_receipt'))) . '</code></p>'
            . ($regNote !== '' ? '<p style="font-size:12px;color:#856404;background:#fff3cd;padding:8px;border-radius:5px;">' . self::e($regNote) . '</p>' : '')
            . self::actionForm('register_c2b', '', 'Force Re-Register Now', 'fp-btn fp-btn-outline')
        );

        $html .= self::toolCard(
            'Simulate C2B Payment' . ($isSandbox ? '' : ' <span style="font-size:11px;color:#c0392b;">(Sandbox only)</span>'),
            $isSandbox ? 'Test the full C2B reconciliation flow end-to-end without a real phone.' : '',
            $isSandbox
                ? self::actionForm('simulate_c2b', self::field('number', 'sim_amount', 'Amount (KES)') . self::field('text', 'sim_phone', 'Phone, e.g. 0708374149') . self::field('text', 'sim_bill_ref', 'Account Ref, e.g. INV-42', false), 'Simulate Payment')
                : '<p style="font-size:12px;color:#888;">Switch to Sandbox / Test Mode in the gateway settings to use this tool.</p>'
        );

        return $html . '</div>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // API Log tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderApiLog(string $modulelink, array $get = []): string
    {
        $operation  = preg_replace('/[^a-z0-9_]/', '', (string) ($get['op'] ?? ''));
        $failedOnly = !empty($get['failed']);
        $logs       = FlexPayStore::listApiLog(200, $operation !== '' ? $operation : null, $failedOnly ? false : null);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">Audit trail of every Daraja API call and security event — automated (STK, C2B registration, callbacks, cron) and manual (Tools tab). Secrets are never logged.</p>';

        $html .= '<form method="get" action="addonmodules.php" style="margin-bottom:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">'
            . '<input type="hidden" name="module" value="flexpay_dashboard"><input type="hidden" name="fp_tab" value="apilog">'
            . '<select class="fp-input" name="op"><option value="">All operations</option>';
        foreach (FlexPayStore::listApiLogOperations() as $op) {
            $html .= '<option value="' . self::e($op) . '"' . ($op === $operation ? ' selected' : '') . '>' . self::e($op) . '</option>';
        }
        $html .= '</select><label style="font-size:13px;"><input type="checkbox" name="failed" value="1"' . ($failedOnly ? ' checked' : '') . '> Failures only</label>'
            . '<button type="submit" class="fp-btn">Filter</button></form>';

        $html .= '<table class="fp-table"><thead><tr><th>Date</th><th>Operation</th><th>Triggered By</th><th>Result</th><th>Details</th></tr></thead><tbody>';

        if (empty($logs)) {
            $html .= '<tr><td colspan="5" style="text-align:center;color:#999;padding:24px;">No API calls logged yet.</td></tr>';
        }

        foreach ($logs as $log) {
            $html .= '<tr>'
                . '<td style="white-space:nowrap;">' . self::e($log->created_at) . '</td>'
                . '<td>' . self::e($log->operation) . '</td>'
                . '<td>' . self::e($log->triggered_by) . '</td>'
                . '<td>' . ($log->success ? '<span class="fp-badge fp-badge-success">OK</span>' : '<span class="fp-badge fp-badge-failed">FAILED</span>') . '</td>'
                . '<td style="font-size:11px;color:#888;max-width:480px;">'
                . '<details><summary style="cursor:pointer;">' . self::e(self::truncate((string) $log->response_data, 120)) . '</summary>'
                . '<div style="margin-top:6px;"><strong>Request:</strong><pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;">' . self::e(self::truncate((string) $log->request_data, 4000)) . '</pre>'
                . '<strong>Response:</strong><pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;">' . self::e(self::truncate((string) $log->response_data, 4000)) . '</pre></div>'
                . '</details></td>'
                . '</tr>';
        }

        return $html . '</tbody></table>';
    }

    /** Byte-safe truncation without depending on mbstring. */
    private static function truncate(string $str, int $maxLen): string
    {
        if (strlen($str) <= $maxLen) {
            return $str;
        }
        // Don't cut a UTF-8 sequence in half.
        $cut = substr($str, 0, $maxLen);
        while ($cut !== '' && (ord($cut[strlen($cut) - 1]) & 0xC0) === 0x80) {
            $cut = substr($cut, 0, -1);
        }
        if ($cut !== '' && ord($cut[strlen($cut) - 1]) >= 0xC0) {
            $cut = substr($cut, 0, -1);
        }
        return $cut . '…';
    }
}

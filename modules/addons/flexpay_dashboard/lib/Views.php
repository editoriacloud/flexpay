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
                $count = count(FlexPayStore::listUnmatched(true));
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
        $liveUrl = htmlspecialchars($modulelink, ENT_QUOTES) . '&fp_tab=live_data';

        return '
        <script>
        (function () {
            var FP_LIVE_URL = ' . json_encode($liveUrl) . ';
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

    // ─────────────────────────────────────────────────────────────────────
    // Overview tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderOverview(string $modulelink, int $days): string
    {
        $stats = FlexPayStore::getStats($days);
        $gw    = FlexPayStore::getFlexPayGatewayParams();

        $license = FlexPayLicense::check($gw);
        $licenseHtml = '';
        if (!$license['valid']) {
            $licenseHtml = '<div class="fp-card" style="border-color:#f5c6cb;background:#fff8f8;margin-bottom:16px;">'
                . '<strong style="color:#721c24;">&#9888; FlexPay License Issue: ' . htmlspecialchars($license['status']) . '</strong><br>'
                . '<span style="font-size:13px;color:#721c24;">' . htmlspecialchars($license['message']) . '</span> '
                . '<a href="' . htmlspecialchars($modulelink) . '&fp_tab=tools" style="font-size:13px;color:#007229;font-weight:600;">Go to Tools to re-check &rarr;</a>'
                . '</div>';
        }

        $modeLabel = (($gw['testMode'] ?? '') === 'on')
            ? '<span class="fp-badge fp-badge-pending">SANDBOX MODE</span>'
            : '<span class="fp-badge fp-badge-success">LIVE MODE</span>';

        $lastBalance = FlexPayStore::getLatestBalance($gw['businessShortcode'] ?? '');

        $html = $licenseHtml . '<div style="margin-bottom:16px;">' . $modeLabel . '</div>';

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
            $html .= '<div class="fp-card"><div class="fp-stat-label">' . htmlspecialchars($label) . '</div>'
                . '<div class="fp-stat-value" style="color:' . $color . ';" id="' . $liveId . '">' . $value . '</div></div>';
        }

        $html .= '</div>';

        $html .= '<div style="font-size:13px;color:#888;margin-bottom:18px;">Showing activity for the last ' . $days . ' days. STK Push successes: <strong>' . $stats['stk_count'] . '</strong> &nbsp;|&nbsp; C2B successes: <strong>' . $stats['c2b_count'] . '</strong></div>';

        if ($lastBalance) {
            $html .= '<div class="fp-card" style="margin-bottom:18px;">';
            $html .= '<div style="font-weight:600;margin-bottom:10px;">Latest Account Balance <span style="font-size:11px;color:#999;font-weight:400;">(as of ' . htmlspecialchars($lastBalance->created_at) . ')</span></div>';
            $html .= '<div style="display:flex;gap:30px;flex-wrap:wrap;">';
            $html .= '<div><div class="fp-stat-label">Working Account</div><div style="font-size:18px;font-weight:700;" id="fp-live-balance-working">KES ' . number_format($lastBalance->working_account, 2) . '</div></div>';
            $html .= '<div><div class="fp-stat-label">Utility Account</div><div style="font-size:18px;font-weight:700;" id="fp-live-balance-utility">KES ' . number_format($lastBalance->utility_account, 2) . '</div></div>';
            $html .= '</div></div>';
        } else {
            $html .= '<div class="fp-card" style="margin-bottom:18px;color:#888;font-size:13px;">No balance snapshot yet. Visit the <a href="' . htmlspecialchars($modulelink) . '&fp_tab=balance">Balance tab</a> to request one.</div>';
        }

        if ($stats['unmatched_count'] > 0) {
            $html .= '<div class="fp-card" style="border-color:#f5c6cb;background:#fff8f8;">'
                . '<strong>&#9888; ' . $stats['unmatched_count'] . ' C2B payment(s) need reconciliation.</strong> '
                . '<a href="' . htmlspecialchars($modulelink) . '&fp_tab=reconciliation" class="fp-btn" style="margin-left:10px;">Review now</a>'
                . '</div>';
        }

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transactions tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderTransactions(string $modulelink, array $get): string
    {
        $page    = max(1, (int) ($get['page'] ?? 1));
        $filters = [
            'channel' => $get['channel'] ?? '',
            'status'  => $get['status']  ?? '',
            'search'  => $get['search']  ?? '',
        ];

        $result = FlexPayStore::listTransactions($filters, $page, 25);
        $rows   = $result['data'];
        $total  = $result['total'];
        $pages  = max(1, (int) ceil($total / 25));

        $html = '<div id="fp-new-transactions-banner" style="display:none;align-items:center;justify-content:space-between;background:#e8f4fd;border:1px solid #b8daff;color:#004085;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:13px;">'
            . '<span>&#128276; <span class="fp-new-count">0</span> new transaction(s) have arrived since you opened this page.</span>'
            . '<a href="' . htmlspecialchars($modulelink) . '&fp_tab=transactions" class="fp-btn" style="padding:5px 12px;">Refresh</a>'
            . '</div>';

        $html .= '<form method="get" style="margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">';
        $html .= '<input type="hidden" name="module" value="flexpay_dashboard">';
        $html .= '<input type="hidden" name="fp_tab" value="transactions">';
        $html .= '<input class="fp-input" type="text" name="search" placeholder="Search receipt, phone, reference…" value="' . htmlspecialchars($filters['search']) . '">';
        $html .= '<select class="fp-input" name="channel"><option value="">All Channels</option>';
        foreach (['stk' => 'STK Push', 'c2b' => 'C2B', 'b2c' => 'B2C / Refund', 'reversal' => 'Reversal'] as $val => $label) {
            $sel = ($filters['channel'] === $val) ? ' selected' : '';
            $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $html .= '</select>';
        $html .= '<select class="fp-input" name="status"><option value="">All Statuses</option>';
        foreach (['success' => 'Success', 'failed' => 'Failed', 'pending' => 'Pending', 'reversed' => 'Reversed'] as $val => $label) {
            $sel = ($filters['status'] === $val) ? ' selected' : '';
            $html .= '<option value="' . $val . '"' . $sel . '>' . $label . '</option>';
        }
        $html .= '</select>';
        $html .= '<button type="submit" class="fp-btn">Filter</button>';
        $html .= '</form>';

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Channel</th><th>Receipt</th><th>Phone</th><th>Reference</th>'
            . '<th>Amount</th><th>Invoice</th><th>Status</th><th>Outcome</th></tr></thead><tbody>';

        if (empty($rows)) {
            $html .= '<tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">No transactions found for the selected filters.</td></tr>';
        }

        foreach ($rows as $row) {
            $invoiceLink = $row->invoice_id
                ? '<a href="invoices.php?action=edit&id=' . (int) $row->invoice_id . '" target="_blank">#' . (int) $row->invoice_id . '</a>'
                : '<span style="color:#999;">—</span>';

            $html .= '<tr>'
                . '<td>' . htmlspecialchars($row->created_at) . '</td>'
                . '<td>' . htmlspecialchars(strtoupper($row->channel)) . '</td>'
                . '<td>' . htmlspecialchars($row->mpesa_receipt ?: '—') . '</td>'
                . '<td>' . htmlspecialchars(DarajaClient::toDisplayPhone($row->phone ?: '')) . '</td>'
                . '<td>' . htmlspecialchars($row->account_reference ?: '—') . '</td>'
                . '<td>KES ' . number_format((float) $row->amount, 2) . '</td>'
                . '<td>' . $invoiceLink . '</td>'
                . '<td>' . self::statusBadge($row->status) . '</td>'
                . '<td>' . self::outcomeBadge($row->payment_outcome ?? null) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        if ($pages > 1) {
            $html .= '<div style="margin-top:16px;display:flex;gap:6px;">';
            for ($p = 1; $p <= $pages; $p++) {
                $isActive = ($p === $page);
                $qs = http_build_query(array_merge($filters, ['page' => $p, 'module' => 'flexpay_dashboard', 'fp_tab' => 'transactions']));
                $html .= '<a href="addonmodules.php?' . $qs . '" class="fp-btn ' . ($isActive ? '' : 'fp-btn-outline') . '" style="padding:5px 10px;">' . $p . '</a>';
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
        $class = $map[$status] ?? 'fp-badge-pending';
        return '<span class="fp-badge ' . $class . '">' . htmlspecialchars(strtoupper($status)) . '</span>';
    }

    /**
     * Visual indicator for partial/overpaid/possible-partial outcomes —
     * see FlexPayStore::classifyPaymentOutcome() for how these are
     * derived. Returns an empty string for 'exact' or unknown/null, since
     * a fully-paid invoice needs no special callout.
     */
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
        return '<span class="fp-badge ' . $class . '">' . htmlspecialchars($label) . '</span>';
    }

    // ─────────────────────────────────────────────────────────────────────
    // Refunds tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderRefunds(string $modulelink): string
    {
        $refunds = FlexPayStore::listRefunds(100);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">Refunds are triggered automatically when an admin issues a WHMCS refund on a paid M-Pesa invoice (via Billing → Invoices → Refund). This list tracks every B2C disbursement attempt and its outcome.</p>';

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Invoice</th><th>Phone</th><th>Amount</th><th>Original Receipt</th>'
            . '<th>Conversation ID</th><th>Status</th><th>Initiated By</th></tr></thead><tbody>';

        if (empty($refunds)) {
            $html .= '<tr><td colspan="8" style="text-align:center;color:#999;padding:24px;">No refunds have been processed yet.</td></tr>';
        }

        foreach ($refunds as $r) {
            $html .= '<tr>'
                . '<td>' . htmlspecialchars($r->created_at) . '</td>'
                . '<td><a href="invoices.php?action=edit&id=' . (int) $r->invoice_id . '" target="_blank">#' . (int) $r->invoice_id . '</a></td>'
                . '<td>' . htmlspecialchars(DarajaClient::toDisplayPhone($r->phone)) . '</td>'
                . '<td>KES ' . number_format((float) $r->amount, 2) . '</td>'
                . '<td>' . htmlspecialchars($r->original_trans_id ?: '—') . '</td>'
                . '<td style="font-size:11px;color:#888;">' . htmlspecialchars($r->conversation_id ?: '—') . '</td>'
                . '<td>' . self::statusBadge($r->status) . '</td>'
                . '<td>' . htmlspecialchars($r->initiated_by ?: '—') . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Reconciliation tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderReconciliation(string $modulelink): string
    {
        $items = FlexPayStore::listUnmatched(true);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">These C2B payments arrived with an account reference that didn\'t match an open WHMCS invoice (commonly a typo, e.g. customer entered "INV42" instead of "INV-42", or paid against an already-settled invoice). Match each one to the correct invoice below — the payment will be applied exactly as if it had matched automatically.</p>';

        if (empty($items)) {
            $html .= '<div class="fp-card" style="text-align:center;color:#888;padding:30px;">&#9989; No unmatched payments. Everything is reconciled.</div>';
            return $html;
        }

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Receipt</th><th>Phone</th><th>Customer Name</th><th>Entered Reference</th>'
            . '<th>Amount</th><th>Match to Invoice</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $hint = trim((string) ($item->notes ?? ''));
            $suggestedInvoiceId = '';

            // If the hint names exactly one invoice (e.g. "...toward: #42
            // (balance KES 500.00)."), pre-fill it to save typing — the
            // admin still has to actively click Apply, this never submits
            // on its own.
            if ($hint && preg_match_all('/#(\d+)/', $hint, $m) && count(array_unique($m[1])) === 1) {
                $suggestedInvoiceId = $m[1][0];
            }

            $html .= '<tr>'
                . '<td>' . htmlspecialchars($item->created_at) . '</td>'
                . '<td>' . htmlspecialchars($item->trans_id) . '</td>'
                . '<td>' . htmlspecialchars(DarajaClient::toDisplayPhone($item->phone)) . '</td>'
                . '<td>' . htmlspecialchars($item->customer_name ?: '—') . '</td>'
                . '<td><code>' . htmlspecialchars($item->bill_ref) . '</code></td>'
                . '<td>KES ' . number_format((float) $item->amount, 2) . '</td>'
                . '<td>'
                . '<form method="post" style="display:flex;gap:6px;">'
                . '<input type="hidden" name="fp_action" value="reconcile">'
                . '<input type="hidden" name="unmatched_id" value="' . (int) $item->id . '">'
                . '<input class="fp-input" style="width:90px;" type="number" name="invoice_id" placeholder="Inv #" value="' . htmlspecialchars($suggestedInvoiceId) . '" required>'
                . '<button type="submit" class="fp-btn" style="padding:6px 12px;">Apply</button>'
                . '</form>'
                . '</td>'
                . '</tr>';

            if ($hint) {
                $html .= '<tr><td></td><td colspan="6" style="font-size:11px;color:#856404;background:#fffbf0;padding:6px 10px;">&#128161; ' . htmlspecialchars($hint) . '</td></tr>';
            }
        }

        $html .= '</tbody></table>';

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Balance tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderBalance(string $modulelink): string
    {
        $gw = FlexPayStore::getFlexPayGatewayParams();
        $shortcode = $gw['businessShortcode'] ?? '';

        $history = FlexPayStore::getBalanceHistory($shortcode, 30);
        $latest  = FlexPayStore::getLatestBalance($shortcode);

        $html = '<form method="post" style="margin-bottom:20px;">';
        $html .= '<input type="hidden" name="fp_action" value="trigger_balance">';
        $html .= '<button type="submit" class="fp-btn">&#128260; Request Fresh Balance from Daraja</button>';
        $html .= ' <span style="font-size:12px;color:#999;margin-left:8px;">Requires B2C Initiator credentials configured in the gateway. Result arrives asynchronously — refresh this page after a few seconds.</span>';
        $html .= '</form>';

        if ($latest) {
            $html .= '<div class="fp-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));margin-bottom:24px;">';
            $html .= '<div class="fp-card"><div class="fp-stat-label">Working Account</div><div class="fp-stat-value">KES ' . number_format($latest->working_account, 2) . '</div></div>';
            $html .= '<div class="fp-card"><div class="fp-stat-label">Utility Account</div><div class="fp-stat-value">KES ' . number_format($latest->utility_account, 2) . '</div></div>';
            $html .= '<div class="fp-card"><div class="fp-stat-label">Charges Paid Account</div><div class="fp-stat-value">KES ' . number_format($latest->charges_account, 2) . '</div></div>';
            $html .= '</div>';
            $html .= '<p style="font-size:12px;color:#999;">Last updated: ' . htmlspecialchars($latest->created_at) . '</p>';
        } else {
            $html .= '<div class="fp-card" style="color:#888;">No balance data yet — click the button above to request one.</div>';
        }

        if (!empty($history)) {
            $html .= '<h4 style="margin-top:28px;">Balance History</h4>';
            $html .= '<table class="fp-table"><thead><tr><th>Date</th><th>Working</th><th>Utility</th><th>Charges</th></tr></thead><tbody>';
            foreach (array_reverse($history) as $h) {
                $html .= '<tr><td>' . htmlspecialchars($h->created_at) . '</td>'
                    . '<td>KES ' . number_format($h->working_account, 2) . '</td>'
                    . '<td>KES ' . number_format($h->utility_account, 2) . '</td>'
                    . '<td>KES ' . number_format($h->charges_account, 2) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tools tab — Transaction Status Query, Reversal, C2B Registration, Simulate
    // ─────────────────────────────────────────────────────────────────────

    public static function renderTools(string $modulelink): string
    {
        $gw = FlexPayStore::getFlexPayGatewayParams();
        $isSandbox = (($gw['testMode'] ?? '') === 'on');
        $lastReg = FlexPayStore::getSetting('c2b_registration_last_success', 'never');

        $html = '<div class="fp-grid" style="grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));">';

        // License status — first, since it's the thing that determines
        // whether everything else here even works.
        $license = FlexPayLicense::check($gw);
        $statusBadgeClass = $license['valid'] ? 'fp-badge-success' : 'fp-badge-failed';
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">FlexPay License</h4>';
        $html .= '<p style="font-size:13px;margin:0 0 10px;">Status: <span class="fp-badge ' . $statusBadgeClass . '">' . htmlspecialchars($license['status']) . '</span></p>';
        $html .= '<p style="font-size:12px;color:#666;margin:0 0 12px;">' . htmlspecialchars($license['message']) . '</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="recheck_license">';
        $html .= '<button type="submit" class="fp-btn fp-btn-outline">Re-check License Now</button>';
        $html .= '</form></div>';

        // Verify Payment — the most common real-world need: "I paid but it's not showing"
        $html .= '<div class="fp-card" style="border-color:#007229;background:#f6fbf7;">';
        $html .= '<h4 style="margin:0 0 10px;">&#128269; Verify a Payment</h4>';
        $html .= '<p style="font-size:12px;color:#666;margin:0 0 12px;">A customer says they paid but the invoice hasn\'t updated? Enter their M-Pesa receipt number (e.g. <code>NLJ7RT61SV</code>), the checkout request ID, or the account reference they used. We check our own records first, then ask Safaricom directly if nothing is found locally.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="verify_payment">';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="text" name="verify_reference" placeholder="Receipt, checkout ID, or reference" required>';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="number" name="verify_invoice_id" placeholder="Invoice # (optional — applies payment if found)">';
        $html .= '<button type="submit" class="fp-btn">Verify Payment</button>';
        $html .= '</form>';
        $html .= '<p style="font-size:11px;color:#999;margin:10px 0 0;">Customers also have their own "Already paid? Verify your payment" box directly on the invoice page — most genuine cases resolve themselves there without needing you. This tool is for the cases that don\'t.</p>';
        $html .= '</div>';

        // Reset a customer's self-verify rate limit, for the rare case
        // where several genuine retries (typos, etc.) trip the limit.
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">Reset Customer Verify Limit</h4>';
        $html .= '<p style="font-size:12px;color:#888;margin:0 0 12px;">The invoice-page "Verify your payment" box is rate-limited to prevent abuse. If a customer reports being blocked after a few genuine retries, reset their limit here.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="reset_verify_limit">';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="number" name="reset_invoice_id" placeholder="Invoice #" required>';
        $html .= '<button type="submit" class="fp-btn fp-btn-outline">Reset Limit</button>';
        $html .= '</form></div>';

        // Transaction Status
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">Transaction Status Query</h4>';
        $html .= '<p style="font-size:12px;color:#888;margin:0 0 12px;">Check the live status of any M-Pesa receipt directly with Safaricom.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="trigger_status">';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="text" name="transaction_id" placeholder="e.g. NLJ7RT61SV" required>';
        $html .= '<button type="submit" class="fp-btn">Check Status</button>';
        $html .= '</form></div>';

        // Reversal
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">Transaction Reversal</h4>';
        $html .= '<p style="font-size:12px;color:#888;margin:0 0 12px;">Reverse a completed transaction. Use cautiously — this moves real money back to the customer.</p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="trigger_reversal">';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="text" name="transaction_id" placeholder="Transaction ID, e.g. NLJ7RT61SV" required>';
        $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="number" name="amount" placeholder="Amount (KES)" required>';
        $html .= '<button type="submit" class="fp-btn fp-btn-danger" onclick="return confirm(\'Reverse this transaction? This cannot be undone.\');">Reverse Transaction</button>';
        $html .= '</form></div>';

        // C2B Registration
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">C2B URL Registration</h4>';
        $html .= '<p style="font-size:12px;color:#888;margin:0 0 4px;">C2B URLs auto-register whenever your shortcode or domain changes.</p>';
        $html .= '<p style="font-size:12px;color:#888;margin:0 0 12px;">Last successful registration: <strong>' . htmlspecialchars($lastReg ?: 'never') . '</strong></p>';
        $html .= '<form method="post">';
        $html .= '<input type="hidden" name="fp_action" value="register_c2b">';
        $html .= '<button type="submit" class="fp-btn fp-btn-outline">Force Re-Register Now</button>';
        $html .= '</form></div>';

        // C2B Simulate (sandbox only)
        $html .= '<div class="fp-card">';
        $html .= '<h4 style="margin:0 0 10px;">Simulate C2B Payment ' . ($isSandbox ? '' : '<span style="font-size:11px;color:#c0392b;">(Sandbox only)</span>') . '</h4>';
        if ($isSandbox) {
            $html .= '<p style="font-size:12px;color:#888;margin:0 0 12px;">Test the full C2B reconciliation flow end-to-end without a real phone.</p>';
            $html .= '<form method="post">';
            $html .= '<input type="hidden" name="fp_action" value="simulate_c2b">';
            $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="number" name="sim_amount" placeholder="Amount (KES)" required>';
            $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="text" name="sim_phone" placeholder="Phone, e.g. 0712345678" required>';
            $html .= '<input class="fp-input" style="width:100%;margin-bottom:8px;box-sizing:border-box;" type="text" name="sim_bill_ref" placeholder="Account Ref, e.g. INV-42" required>';
            $html .= '<button type="submit" class="fp-btn">Simulate Payment</button>';
            $html .= '</form>';
        } else {
            $html .= '<p style="font-size:12px;color:#888;">Switch to Sandbox / Test Mode in the gateway settings to use this tool.</p>';
        }
        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    // ─────────────────────────────────────────────────────────────────────
    // API Log tab
    // ─────────────────────────────────────────────────────────────────────

    public static function renderApiLog(string $modulelink): string
    {
        $logs = FlexPayStore::listApiLog(100);

        $html = '<p style="color:#888;font-size:13px;margin-bottom:14px;">Full audit trail of every Daraja API call made by this module — both automated (STK Push, C2B auto-registration) and manually triggered from the Tools tab.</p>';

        $html .= '<table class="fp-table"><thead><tr>'
            . '<th>Date</th><th>Operation</th><th>Triggered By</th><th>Result</th><th>Details</th></tr></thead><tbody>';

        if (empty($logs)) {
            $html .= '<tr><td colspan="5" style="text-align:center;color:#999;padding:24px;">No API calls logged yet.</td></tr>';
        }

        foreach ($logs as $log) {
            $badge = $log->success
                ? '<span class="fp-badge fp-badge-success">OK</span>'
                : '<span class="fp-badge fp-badge-failed">FAILED</span>';

            $shortResponse = self::truncate((string) $log->response_data, 120);

            $html .= '<tr>'
                . '<td>' . htmlspecialchars($log->created_at) . '</td>'
                . '<td>' . htmlspecialchars($log->operation) . '</td>'
                . '<td>' . htmlspecialchars($log->triggered_by) . '</td>'
                . '<td>' . $badge . '</td>'
                . '<td style="font-size:11px;color:#888;max-width:380px;overflow:hidden;text-overflow:ellipsis;">' . htmlspecialchars($shortResponse) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Truncate a string to a maximum byte length with an ellipsis, without
     * depending on the mbstring extension (not guaranteed present on every
     * PHP build, even though WHMCS itself recommends it).
     *
     * @param  string $str
     * @param  int    $maxLen
     * @return string
     */
    private static function truncate(string $str, int $maxLen): string
    {
        if (strlen($str) <= $maxLen) {
            return $str;
        }
        return substr($str, 0, $maxLen) . '…';
    }
}

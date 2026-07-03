# FlexPay (Daraja) — WHMCS M-Pesa Module Suite v3.0.0

A complete Safaricom Daraja (M-Pesa) integration for WHMCS: a payment
gateway with intelligent, fully-automated C2B reconciliation, plus a
standalone Dashboard addon for managing transactions, refunds, balance,
and reversals — all without touching the WHMCS API or shell.

## What's included

1. **FlexPay Gateway Module** (`modules/gateways/flexpay.php`)
   - STK Push (Lipa Na M-Pesa Online) checkout widget with live polling
   - Fully automated C2B (Paybill) payment reconciliation
   - B2C refunds (triggered automatically by WHMCS's native Refund button)
   - Manual-paybill fallback shown to customers who prefer the M-Pesa menu

2. **FlexPay Dashboard Addon Module** (`modules/addons/flexpay_dashboard/`)
   - Overview: live stats, account balance, unmatched-payment alerts
   - Transactions: full searchable/filterable ledger of every STK/C2B/B2C/Reversal
   - Refunds: tracks every B2C disbursement and its outcome
   - Reconciliation: one-click matching of unmatched C2B payments to invoices
   - Balance: on-demand Account Balance Query + historical trend
   - Tools: Transaction Status Query, Transaction Reversal, force C2B re-registration, sandbox C2B simulator
   - API Log: full audit trail of every Daraja API call, automated or manual

3. **Admin Homepage Widget** — a live 7-day stats card on the WHMCS admin
   dashboard homepage, with a link straight into the full Dashboard.

## Why "intelligent" C2B?

Most M-Pesa WHMCS integrations require you to manually call Safaricom's
C2B URL registration endpoint once, by hand, and hope you never change
your domain or shortcode afterward. FlexPay instead:

- **Auto-registers and auto-re-registers.** Every time an invoice page
  loads, the gateway checks whether your shortcode + domain + sandbox/live
  flag has changed since the last successful registration (a cheap local
  hash comparison — no Safaricom call most of the time). The instant
  something changes, it silently re-registers with Daraja. No manual step,
  ever, even after a domain migration.
- **Tolerates real customer typos.** When a customer pays via the M-Pesa
  menu and fat-fingers the account reference — `INV42` instead of `INV-42`,
  or just `42`, or `0000042` — FlexPay's reference parser extracts the
  invoice number anyway and applies the payment automatically. Only
  genuinely unparseable references land in the Reconciliation queue.
- **Strict or lenient, your choice.** Lenient mode (default) never blocks
  a real payment over a formatting quirk — it reconciles in the dashboard
  instead. Strict mode rejects unrecognised references immediately,
  before Safaricom finalizes the payment, if you'd rather customers
  retype than have anything land unmatched.

## Installation

1. Upload the full contents of this package to your WHMCS root, preserving
   folder structure exactly (`modules/gateways/...`, `modules/addons/...`).
2. **Activate the gateway:** Setup → Payments → Payment Gateways → find
   "M-Pesa via FlexPay (Daraja)" under the Inactive tab → Activate, then
   fill in your Consumer Key/Secret, Shortcode, Passkey, and (for refunds/
   reversals/balance/status) your B2C Initiator Name + Security Credential.
3. **Activate the dashboard:** Setup → Addon Modules → find "FlexPay
   Dashboard (M-Pesa / Daraja)" → Activate → Configure (defaults are fine
   for most installs).
4. That's it. C2B URLs register themselves automatically the first time
   any invoice using FlexPay is viewed. The admin homepage widget appears
   automatically once the addon is active — no further setup needed.
5. Open **Addon Modules → FlexPay Dashboard** any time to see live
   transactions, reconcile payments, check your balance, or run a
   reversal/status query.

## Database tables created automatically

`flexpay_transactions`, `flexpay_unmatched_payments`, `flexpay_refunds`,
`flexpay_balance_snapshots`, `flexpay_api_log`, `flexpay_settings`. A
manual fallback schema is included at `sql/install.sql` but you should
never need it — both modules create these tables automatically and
idempotently on first load/activation.

## Callback URLs

All Daraja callbacks route through a single file using a `?route=`
parameter — never the word "mpesa" — so they blend in with general
billing-system traffic in server logs:

```
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=stk_result
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=c2b_check
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=c2b_receipt
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=disbursement_result
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=reversal_result
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=balance_result
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=status_result
```

These are wired up and sent to Daraja automatically — you never need to
register or type them yourself.

## Requirements

- WHMCS 7.x or 8.x with Capsule/Eloquent database support (standard since WHMCS 6.0)
- PHP 7.4+ (tested against PHP 8.3)
- A Daraja app (sandbox or production) from https://developer.safaricom.co.ke
- HTTPS on your WHMCS install (Safaricom rejects HTTP callback URLs)
- cURL extension (standard with PHP)

## Version

3.0.0 — full Daraja API coverage (STK, C2B, B2C, Reversal, Transaction
Status, Account Balance), Dashboard addon, admin widget, intelligent C2B
automation.

## Customer self-verification (v3.1.0+)

Every invoice rendered by the FlexPay gateway now includes an
"Already paid? Verify your payment" box, below the manual payment
instructions. Customers can enter their M-Pesa receipt number themselves
and get an instant answer — no need to wait for support.

This is intentionally invoice-locked and rate-limited (see
`modules/gateways/flexpay/verify.php` and
`FlexPayStore::verifyPaymentForInvoice()` for the exact security model).
If you ever need to clear a stuck rate limit for a customer who's made
several genuine retries, use **Tools → Reset Customer Verify Limit** in
the FlexPay Dashboard addon.

## Partial payments & overpayment credit (v3.2.0+)

WHMCS already handles overpayment-to-credit natively — FlexPay doesn't
implement separate credit logic, it just describes what WHMCS itself
already did, so the customer and admin both see exactly what happened
("KES 200 credited to your account balance") instead of a silent side
effect.

Partial payments (WHMCS has no distinct "Partially Paid" status — these
stay Unpaid/Overdue with a reduced balance) are now visible everywhere:
the customer sees "KES X still due" immediately after paying, and the
Transactions tab shows a PARTIAL badge at a glance.

For Till (Buy Goods) payments specifically — which carry no account
reference at all — a partial payment can never be auto-applied (there's
no reliable way to know which of several open invoices it's meant for),
but the Reconciliation tab will flag it with "Possible partial payment
toward: #X (balance KES Y)" so reconciling it takes one click instead of
investigation from scratch.

## Licensing (v3.4.0+)

FlexPay is developed and licensed by **Editoria Cloud Systems**
(https://www.editoriaweb.co.ke). Every copy of FlexPay checks in with
Editoria Cloud Systems' own WHMCS installation — the licensing server
URL is hardcoded into the module and is not a customer-editable setting.

Customers only need to enter two values in their FlexPay gateway
settings, both supplied at the time of purchase:
- **FlexPay License Key**
- **Licensing Secret Key**

FlexPay validates this automatically: a periodic remote check against
Editoria Cloud Systems' licensing server, cached locally so a brief
outage doesn't interrupt a customer's M-Pesa payments, with domain/IP
binding so a license can't simply be copied onto an unauthorized install.

If a license lapses, expires, or is suspended, FlexPay blocks new
payment initiation (the customer sees a generic "temporarily
unavailable" message — never licensing internals) while still safely
completing any payment that was already in flight with Safaricom. Check
status and force a re-check anytime from **Tools → FlexPay License** in
the dashboard.

## Live dashboard updates (v3.3.0+)

The Overview tab now refreshes its stats, balance, and the
reconciliation badge automatically every ~8 seconds — no more manual
reloading to see whether a payment just landed. The Transactions tab
shows a "new transactions have arrived" banner instead of silently
reordering rows while you might be mid-action on something.

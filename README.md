# FlexPay (Daraja) — WHMCS M-Pesa Module Suite

A complete Safaricom Daraja (M-Pesa) integration for WHMCS: a payment
gateway with intelligent, fully-automated C2B reconciliation, plus a
standalone Dashboard addon for managing transactions, refunds, balance,
and reversals — all without touching the WHMCS API or shell.

## What's included

1. **FlexPay Gateway Module** (`modules/gateways/flexpay.php`)
   - STK Push (Lipa Na M-Pesa Online) checkout widget with live polling
   - Fully automated C2B (Paybill **and** Till/Buy Goods) payment reconciliation
   - B2C refunds (triggered automatically by WHMCS's native Refund button),
     paid to the phone number that actually paid
   - Optional Daraja **Dynamic QR code** the customer scans in the M-Pesa app
   - Manual-paybill fallback shown to customers who prefer the M-Pesa menu
   - Multi-currency: invoices in USD/EUR/etc. are charged in KES at your
     WHMCS exchange rates, and payments are converted back when credited

2. **FlexPay Dashboard Addon Module** (`modules/addons/flexpay_dashboard/`)
   - Overview: live stats, account balance, unmatched-payment alerts
   - Transactions: searchable/filterable ledger (channel, status, date range) with **CSV export**
   - Refunds: tracks every B2C disbursement and its outcome, with **one-click retry** of failed refunds
   - Reconciliation: one-click matching of unmatched payments to invoices (pre-filled suggestions), or dismiss
   - Balance: on-demand or daily Account Balance snapshots + history
   - Tools: Verify a Payment, Test Configuration, Resolve Pending STK, Transaction Status Query,
     Transaction Reversal, force C2B re-registration, sandbox C2B simulator
   - API Log: audit trail of every Daraja call and security event, filterable, secrets redacted
   - Cron: automatically settles STK payments whose callback never arrived, prunes old logs

3. **Admin Homepage Widget** — a live 7-day stats card on the WHMCS admin
   dashboard homepage, with a link straight into the full Dashboard.

4. **Native WHMCS integration**
   - Invoice emails: `{$flexpay_instructions}`, `{$flexpay_paybill}`,
     `{$flexpay_account}` and `{$flexpay_amount_kes}` merge fields (listed in
     the invoice template editor), so customers get the exact account number
     before they pay.
   - Admin invoice page: an M-Pesa panel with the invoice's M-Pesa payments,
     unmatched payments that may belong to it, and a **Send M-Pesa Prompt**
     button.
   - Settings are validated when you save the gateway (`_config_validate`).
   - WHMCS 8.2+: M-Pesa balance in WHMCS's gateway balances
     (`_account_balance`), and transaction details when you click a FlexPay
     transaction ID under Billing → Transactions (`_TransactionInformation`).
   - Reversals are recorded natively with WHMCS `paymentReversed()`.
   - Every Daraja call appears in Utilities → Logs → Module Log when module
     debugging is on (credentials redacted).

## Payment matching rules (v3.6.0+)

A payment is applied to an invoice **automatically only when its account
reference is exactly one open invoice**. Accepted forms of the reference:

| Customer typed | Result |
|---|---|
| `INV-42`, `inv42`, `INV 0042`, `#42` | Invoice 42 |
| `42`, `0042` | Invoice 42 (switch off with *Accept Bare Invoice Number*) |
| `2026-0077` (the WHMCS invoice number, with custom/sequential numbering) | That invoice |
| `INV-42-A`, `INV 23 and 24`, `PAY INV42`, a name, a phone number | **Unmatched** |
| nothing (Till / Buy Goods) | **Unmatched** |
| a reference that is one invoice's ID and another's invoice number | **Unmatched** (ambiguous) |
| a reference to a Paid / Cancelled invoice | **Unmatched** |

The amount and the payer's phone **never** cause a payment to be applied.
They only pre-fill a *suggestion* in the Reconciliation tab, where you can
apply the payment to an invoice, credit it to the client's account
balance, or dismiss it. STK payments carry FlexPay's own `INV-42`
reference, so they credit their invoice, but only while it is still open.
Customer self-verification queues payments for staff approval by default.

Every path that puts money on an invoice (automatic, admin Apply,
Verify) requires the invoice to be **open** (Unpaid, Overdue or Payment
Pending). Money for a client with nothing open goes to their account
balance via *Credit to client*.

When a customer pays from their own phone with the wrong reference while
the invoice page is open, the page tells them the payment arrived and staff
will confirm it. It never applies the payment and never shows payment
details. Admins get an email digest (WHMCS system notification) of new
unmatched payments and failed refunds on each cron run. Every manual action
is written to the WHMCS Activity Log.

A ledger check runs on each cron run and on Safaricom retries. It makes sure
no recorded payment can go missing from Reconciliation after an interrupted
callback.

With *C2B Validation Mode* = strict (and external validation enabled on
your shortcode by Safaricom), payments whose account number would not
match are refused at the customer's phone, before any money moves.

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

## Security model (v3.5.0+)

FlexPay moves money, so every entry point is authenticated:

- **No secrets in the browser.** The invoice widget carries only a signed,
  expiring token for that one invoice. Amount, shortcode, passkey, account
  reference and callback URL are always derived on the server.
- **Authenticated callbacks.** Every Daraja callback URL contains a secret
  per-route key generated for your install. The *Callback Security*
  setting decides what is accepted: key **or** a Safaricom source IP
  (default — keeps production shortcodes registered before v3.5 working),
  key only, IP only, or off (not recommended). A forged "payment received"
  request is rejected and logged.
- **Double-checked STK results.** A successful STK callback is confirmed
  with Daraja's STK Query API before the invoice is credited, and the amount
  credited is always the amount FlexPay asked Safaricom to collect.
- **Exactly-once crediting.** Callback retries, the C2B echo Safaricom
  sends for STK payments, the invoice poller, and the cron sweeper can all
  report the same payment. Database-level locking means it is applied once.
- **Customer self-verify** accepts M-Pesa receipt numbers only (the value
  only the payer has), is locked to the invoice, and is rate-limited per
  invoice and per IP. By default it only flags the payment for staff approval.
- **Rigid matching**: see *Payment matching rules* above. A tiny payment on a
  non-KES invoice that would round to 0.00 is never passed to WHMCS, because
  `addInvoicePayment(0)` means "pay the full balance".
- **Admin dashboard**: CSRF-protected actions, enforced role list, secrets
  redacted from the API log, OAuth tokens cached encrypted in the database
  (not in the shared `/tmp`).
- **Rate limits** on STK prompts (per IP, invoice and phone) stop the
  widget being used to spam someone's phone with PIN prompts.

If WHMCS sits behind Cloudflare or another reverse proxy, list the proxy's
IP ranges under **Trusted Proxies** so Safaricom's real IP is seen. Only
listed proxies may set `X-Forwarded-For`.

## Installation

1. Upload the full contents of this package to your WHMCS root, preserving
   folder structure exactly (`modules/gateways/...`, `modules/addons/...`).
2. **Activate the gateway:** Setup → Payments → Payment Gateways → find
   "M-Pesa via FlexPay (Daraja)" under the Inactive tab → Activate, then
   fill in your Consumer Key/Secret, Shortcode, Passkey, and (for refunds/
   reversals/balance/status) your Initiator Name + Security Credential.
   Instead of the encrypted Security Credential you can enter the Initiator
   Password and paste Safaricom's public certificate, and FlexPay encrypts
   it for you. Buy Goods merchants: set the Till Number field if your till
   differs from your store number.
3. **Activate the dashboard:** Setup → Addon Modules → find "FlexPay
   Dashboard (M-Pesa / Daraja)" → Activate → Configure (defaults are fine
   for most installs).
4. That's it. C2B URLs register themselves automatically the first time
   any invoice using FlexPay is viewed. The admin homepage widget appears
   automatically once the addon is active — no further setup needed.
5. Open **Addon Modules → FlexPay Dashboard** any time to see live
   transactions, reconcile payments, check your balance, or run a
   reversal/status query. Run **Tools → Test Configuration** once after
   setup.
6. Make sure the WHMCS cron is running. The dashboard addon uses it to
   settle STK payments whose Safaricom callback was lost, even if the
   customer closed the page.

## Database tables created automatically

`flexpay_transactions`, `flexpay_unmatched_payments`, `flexpay_refunds`,
`flexpay_balance_snapshots`, `flexpay_api_log`, `flexpay_settings`. A
manual fallback schema is included at `sql/install.sql` but you should
never need it — both modules create these tables automatically and
idempotently on first load/activation.

## Callback URLs

All Daraja callbacks route through a single file using a `?route=`
parameter — never the word "mpesa", which Daraja rejects in C2B URLs:

```
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=stk_result&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=c2b_check&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=c2b_receipt&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=disbursement_result&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=reversal_result&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=balance_result&k=<secret>
https://yourdomain.com/modules/gateways/callback/flexpay.php?route=status_result&k=<secret>
```

These are generated and sent to Daraja automatically, so you never need
to register or type them yourself. Each `k=` is a per-install, per-route
secret.

**Upgrading a live shortcode from v3.4?** Safaricom normally lets
production C2B URLs be registered only once, so the new keyed URLs may be
refused ("already registered"). FlexPay detects this, stops retrying, and
shows a note in the dashboard. Your existing URLs keep working through the
Safaricom-IP check. To switch to keyed URLs, delete the old URLs in the
Daraja portal (or ask Safaricom), then use **Tools → Force Re-Register**.

## Requirements

- WHMCS 7.x or 8.x with Capsule/Eloquent database support (standard since WHMCS 6.0)
- PHP 7.4+ (tested against PHP 8.4)
- The WHMCS cron (for the pending-payment sweeper and housekeeping)
- A Daraja app (sandbox or production) from https://developer.safaricom.co.ke
- HTTPS on your WHMCS install (Safaricom rejects HTTP callback URLs)
- cURL extension (standard with PHP)

## Version

3.0.0 — full Daraja API coverage (STK, C2B, B2C, Reversal, Transaction
Status, Account Balance), Dashboard addon, admin widget, intelligent C2B
automation.

## Testing

`tests/` contains an end-to-end suite (not shipped in the release zip). It
serves the real module files from a throwaway fake WHMCS root through PHP's
built-in web server, mocks Daraja, and drives the checkout, poll, verify
and callback endpoints over HTTP. Refunds, dashboard actions, cron hooks
and rendering run in-process.

```bash
cd tests && composer install
php run.php                                  # SQLite
FP_TEST_MYSQL=fp_e2e php run.php             # MySQL/MariaDB (user fp/fp on 127.0.0.1)
FP_TEST_MYSQL=fp_migration php migration.php # upgrade a real v3.4.0 schema
```

## Customer self-verification (v3.1.0+, queue mode v3.6.0+)

Every invoice rendered by the FlexPay gateway now includes an
"Already paid? Verify your payment" box, below the manual payment
instructions. Customers can enter their M-Pesa receipt number themselves
and get an instant answer — no need to wait for support.

This is intentionally receipt-only, invoice-locked and rate-limited (see
`modules/gateways/flexpay/verify.php` and
`FlexPayStore::verifyPaymentForInvoice()` for the exact security model).
If the receipt isn't on file yet, FlexPay asks Safaricom (Transaction
Status Query). By default (*Customer Self-Verify* = queue) a found payment
is **not** applied by the customer. It is queued in Reconciliation with
their invoice pre-filled, for one-click staff approval. In "apply" mode it
is applied immediately (for Safaricom-confirmed receipts, only when the
paying phone matches the client).
If you ever need to clear a stuck rate limit for a customer who's made
several genuine retries, use **Tools → Reset Customer Verify Limit** in
the FlexPay Dashboard addon.

## Partial payments & overpayment credit (v3.2.0+)

WHMCS already handles overpayment-to-credit natively — FlexPay doesn't
implement separate credit logic, it just describes what WHMCS itself
already did, so the customer and admin both see exactly what happened
("KES 200 credited to your account balance") instead of a silent side
effect.

Invoice balances are computed from the WHMCS ledger (`tblaccounts`) the
same way WHMCS does; `tblinvoices` has no balance column. Before v3.5 this
meant the partial/overpayment messages and hints never actually appeared.

Partial payments (WHMCS has no distinct "Partially Paid" status — these
stay Unpaid/Overdue with a reduced balance) are now visible everywhere:
the customer sees "KES X still due" immediately after paying, and the
Transactions tab shows a PARTIAL badge at a glance.

Till (Buy Goods) payments carry no account reference, so under the v3.6
matching rules they always go to Reconciliation unless they were made
through the invoice page's STK prompt (which carries the reference). The
Reconciliation tab suggests the likeliest invoice from the payer's phone
(including the SHA-256-hashed MSISDN that C2B v2 sends) and the amount,
and flags "Possible partial payment toward: #X (balance KES Y, same
phone)". You confirm it with one click.

## Licensing (v3.4.0+)

FlexPay is developed and licensed by **Editoria Cloud Systems**
(https://www.editoriaweb.co.ke). Every copy of FlexPay checks in with
Editoria Cloud Systems' own WHMCS installation

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

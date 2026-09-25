# Changelog

## v3.5.0 — Security hardening, reliability fixes, new Daraja features

### Critical security fixes
- **Daraja passkey was published on every invoice page.** The widget
  embedded the Lipa Na M-Pesa passkey in JavaScript and posted it back, and
  the "CSRF" token was an HMAC keyed on it, so anyone could forge requests.
  The widget now carries only a signed, expiring per-invoice token. Rotate
  your passkey with Safaricom if you ran v3.4 or earlier in production.
- **`checkout.php` trusted the browser** for amount, shortcode, passkey,
  account reference and callback URL. Everything is now derived server-side.
- **Callbacks were unauthenticated.** A forged `c2b_receipt` or `stk_result`
  POST could mark any invoice paid. Callback URLs now carry a per-install
  secret key, and there is a *Callback Security* setting (key or Safaricom
  IP / key only / IP only / off). Successful STK results are confirmed with
  Daraja's STK Query API before crediting, and the amount credited is the
  amount FlexPay requested.
- **`poll.php` leaked payment data** (status, receipts, amounts) for any
  invoice ID and ran Daraja queries for any checkout ID. It now requires the
  invoice token and binds every lookup to that invoice.
- **Customer self-verify could claim other people's payments** by guessing
  an account reference. It now accepts M-Pesa receipt numbers only, and
  every attempt is rate-limited per invoice and per IP.
- OAuth tokens were cached as plain files in the shared system temp
  directory. They are now stored encrypted in the database.
- License responses without an integrity hash were trusted; the hash is now
  mandatory. Check tokens use a CSPRNG.
- Dashboard: CSRF tokens on every action; the "Restrict Access To" role
  list is now actually enforced; secrets are redacted from the API log.
- Rate limits on STK prompts (per IP / invoice / phone) prevent PIN-prompt
  spam; client IPs honour `X-Forwarded-For` only from configured trusted
  proxies.

### Bug fixes
- **Only one pending STK payment (and one C2B payment) could ever be
  recorded.** `checkout_request_id` / `mpesa_receipt` had UNIQUE indexes
  with a default of `''`, so every later insert failed silently and those
  payments never auto-applied. Columns are now nullable, and existing
  installs are migrated automatically.
- `checkCbTransID()` / `checkCbInvoiceID()` call `die()` in WHMCS, which
  killed callbacks without a response and blanked dashboard pages. They are
  replaced by non-fatal equivalents.
- Payments are applied exactly once across callback retries, the C2B
  confirmation Safaricom also sends for STK payments, the poller and cron.
- Poller self-healing never ran (a pending row always answered first), and
  result codes 1032/1037 were treated as "still pending" forever. Both fixed.
- Partial/overpayment messages and hints never worked, because
  `tblinvoices` has no `balance` column. Balances are now computed from
  `tblaccounts`.
- The poller no longer reloads the page in a loop when an invoice has an
  earlier partial payment.
- Dashboard live refresh never worked: the URL was HTML-escaped inside
  JavaScript, and the JSON was followed by the admin template.
- Reversal results marked the wrong transaction as reversed.
- B2C v3 requests were missing `OriginatorConversationID`. Refunds went to
  the client's current profile phone instead of the paying phone.
- B2C `ReceiverPartyPublicName` overflowed the phone column in strict SQL.
- A phone number typed as the paybill account number was read as an
  invoice ID. References with several unrelated numbers are no longer guessed.
- C2B strict validation now returns the documented `C2B00012` (invalid
  account number) code.
- C2B auto-registration no longer retries a slow Daraja call on every invoice
  view after a failure (1-hour backoff), and handles "already registered".
- STK timestamps use Africa/Nairobi time. Expired OAuth tokens are
  refreshed and retried once, and idempotent Daraja calls retry transient errors.
- Callbacks no longer wait on the remote licensing server.
- Audit entries record the real admin username (WHMCS keeps only the admin
  ID in the session).

### New features
- Dynamic QR code on the invoice (Daraja QR API).
- Multi-currency: non-KES invoices are charged in KES at WHMCS rates, and
  payments are converted back when applied.
- Till/Buy Goods: separate till number (PartyB), and C2B matching by payer
  phone (including C2B v2 hashed MSISDNs). Amount-only matching is now
  optional and off by default.
- Security Credential can be generated from the Initiator Password and
  Safaricom's certificate.
- Configurable B2C CommandID.
- Cron sweeper settles STK payments whose callback never arrived. Daily
  housekeeping and an optional daily balance snapshot.
- Customer and admin "Verify" confirmed by a Transaction Status result can
  auto-apply the payment.
- Dashboard: CSV export, date filters, windowed pagination, refund retry,
  dismiss unmatched payments, pre-filled invoice suggestions, Test
  Configuration, Resolve Pending STK, API log filters and full request/
  response view, insecure-configuration warnings.
- End-to-end test suite (SQLite and MySQL) plus an upgrade test against a
  real v3.4.0 schema.

### Upgrade notes
- Upload the files over v3.4. Tables migrate automatically on first load.
- New settings default to safe values. Review *Callback Security* and *Trusted
  Proxies* if WHMCS is behind Cloudflare or another proxy.
- Grant your admin role access to the addon and check "Restrict Access To".

## v3.4.0 — Hardcoded licensing + branding

- The Licensing Server URL is no longer a customer-editable gateway
  setting — it's hardcoded to Editoria Cloud Systems' own licensing
  WHMCS (`https://www.editoriaweb.co.ke`). Customers now only enter
  their **License Key** and **Licensing Secret Key**; the module always
  checks in with Editoria Cloud Systems and cannot be repointed at a
  different licensing server.
- Updated all module branding/attribution to **Editoria Cloud Systems**
  (addon module author field, gateway file docblock, and a small
  "Powered by FlexPay · Editoria Cloud Systems" footer on the customer
  payment widget).
- Added a full end-user documentation guide covering installation,
  configuration, every dashboard feature, licensing, troubleshooting,
  and FAQs.

## v3.3.0 — Commercial licensing + live dashboard

### Commercial license enforcement (WHMCS Software Licensing addon)

Researched WHMCS's actual Software Licensing addon protocol — the same
mechanism used by commercial WHMCS modules generally — before writing
any code: local key caching, periodic remote verification against the
issuing WHMCS install, MD5 response signing, and domain/IP binding, all
verified against WHMCS's own documented behavior and official
integration sample.

**New `FlexPayLicense.php`** implements this protocol:
- Three new gateway settings: **License Key**, **Licensing Server URL**,
  **Licensing Secret Key** — set these to the values from your own
  WHMCS once you create FlexPay as a licensed product there.
- Remote checks happen periodically (every 3 days) against your
  licensing WHMCS's `verify.php` endpoint, with the response's MD5 hash
  verified to detect tampering before trusting it.
- Successful checks are cached locally (encoded, tamper-evident) so a
  customer's M-Pesa payments keep working through a brief outage of
  *your* licensing server — with a grace period (3+5 days) before
  failing closed.
- **Domain/IP binding is enforced even during the grace period** — this
  was caught and fixed during testing: an earlier version only checked
  domain/IP when a cached key was still within its normal freshness
  window, which meant a license copied onto an unauthorized domain could
  pass simply by being offline when checked. Now checked unconditionally,
  so grace period can never be used to bypass domain binding.
- Enforced at every payment-critical entry point: the STK widget shows a
  generic "temporarily unavailable" message to customers (never exposing
  licensing internals) when invalid; `checkout.php` refuses to initiate
  new STK pushes; refunds are blocked. Already-in-flight Daraja callbacks
  are deliberately NOT blocked — by the time a callback arrives,
  Safaricom has already told the customer their payment succeeded, so
  refusing to apply it would leave a paying customer worse off than
  letting it complete; a warning is logged instead.
- New "FlexPay License" card in the dashboard's Tools tab with a
  "Re-check License Now" button, a license-status banner on the
  Overview tab, and a license warning on the admin homepage widget.

### Live-updating dashboard (no more manual reload)

Found that the dashboard was 100% server-rendered with zero client-side
refresh — every tab only updated on a full page reload. Added a
lightweight polling layer:
- A new internal `?fp_tab=live_data` route returns fresh stats, balance,
  and the latest transactions as JSON. It deliberately runs through the
  addon's own authenticated `_output()` entry point rather than a
  separate file, so it automatically inherits WHMCS's admin session
  check instead of needing its own auth handling.
- The Overview tab's stat cards, balance figures, and the
  Reconciliation nav badge now update live every ~8 seconds without a
  page reload.
- The Transactions tab shows a "N new transaction(s) have arrived"
  banner rather than silently reordering rows under an admin's cursor
  while they might be mid-click on a reconciliation action — click to
  refresh when ready.

## v3.2.0 — Intelligent partial & overpayment handling

Researched WHMCS's native billing behavior: overpayment-to-credit is
already fully automatic (addInvoicePayment() handles it), and there's no
"Partially Paid" status — just a reduced balance. Added a shared
`classifyPaymentOutcome()` used everywhere a payment is applied, so
customers and admins both see exactly what happened ("KES 600 still
due" / "KES 200 credited to your account") instead of silence. Till
payments get partial-payment detection that informs but never
auto-applies, since there's no reliable uniqueness signal for a
less-than-exact amount match.

## v3.1.0 — Customer self-verification on the invoice page

Added an invoice-locked, rate-limited "Already paid? Verify your
payment" box directly on the invoice page.

## v3.0.2 — Critical settlement fix + intelligent Till/Paybill matching

Fixed a bug where the self-healing live-query fallback could report
"Payment confirmed!" without ever calling addInvoicePayment(). Added
intelligent Till-vs-Paybill C2B matching.

## v3.0.1 — Dashboard crash fixes

Fixed an admin-widget activation crash and an undefined-function crash
on Balance/Tools actions. Added instant, staged payment-status polling
on the invoice page.

## v3.0.0

Initial release: full Daraja API coverage, Dashboard addon, admin
widget, intelligent C2B automation.

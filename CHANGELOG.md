# Changelog

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

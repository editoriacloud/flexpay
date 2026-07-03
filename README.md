
FlexPay
M-Pesa Payment Gateway for WHMCS
Complete User & Administrator Guide
Version 3.4.0
Developed by Editoria Cloud Systems
www.editoriaweb.co.ke
 
Table of Contents
Table of Contents	2
1. Introduction	4
1.1 What FlexPay Does	4
1.2 How Payments Flow Through FlexPay	4
2. System Requirements	5
3. Installation	6
3.1 Upload the Files	6
3.2 Activate the Payment Gateway	6
3.3 Activate the Management Dashboard	6
3.4 What Happens Automatically	6
4. Gateway Configuration	7
4.1 Sandbox / Test Mode	7
4.2 Daraja API Credentials	7
4.3 STK Transaction Type	7
4.4 Account Reference Prefix	7
4.5 C2B (Manual Payment) Settings	7
4.6 B2C (Refunds, Balance, Reversals) Settings	8
4.7 Licensing Fields	8
5. Licensing & Activation	9
5.1 How Licensing Works	9
5.2 What Happens If Your License Has a Problem	9
5.3 Checking License Status	9
5.4 Resilience — What If the Licensing Server Is Briefly Unreachable?	9
6. The Customer Payment Experience	10
6.1 The Payment Widget	10
6.2 Paying via STK Push (the automatic prompt)	10
6.3 Paying Manually (Paybill or Till)	10
6.4 Customer Self-Verification	11
7. The FlexPay Dashboard	12
7.1 Dashboard Settings	12
7.2 Overview Tab	12
7.3 Transactions Tab	12
7.4 Refunds Tab	12
7.5 Reconciliation Tab	13
7.6 Balance Tab	13
7.7 Tools Tab	13
7.8 API Log Tab	13
7.9 The Admin Homepage Widget	13
8. Partial Payments & Overpayment Credit	15
8.1 Exact Payment	15
8.2 Partial Payment (Underpayment)	15
8.3 Overpayment	15
8.4 Partial Payments via Till — A Special Case	15
9. How Intelligent Till & Paybill Matching Works	16
9.1 Why Paybill and Till Need Different Approaches	16
9.2 Paybill Matching — Reference Parsing	16
9.3 Lenient vs Strict Validation Mode	16
9.4 Till Matching — Matching by Amount	16
9.5 Detecting Likely Partial Till Payments	17
9.6 Summary	17
10. Troubleshooting	18
11. Frequently Asked Questions	19
12. Support & Contact	20

1. Introduction
FlexPay is a complete M-Pesa payment gateway for WHMCS, built on Safaricom's Daraja API. It lets your customers pay invoices instantly using M-Pesa — either through an automatic STK Push prompt sent directly to their phone, or by paying manually through the M-Pesa menu (Paybill or Buy Goods/Till) — and it reconciles those payments back to the correct invoice automatically, in most cases without any manual work at all.
This guide covers everything from first installation through to day-to-day use of the management dashboard, troubleshooting, and frequently asked questions. It is written for WHMCS administrators — no programming knowledge is required to use FlexPay, though a few sections (marked clearly) go into technical depth for administrators who want to understand exactly how something works.
1.1 What FlexPay Does
•	STK Push checkout — sends an M-Pesa payment prompt straight to the customer's phone from the invoice page. They enter their PIN and the invoice is marked paid automatically, usually within a few seconds.
•	Manual Paybill and Till (Buy Goods) support — customers who prefer to pay through the M-Pesa menu themselves are fully supported too, with intelligent automatic matching back to the correct invoice.
•	Customer self-verification — if a payment doesn't reconcile automatically for any reason, customers can verify it themselves directly on the invoice page using their M-Pesa receipt number.
•	Refunds — when you issue a refund from WHMCS on an invoice paid via FlexPay, it automatically sends the money back to the customer's M-Pesa number.
•	A full management dashboard — a dedicated area inside WHMCS showing every transaction, refund, and reconciliation item, with live-updating figures and one-click tools.
•	Automatic partial-payment and overpayment handling — underpayments are clearly flagged with the remaining balance; overpayments are automatically credited to the customer's account by WHMCS, with FlexPay describing exactly what happened.
•	Commercial licensing — FlexPay is a licensed product. Each installation requires a valid license key, issued by Editoria Cloud Systems, described in full in Section 5.
1.2 How Payments Flow Through FlexPay
At a high level, every payment FlexPay handles follows one of two paths:
1.	STK Push: the customer clicks "Send M-Pesa Prompt" on the invoice page, enters their phone number, and receives a push notification on their phone. They enter their M-Pesa PIN, Safaricom processes the payment, and FlexPay marks the invoice paid — typically within 5–15 seconds.
2.	Manual payment: the customer opens the M-Pesa app or dials the M-Pesa menu themselves, and pays via Paybill (entering the invoice number as the account reference) or Buy Goods/Till (no reference needed). Safaricom notifies FlexPay the moment the payment completes, and FlexPay matches it back to the right invoice — by reference number for Paybill, or by intelligent amount-matching for Till.
Note:   Both paths are fully supported and FlexPay actively watches for either one — a customer doesn't have to use the "Send Prompt" button at all if they'd rather pay manually; the invoice page detects either kind of payment automatically.
2. System Requirements
Before installing FlexPay, confirm your WHMCS installation meets the following:
Requirement	Details
WHMCS version	7.x or 8.x with database access via Capsule/Eloquent (standard since WHMCS 6.0)
PHP version	7.4 or newer (tested on PHP 8.3)
HTTPS	Required. Safaricom's Daraja API rejects callback URLs that aren't HTTPS
cURL extension	Required — standard with virtually every PHP installation
MySQL / MariaDB	Standard WHMCS database requirements apply
Daraja account	A Safaricom Daraja developer account (sandbox or production) — see Section 4.2

Important:   Your WHMCS server's domain must be publicly reachable over HTTPS for Safaricom to deliver payment confirmations. If your WHMCS is only reachable on a local network or behind certain firewalls, M-Pesa payments will not be confirmed automatically.
3. Installation
3.1 Upload the Files
Upload the full contents of the FlexPay package to your WHMCS root directory, keeping the folder structure exactly as provided:
modules/gateways/flexpay.php
modules/gateways/callback/flexpay.php
modules/gateways/flexpay/  (supporting files)
modules/addons/flexpay_dashboard/  (the management dashboard)

You can upload these via FTP/SFTP, your hosting control panel's file manager, or by extracting the package directly on the server.
3.2 Activate the Payment Gateway
1.	Log in to your WHMCS admin area.
2.	Go to Setup → Payments → Payment Gateways.
3.	Click the "All Payment Gateways" tab and find "M-Pesa via FlexPay (Daraja)".
4.	Click it to activate, then fill in the configuration fields — every field is explained in detail in Section 4.
5.	Click Save Changes.
3.3 Activate the Management Dashboard
1.	Go to Setup → Addon Modules.
2.	Find "FlexPay Dashboard (M-Pesa / Daraja)" and click Activate.
3.	Click Configure to set who can access it (see Section 7.1).
4.	Once active, you'll find the dashboard under Addons → FlexPay Dashboard in the admin menu.
3.4 What Happens Automatically
Once both the gateway and dashboard are active, FlexPay sets itself up with no further manual steps:
•	Database tables are created automatically the first time either module loads.
•	The first time a customer views an invoice using FlexPay, the module automatically registers its callback URLs with Safaricom for manual Paybill/Till payments — you never need to do this by hand, and it re-registers itself automatically if you ever change domains.
•	A small live-stats widget appears on your WHMCS admin homepage automatically.
Note:   You will not be able to accept real payments until you also complete the Daraja API setup (Section 4.2) and enter a valid FlexPay license (Section 5).
4. Gateway Configuration
This section explains every field on the FlexPay gateway settings page (Setup → Payments → Payment Gateways → M-Pesa via FlexPay).
4.1 Sandbox / Test Mode
A simple Yes/No toggle. When ticked, FlexPay talks to Safaricom's Sandbox environment — no real money moves, and you can test the entire payment flow safely using Safaricom's test phone numbers and credentials. Untick this only once you are ready to accept real payments, and make sure you have switched to your production Daraja credentials first.
4.2 Daraja API Credentials
These come from your app on the Safaricom Daraja developer portal (developer.safaricom.co.ke). If you don't yet have an app there, you'll need to create one — Safaricom's own portal walks you through this.
Field	What to enter
Consumer Key	From your Daraja app's credentials page
Consumer Secret	From your Daraja app's credentials page
Business Shortcode	Your Paybill or Till number, e.g. 174379
Lipa Na M-Pesa Passkey	From the Daraja portal's "Lipa Na M-Pesa Online" credentials tab

4.3 STK Transaction Type
A dropdown with two options:
•	CustomerPayBillOnline — choose this if your Business Shortcode is a Paybill number.
•	CustomerBuyGoodsOnline — choose this if your Business Shortcode is a Till number.
This single setting changes how FlexPay behaves throughout the module — it affects what instructions customers see for manual payment, and which intelligent matching strategy is used for payments that arrive without an STK prompt (see Section 9).
4.4 Account Reference Prefix
Used only for Paybill-type setups. This is the prefix added to the invoice number to form the account reference customers see — for example, with the default prefix "INV", invoice #42 becomes reference "INV-42". Customers paying manually through Paybill enter this as their account number. FlexPay tolerates common typos here automatically (see Section 9.2), so this doesn't need to be exact for the customer to still be matched correctly.
4.5 C2B (Manual Payment) Settings
•	Auto-Register C2B URLs — leave this ON (the default). FlexPay automatically tells Safaricom where to send manual payment notifications, and keeps this registration up to date automatically if you ever change your shortcode or domain. There is no reason to turn this off under normal circumstances.
•	C2B Shortcode (optional) — leave blank unless your manual-payment shortcode is different from your main Business Shortcode above.
•	C2B Validation Mode — a dropdown, explained fully in Section 9.3. "Lenient" (the default) accepts every manual payment and reconciles it intelligently afterward; "Strict" rejects a payment immediately if its reference doesn't match an open invoice. Lenient is recommended for almost all use cases.
4.6 B2C (Refunds, Balance, Reversals) Settings
These three fields are required only if you want to use refunds, account balance checks, or transaction reversals from the dashboard. If you don't need these features yet, you can leave them blank and add them later.
•	B2C Shortcode — the shortcode refunds are sent from — usually the same as your Business Shortcode.
•	B2C Initiator Name — the API operator username you configured on the Daraja portal for B2C operations.
•	B2C Security Credential — the RSA-encrypted, base64-encoded initiator password generated on the Daraja portal. This is not the same as your regular M-Pesa PIN or portal password — it's a specific encrypted credential the Daraja portal generates for you.
4.7 Licensing Fields
Covered in full in Section 5. Two fields appear here: FlexPay License Key and Licensing Secret Key, both supplied to you at the time of purchase.
5. Licensing & Activation
FlexPay is a commercially licensed product, developed and sold by Editoria Cloud Systems. Every installation must be activated with a valid license before it will process any M-Pesa payments.
5.1 How Licensing Works
When you purchase FlexPay, you are issued a unique License Key and a Licensing Secret Key. Enter both into the gateway settings (Setup → Payments → Payment Gateways → M-Pesa via FlexPay):
Field	Description
FlexPay License Key	Your unique license key, issued at the time of purchase
Licensing Secret Key	A verification key supplied with your license, used to confirm responses from the licensing server are genuine

FlexPay automatically and periodically verifies your license against Editoria Cloud Systems' licensing server. You do not need to enter a server address — every copy of FlexPay checks in with 
Every copy of FlexPay checks in automatically with www.editoriaweb.co.ke — this is built into the module and is not a setting you need to configure.
5.2 What Happens If Your License Has a Problem
If your license is invalid, expired, suspended, or hasn't been entered yet, FlexPay responds safely and predictably:
•	New payments are blocked. Customers see a simple, generic message ("M-Pesa payment is temporarily unavailable") rather than any technical or licensing detail — your customers never see licensing error messages.
•	Payments already in progress are NOT interrupted. If a customer has already sent an M-Pesa payment and Safaricom is in the process of confirming it, that confirmation is still processed normally. FlexPay only blocks the start of new payments, never the completion of one already underway — this protects your customers from a payment succeeding on their end but failing to be recorded on yours.
•	Refunds are blocked until the license is valid again.
•	You are told exactly what's wrong, via a clear banner on the dashboard's Overview tab, a warning on the admin homepage widget, and full detail in the Tools tab — see Section 5.3.
5.3 Checking License Status
Open the FlexPay Dashboard and go to the Tools tab. The first card shows your current license status (Active, Invalid, Expired, Suspended, or Unconfigured) along with a clear explanation. If you've just renewed or fixed a licensing issue, click "Re-check License Now" to force an immediate fresh check rather than waiting for the next scheduled one.
5.4 Resilience — What If the Licensing Server Is Briefly Unreachable?
FlexPay caches a successful license check locally on your server. If Editoria Cloud Systems' licensing server is briefly unreachable — for example during a short maintenance window — your customers' M-Pesa payments are not interrupted. FlexPay uses the cached result for a few days, with a further short grace period beyond that, before requiring a successful fresh check. This means a brief network hiccup on either side will never suddenly stop your store from accepting M-Pesa payments.
6. The Customer Payment Experience
This section walks through exactly what your customers see and do when paying an invoice with FlexPay.
6.1 The Payment Widget
On any unpaid invoice where M-Pesa via FlexPay is available, customers see a payment box with:
•	The amount due, clearly displayed in KES.
•	A phone number field, pre-filled if WHMCS already has the customer's number on file.
•	A "Send M-Pesa Prompt" button.
•	A "Prefer to pay manually?" expandable section with Paybill or Till instructions, depending on your configuration.
•	An "Already paid? Verify your payment" link, for the rare case a payment doesn't reconcile by itself (see Section 6.4).
6.2 Paying via STK Push (the automatic prompt)
1.	The customer enters their phone number and clicks "Send M-Pesa Prompt".
2.	Within a few seconds, they receive a push notification on their phone from M-Pesa, asking them to enter their PIN to authorize the payment.
3.	The invoice page updates itself automatically and instantly the moment the payment is confirmed — the customer does not need to refresh or reload the page.
4.	If the customer cancels, takes too long, or the payment fails for any reason, the page shows a clear message explaining what happened and lets them try again.
Note:   The invoice page starts watching for a payment the moment it loads — even before the customer clicks "Send Prompt". This means if they decide to pay manually instead (Section 6.3), the page will still detect that payment automatically.
6.3 Paying Manually (Paybill or Till)
Customers who prefer to pay through the M-Pesa app or USSD menu themselves can do so without ever clicking the "Send Prompt" button. The exact steps shown depend on whether your business uses Paybill or Till:
For Paybill businesses:
1.	M-Pesa menu → Lipa Na M-Pesa → Pay Bill
2.	Business Number: your configured shortcode
3.	Account Number: the reference shown on the invoice page (e.g. INV-42)
4.	Amount: as shown on the invoice
For Till (Buy Goods) businesses:
1.	M-Pesa menu → Lipa na M-Pesa → Buy Goods and Services
2.	Till Number: your configured shortcode
3.	Amount: as shown on the invoice
In both cases, the invoice page detects the payment automatically and updates itself within moments of Safaricom confirming it — see Section 9 for exactly how FlexPay matches these payments back to the correct invoice.
6.4 Customer Self-Verification
Occasionally — most commonly with a brief network issue, or a Till payment that needs a moment longer to reconcile — a payment may not show as confirmed right away. Rather than requiring the customer to contact support, FlexPay gives them a way to check it themselves:
1.	The customer clicks "Already paid? Verify your payment" on the invoice page.
2.	They enter the M-Pesa receipt number from their confirmation SMS (e.g. NLJ7RT61SV).
3.	FlexPay checks its own records first, and if the payment is found and genuinely belongs to that invoice, it's applied immediately.
4.	If nothing is found locally, FlexPay checks directly with Safaricom and tells the customer to check back shortly.
Security note:   This feature is carefully scoped so a customer can only ever verify a payment against the invoice they are currently viewing — it cannot be used to look up or apply payments belonging to a different customer's invoice, and it is rate-limited to prevent misuse.
7. The FlexPay Dashboard
The dashboard is your day-to-day management console for everything FlexPay handles. Access it via Addons → FlexPay Dashboard in your WHMCS admin menu.
7.1 Dashboard Settings
Configured via Setup → Addon Modules → FlexPay Dashboard → Configure:
•	Restrict Access To — comma-separated WHMCS admin role names allowed to view the dashboard. Defaults to "Full Administrator"; change this only if you have custom admin roles and understand the implications of giving other roles access to payment data.
•	Default Stats Lookback (days) — how many days of activity the Overview tab summarizes by default. 30 is a sensible default for most businesses.
7.2 Overview Tab
The first thing you see when opening the dashboard. At a glance:
•	Whether you're in Sandbox or Live mode.
•	A license status warning, if there's a problem (see Section 5.3).
•	Six summary cards: total received, total refunded, successful transactions, failed transactions, pending transactions, and unmatched C2B payments needing attention.
•	Your most recent M-Pesa account balance, if you've requested one (Section 7.6).
•	A direct link into the Reconciliation tab if anything needs your attention.
Live updates:   The figures on this tab refresh automatically roughly every 8 seconds, without needing to reload the page — a small pulsing green dot near the top right confirms this is active and shows when data was last refreshed.
7.3 Transactions Tab
A complete, searchable, filterable ledger of every M-Pesa transaction FlexPay has ever processed or recorded — STK Push, manual Paybill/Till, refunds, and reversals all appear here.
•	Search — by receipt number, phone number, or account reference.
•	Filter by Channel — STK Push, C2B (manual), B2C (refund), or Reversal.
•	Filter by Status — Success, Failed, Pending, or Reversed.
•	The Outcome column — shows at a glance whether a payment was an exact match, a partial payment with a balance still due, an overpayment that was credited to the customer's account, or a possible partial payment awaiting reconciliation. Explained fully in Section 8.
•	Invoice links — click straight through to the linked invoice where one exists.
A banner appears at the top of this tab if new transactions have arrived since you opened the page — click Refresh to bring them into view, rather than the table silently reordering itself while you might be in the middle of reviewing something.
7.4 Refunds Tab
Tracks every B2C disbursement triggered by an admin issuing a refund from Billing → Invoices → Refund on an invoice that was paid via FlexPay. Each entry shows the invoice, the customer's phone number, the amount, the original receipt being refunded, its current status, and who initiated it.
7.5 Reconciliation Tab
This is where any manual payment that couldn't be automatically matched to an invoice ends up — most commonly a Till payment whose amount didn't clearly correspond to exactly one open invoice, or a Paybill payment with a badly mistyped reference.
For each unmatched item you'll see the receipt number, the customer's phone and name (where available), the amount, and a "Match to Invoice" form. Where FlexPay can identify a single likely candidate invoice (see Section 9.4), the invoice number field is pre-filled to save you typing — but nothing is ever applied automatically here; you always click Apply yourself to confirm the match.
Tip:   A small highlighted note appears under each unmatched entry whenever FlexPay has identified a plausible candidate, e.g. "Possible partial payment toward: #42 (balance KES 1,200.00)" — this is the same intelligence engine described in Section 9, just surfaced for a human to confirm rather than acted on automatically.
7.6 Balance Tab
Shows your most recent M-Pesa account balance (Working Account and Utility Account) and a historical trend. Click "Request Fresh Balance from Daraja" to ask Safaricom for an up-to-date figure — this requires your B2C Initiator credentials to be configured (Section 4.6) and the result typically arrives within 10–30 seconds; refresh the tab to see it.
7.7 Tools Tab
A collection of on-demand actions:
•	FlexPay License — current license status and a "Re-check License Now" button (Section 5.3).
•	Verify a Payment — the administrator's version of customer self-verification (Section 6.4). Enter any receipt number, checkout ID, or reference; optionally provide an invoice number to apply the payment immediately if it's found unlinked.
•	Reset Customer Verify Limit — if a genuine customer gets rate-limited on the invoice page's self-verify box after several real retries (e.g. repeated typos), clear their limit here using the invoice number.
•	Transaction Status Query — ask Safaricom directly for the live status of any receipt number.
•	Transaction Reversal — reverse a completed transaction. Use this carefully — it moves real money back to the customer and cannot be undone.
•	C2B URL Registration — shows when your manual-payment callback URLs were last successfully registered with Safaricom, with a button to force an immediate re-registration (normally never needed, since this happens automatically).
•	Simulate C2B Payment — Sandbox mode only. Lets you test the entire manual-payment reconciliation flow without a real phone or real money.
7.8 API Log Tab
A complete audit trail of every API call FlexPay has made to Safaricom's Daraja API, whether triggered automatically (an STK push, an automatic C2B re-registration) or manually from the Tools tab — including who triggered it, when, and whether it succeeded. Useful for diagnosing exactly what happened around a specific payment or a specific point in time.
7.9 The Admin Homepage Widget
A compact summary of the last 7 days appears automatically on your WHMCS admin homepage once the dashboard addon is active — received, refunded, successful, and pending totals, a warning if anything needs reconciling, a license warning if relevant, and a direct link into the full dashboard.
8. Partial Payments & Overpayment Credit
FlexPay handles three possible outcomes every time a payment is applied to an invoice: an exact payment, a partial (under) payment, and an overpayment. This section explains what each one means and what happens automatically in each case.
8.1 Exact Payment
The customer pays exactly what's owed. The invoice is marked Paid, and that's the end of it — this is the outcome for the large majority of payments.
8.2 Partial Payment (Underpayment)
If a customer pays less than the full amount owed — for example, paying KES 400 toward a KES 1,000 invoice — WHMCS reduces the invoice balance to KES 600 and the invoice remains in Unpaid (or Overdue) status. This is standard WHMCS behavior; there is no separate "Partially Paid" status in WHMCS, just a reduced balance on an invoice that's still open.
FlexPay makes this clearly visible wherever it matters:
•	The customer immediately sees a message such as "KES 400.00 received and applied. KES 600.00 still due on this invoice."
•	The Transactions tab in the dashboard shows a PARTIAL — BALANCE DUE badge on that transaction.
Important:   A partial payment does not trigger WHMCS's automatic "invoice paid" actions (such as activating or renewing a service) — those wait until the invoice is fully paid. This is intentional and matches standard WHMCS behavior; it means a partially-paid invoice still needs the remaining balance collected before the related service is automatically activated or renewed.
8.3 Overpayment
If a customer pays more than what's owed, WHMCS automatically creates a credit note for the difference and adds that amount to the customer's account credit balance — available automatically toward their next invoice. This is entirely native, standard WHMCS behavior; FlexPay does not need to do anything extra to make this happen, but it does make sure everyone involved can see clearly that it happened:
•	The customer sees a message such as "KES 1,200.00 received. KES 1,000.00 applied to this invoice and KES 200.00 credited to your account balance for future use."
•	The Transactions tab shows an OVERPAID — CREDITED badge.
You can see a customer's resulting account credit under their client profile in WHMCS, exactly as you would for a credit applied through any other payment method.
8.4 Partial Payments via Till — A Special Case
Till (Buy Goods) payments don't carry any reference number — the customer never enters one — so a partial Till payment cannot be automatically applied the way a partial Paybill payment can, because there's no reliable way to know which invoice it's meant for. See Section 9.4 for exactly how FlexPay handles this safely.
9. How Intelligent Till & Paybill Matching Works
This section explains, in plain terms, exactly how FlexPay decides which invoice a manual M-Pesa payment belongs to — and, just as importantly, when it deliberately refuses to guess.
9.1 Why Paybill and Till Need Different Approaches
When a customer pays via Paybill, they type in an account number — this is where the invoice reference (e.g. INV-42) comes from, and FlexPay uses it directly to know which invoice to apply the payment to.
When a customer pays via Till (Buy Goods and Services), there is no account number step at all — Safaricom's payment flow for Till simply doesn't ask for one. This means Till payments arrive with no reference whatsoever, and FlexPay has to use a different strategy entirely: matching by amount.
9.2 Paybill Matching — Reference Parsing
FlexPay reads whatever the customer typed as their account number and looks for any run of digits within it, tolerating common real-world typing variations. All of the following are understood as invoice #42:
What the customer typed	Understood as
INV-42	Invoice #42
INV42	Invoice #42
inv 42	Invoice #42
42	Invoice #42
0000042	Invoice #42
INV-42-A	Invoice #42

If the resulting invoice number doesn't correspond to a real, currently open invoice, or no digits could be found at all, the payment is queued for manual reconciliation (Section 7.5) rather than guessed at.
9.3 Lenient vs Strict Validation Mode
This only affects Paybill payments, set via the "C2B Validation Mode" gateway setting (Section 4.5):
•	Lenient (recommended, default): every payment is accepted by Safaricom immediately, no matter what reference was entered. Matching happens afterward; anything that can't be matched goes to Reconciliation. This means a customer's payment is never rejected outright over a typo.
•	Strict: Safaricom is told to reject the payment immediately if the reference doesn't correspond to an open invoice — the customer sees this rejection on their phone right away and can correct it before the money ever moves. Till payments are never rejected under strict mode (see below), since they never have a reference to check in the first place.
9.4 Till Matching — Matching by Amount
Since a Till payment carries no reference, FlexPay instead looks for currently open (Unpaid or Overdue) invoices whose total exactly matches the amount paid:
•	Exactly one invoice matches: applied automatically, immediately, with no manual step needed.
•	More than one invoice shares that exact amount: FlexPay uses the time the payment was made to prefer whichever invoice's due date is closest — most likely the bill the customer is actually settling right now.
•	Still genuinely tied, even after that: FlexPay does not guess. The payment is queued for manual reconciliation instead, exactly as designed — applying it to the wrong one of two equally-likely invoices would be a real mistake with real consequences, so FlexPay treats "can't tell for certain" as a reason to ask a human, not a reason to pick one.
9.5 Detecting Likely Partial Till Payments
A partial payment is fundamentally harder to match safely than a full one: a KES 400 payment could plausibly be a partial payment toward almost any open invoice with a balance of KES 400 or more — there's no natural "this can only be the one" signal the way an exact amount match provides. For this reason, FlexPay never auto-applies a partial Till payment to any invoice.
What it does instead is genuinely useful: it looks for open invoices whose remaining balance is larger than the amount paid, and if it finds any, it adds a clear note to the Reconciliation entry — for example, "Possible partial payment toward: #42 (balance KES 1,200.00)" — and even pre-fills the invoice number for you if there's exactly one such candidate. You still have to click Apply yourself; FlexPay simply saves you the investigation.
9.6 Summary
Scenario	What happens
Paybill, valid reference, exact amount	Applied automatically
Paybill, mistyped but readable reference	Applied automatically (typo-tolerant)
Paybill, unreadable/invalid reference	Queued for manual reconciliation
Till, exact amount, one matching invoice	Applied automatically
Till, exact amount, multiple matches, timing clearly favors one	Applied automatically
Till, exact amount, genuinely tied	Queued for manual reconciliation
Till or Paybill, partial amount	Never auto-applied — queued with a helpful hint where possible
10. Troubleshooting
"M-Pesa payment is temporarily unavailable" shown to customers
This means your FlexPay license is invalid, expired, suspended, or hasn't been entered. Go to the Dashboard → Tools tab and check the License Status card for the exact reason, then click "Re-check License Now" once resolved.
STK Push prompt never arrives on the customer's phone
•	Confirm Consumer Key, Consumer Secret, Business Shortcode, and Passkey are all entered correctly and match your Daraja app.
•	Confirm you're using the correct credentials for the mode you're in — Sandbox credentials will not work when Sandbox Mode is switched off, and vice versa.
•	Check the API Log tab in the dashboard for the actual error Safaricom returned.
Customer says they paid, but the invoice is still unpaid
1.	Have the customer use "Already paid? Verify your payment" directly on the invoice page (Section 6.4) — this resolves the large majority of these cases instantly.
2.	If that doesn't work, use "Verify a Payment" in the dashboard's Tools tab with their M-Pesa receipt number.
3.	Check the Reconciliation tab — the payment may be sitting there waiting for a one-click manual match, possibly with a helpful hint already attached.
4.	Check the API Log tab for any failed callback or API call around the time of the payment.
Manual Paybill/Till payments aren't being detected at all
•	Confirm "Auto-Register C2B URLs" is switched on, and check the Tools tab for when registration last succeeded.
•	Confirm your WHMCS is reachable over HTTPS from the public internet — Safaricom cannot deliver payment notifications to a server it cannot reach.
•	Try "Force Re-Register Now" in the Tools tab after confirming the above.
Balance or Reversal tools show an error
These features require the B2C Initiator Name and B2C Security Credential fields to be filled in correctly (Section 4.6) — they are separate from your main Daraja Consumer Key/Secret and are generated specifically for B2C-type operations on the Daraja portal.
Refund failed
•	Confirm your FlexPay license is currently valid — refunds are blocked while it isn't.
•	Confirm the B2C settings above are correctly configured.
•	Check the customer's phone number on file is a valid, correctly-formatted Safaricom number.
The dashboard shows a permission or access error
Check Setup → Addon Modules → FlexPay Dashboard → Configure, and confirm the admin role you're using is listed under "Restrict Access To".
11. Frequently Asked Questions
Do I need separate Daraja credentials for Sandbox and Live?
Yes. Safaricom issues separate credentials for the Sandbox (testing) and Production (live) environments. Make sure the credentials entered match whichever mode the "Sandbox / Test Mode" toggle is set to.
Can I use both a Paybill and a Till number?
The STK Transaction Type setting (Section 4.3) determines which one FlexPay is configured for at any given time. If you need both, please contact Editoria Cloud Systems to discuss your specific setup.
What happens if a customer closes the invoice page right after sending the STK prompt?
The payment still completes normally on Safaricom's side regardless of whether the customer keeps the page open. If they return to the invoice afterward, FlexPay will detect the completed payment and update the invoice automatically — there's no need for the page to have stayed open.
Does FlexPay support recurring or subscription billing?
FlexPay processes individual invoice payments. WHMCS's own recurring billing creates new invoices on schedule as normal; each one is paid through FlexPay the same way any other invoice is.
Is my customers' M-Pesa data secure?
FlexPay never stores M-Pesa PINs — these are entered directly on the customer's phone and never pass through your server at all. Receipt numbers, phone numbers, and amounts are stored in your own WHMCS database, the same way any other payment gateway's transaction records are.
Can a customer accidentally see another customer's payment information?
No. The customer self-verification feature (Section 6.4) is deliberately restricted so it can only ever check or apply a payment against the specific invoice the customer is currently viewing — see the security note in that section for details.
What currency does FlexPay use?
Kenyan Shillings (KES) throughout, matching Safaricom's M-Pesa.
Can I customize the wording shown to customers?
The current release uses carefully considered, tested wording throughout. If you need specific customization for your business, please contact Editoria Cloud Systems.
How do I renew my license?
Contact Editoria Cloud Systems through the details in Section 12. Once renewed, click "Re-check License Now" in the dashboard's Tools tab to confirm the update immediately rather than waiting for the next scheduled check.
12. Support & Contact
FlexPay is developed and supported by Editoria Cloud Systems.
Website: https://www.editoriaweb.co.ke
Before contacting support, it's helpful to have the following ready:
•	Your FlexPay version number (shown at the top of this guide, and in the gateway settings page).
•	Whether you're in Sandbox or Live mode.
•	The relevant entry from the dashboard's API Log tab, if your question relates to a specific transaction.
•	Your license status, shown in the Tools tab.

Thank you for choosing FlexPay.

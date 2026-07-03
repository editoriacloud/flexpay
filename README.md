FlexPay for WHMCS
Complete M-Pesa Payment Gateway for WHMCS
FlexPay is a complete M-Pesa payment gateway for WHMCS, built on Safaricom's Daraja API. It lets your customers pay invoices instantly using M-Pesa — either through an automatic STK Push prompt sent directly to their phone, or by paying manually through the M-Pesa menu (Paybill or Buy Goods/Till) — and it reconciles those payments back to the correct invoice automatically, in most cases without any manual work at all.

This guide covers everything from first installation through to day-to-day use of the management dashboard, troubleshooting, and frequently asked questions. It is written for WHMCS administrators — no programming knowledge is required to use FlexPay, though a few sections (marked clearly) go into technical depth for administrators who want to understand exactly how something works.

📋 Table of Contents
1.1 What FlexPay Does

1.2 How Payments Flow Through FlexPay

1.1 What FlexPay Does
STK Push checkout — sends an M-Pesa payment prompt straight to the customer's phone from the invoice page. They enter their PIN and the invoice is marked paid automatically, usually within a few seconds.

Manual Paybill and Till (Buy Goods) support — customers who prefer to pay through the M-Pesa menu themselves are fully supported too, with intelligent automatic matching back to the correct invoice.

Customer self-verification — if a payment doesn't reconcile automatically for any reason, customers can verify it themselves directly on the invoice page using their M-Pesa receipt number.

Refunds — when you issue a refund from WHMCS on an invoice paid via FlexPay, it automatically sends the money back to the customer's M-Pesa number.

A full management dashboard — a dedicated area inside WHMCS showing every transaction, refund, and reconciliation item, with live-updating figures and one-click tools.

Automatic partial-payment and overpayment handling — underpayments are clearly flagged with the remaining balance; overpayments are automatically credited to the customer's account by WHMCS, with FlexPay describing exactly what happened.

Commercial licensing — FlexPay is a licensed product. Each installation requires a valid license key, issued by Editoria Cloud Systems, described in full in Section 5.

1.2 How Payments Flow Through FlexPay
At a high level, every payment FlexPay handles follows one of two paths:

1. STK Push
The customer clicks "Send M-Pesa Prompt" on the invoice page, enters their phone number, and receives a push notification on their phone. They enter their M-Pesa PIN, Safaricom processes the payment, and FlexPay marks the invoice paid — typically within 5–15 seconds.

2. Manual Payment
The customer opens the M-Pesa app or dials the M-Pesa menu themselves, and pays via:

Paybill — entering the invoice number as the account reference

Buy Goods/Till — no reference needed

Safaricom notifies FlexPay the moment the payment completes, and FlexPay matches it back to the right invoice — by reference number for Paybill, or by intelligent amount-matching for Till.

📄 License
FlexPay is a commercial product licensed by Editoria Cloud Systems. Each installation requires a valid license key.

Note: This documentation is intended for WHMCS administrators. Some sections may contain technical details for those who want to understand the inner workings of the gateway.

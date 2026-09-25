<?php
/**
 * FlexPayWhmcsIntegration — logic behind FlexPay's WHMCS hooks (hooks.php):
 *
 *   EmailPreSend / EmailTplMergeFields
 *       M-Pesa payment instructions as merge fields in invoice emails, so
 *       customers see the exact paybill number and account reference
 *       ("INV-42") before they ever open the invoice — the single biggest
 *       cause of unmatched payments is a customer guessing the reference.
 *
 *   AdminInvoicesControlsOutput
 *       An M-Pesa panel on the admin invoice page: FlexPay payments for the
 *       invoice, unmatched payments suggested for it, and a button to send
 *       the client an STK prompt for the balance.
 *
 * Kept out of hooks.php so it can be unit tested.
 *
 * @package FlexPay\Dashboard
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

class FlexPayWhmcsIntegration
{
    public const MERGE_FIELDS = [
        'flexpay_paybill'      => 'M-Pesa Paybill / Till number',
        'flexpay_account'      => 'M-Pesa account number to use (e.g. INV-42)',
        'flexpay_amount_kes'   => 'Amount due in KES (whole shillings)',
        'flexpay_instructions' => 'Complete M-Pesa payment instructions (one line)',
    ];

    /**
     * EmailPreSend: add FlexPay merge fields to any email about an invoice.
     *
     * @param array $vars  messagename, relid, mergefields
     * @return array  merge fields to add (empty when not applicable)
     */
    public static function emailMergeFields(array $vars): array
    {
        $invoiceId = (int) ($vars['mergefields']['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            return [];
        }

        $gw = FlexPayStore::getFlexPayGatewayParams();
        if (empty($gw['type'])) {
            return [];
        }

        $invoice = FlexPayStore::getInvoice($invoiceId);
        if (!$invoice) {
            return [];
        }

        $isTill  = ($gw['transactionType'] ?? '') === 'CustomerBuyGoodsOnline';
        $number  = $isTill ? DarajaClient::stkPartyB($gw) : DarajaClient::c2bShortcode($gw);
        $account = FlexPayService::accountReference($gw, $invoiceId);
        $amount  = FlexPayStore::invoiceBalanceInKes($invoiceId);
        $open    = in_array($invoice->status, FlexPayStore::OPEN_INVOICE_STATUSES, true);

        if ($number === '') {
            return [];
        }

        $amountText = ($amount !== null && $amount > 0) ? 'KES ' . number_format($amount) : '';

        if (!$open || $amountText === '') {
            $instructions = '';
        } elseif ($isTill) {
            // Till payments cannot carry a reference; point customers at the
            // invoice page's STK prompt so the payment matches automatically.
            $instructions = 'Pay ' . $amountText . ' with M-Pesa from your invoice page (tap "Send M-Pesa Prompt") so it is matched automatically. '
                . 'Paying to Till ' . $number . ' from the M-Pesa menu is also accepted but is confirmed manually.';
        } else {
            $instructions = 'Pay with M-Pesa: Lipa na M-Pesa → Pay Bill → Business No. ' . $number
                . ' → Account No. ' . $account . ' → Amount ' . $amountText
                . '. Use the account number exactly as shown so your payment is applied automatically.';
        }

        // Merge values end up inside HTML emails: escape them.
        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        return [
            'flexpay_paybill'      => $e($number),
            'flexpay_account'      => $e($account),
            'flexpay_amount_kes'   => $e($amountText),
            'flexpay_instructions' => $e($instructions),
        ];
    }

    /** EmailTplMergeFields: list FlexPay's fields in the invoice template editor. */
    public static function emailTemplateFields(array $vars): array
    {
        return (($vars['type'] ?? '') === 'invoice') ? self::MERGE_FIELDS : [];
    }

    /**
     * AdminInvoicesControlsOutput: the M-Pesa panel on the admin invoice page.
     *
     * @param array $vars  invoiceid, userid, total, balance, paymentmethod, ...
     */
    public static function adminInvoicePanel(array $vars): string
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId <= 0) {
            return '';
        }

        $transactions = FlexPayStore::listTransactions(['invoice_id' => $invoiceId], 1, 10)['data'];
        $suggested    = FlexPayStore::listSuggestedForInvoice($invoiceId);
        $isFlexPay    = ($vars['paymentmethod'] ?? '') === 'flexpay';

        if (!$isFlexPay && empty($transactions) && empty($suggested)) {
            return '';
        }

        $e = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };
        $addon = 'addonmodules.php?module=flexpay_dashboard';

        $html = '<div class="panel panel-default" style="margin-top:15px;" id="flexpay-invoice-panel">'
            . '<div class="panel-heading"><strong>M-Pesa (FlexPay)</strong></div><div class="panel-body" style="font-size:13px;">';

        if (!empty($transactions)) {
            $html .= '<table class="table table-condensed" style="margin-bottom:10px;"><thead><tr><th>Date</th><th>Channel</th><th>Receipt</th><th>Amount</th><th>Status</th><th>Details</th></tr></thead><tbody>';
            foreach ($transactions as $t) {
                $html .= '<tr><td>' . $e($t->created_at) . '</td><td>' . $e(strtoupper($t->channel)) . '</td>'
                    . '<td>' . $e($t->mpesa_receipt ?: $t->checkout_request_id ?: '—') . '</td>'
                    . '<td>KES ' . number_format((float) $t->amount, 2) . '</td>'
                    . '<td>' . $e(strtoupper($t->status)) . '</td><td>' . $e($t->result_desc) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        if (!empty($suggested)) {
            $html .= '<div class="alert alert-warning" style="padding:8px 12px;">&#9888; ' . count($suggested)
                . ' unmatched M-Pesa payment(s) may belong to this invoice (not applied automatically because the account reference did not match): ';
            $parts = [];
            foreach ($suggested as $u) {
                $parts[] = $e($u->trans_id) . ' — KES ' . number_format((float) $u->amount, 2);
            }
            $html .= implode('; ', $parts) . '. <a href="' . $addon . '&amp;fp_tab=reconciliation">Review in Reconciliation &rarr;</a></div>';
        }

        $invoice = FlexPayStore::getInvoice($invoiceId);
        if ($invoice && in_array($invoice->status, FlexPayStore::OPEN_INVOICE_STATUSES, true)) {
            $phone = '';
            try {
                $phone = (string) \WHMCS\Database\Capsule::table('tblclients')->where('id', (int) $invoice->userid)->value('phonenumber');
            } catch (\Throwable $ex) {
                $phone = '';
            }
            $html .= '<form method="post" action="' . $addon . '&amp;fp_tab=tools" class="form-inline" onsubmit="return confirm(\'Send an M-Pesa payment prompt for this invoice to the phone number entered?\');">'
                . FlexPaySecurity::csrfField()
                . '<input type="hidden" name="fp_action" value="admin_stk">'
                . '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">'
                . '<input type="tel" name="phone" class="form-control input-sm" style="width:160px;" value="' . $e(DarajaClient::toDisplayPhone(DarajaClient::formatPhone($phone))) . '" placeholder="07XXXXXXXX" required> '
                . '<button type="submit" class="btn btn-success btn-sm">Send M-Pesa Prompt</button>'
                . ' <span class="text-muted">Account ref: <code>' . $e(FlexPayService::accountReference(FlexPayStore::getFlexPayGatewayParams(), $invoiceId)) . '</code></span>'
                . '</form>';
        }

        return $html . '</div></div>';
    }
}

<?php

namespace App\Services\Email;

use App\Models\EmailTemplate;
use App\Services\Settings\SettingsService;

/**
 * Turns an email template (plain text with {placeholders}) into branded HTML
 * and a plain-text version.
 *
 * Template syntax:
 *   - Paragraphs are separated by a blank line. The first paragraph is the greeting/heading.
 *   - {first_name} etc. are replaced with values (HTML-escaped).
 *   - {button:Label|{link_placeholder}} becomes a button.
 *   - {fee_table} and {receipt_details} become small tables.
 */
final class EmailRenderer
{
    /** Placeholders shown in the template editor. */
    public const PLACEHOLDERS = [
        'first_name' => 'Client’s first name',
        'company_name' => 'Brokerage name',
        'deposit_amount' => 'Deposit amount',
        'deposit_status' => '“paid” or “being processed by your bank”',
        'balance_amount' => 'Balance due at go-live',
        'monthly_amount' => 'Current monthly fee (before any yearly discount)',
        'subscription_amount' => 'What each subscription charge is, e.g. “$660.00 a month” or “$7,128.00 a year”',
        'agent_count' => 'Agents billed',
        'go_live_date' => 'Go-live date',
        'first_monthly_date' => 'First monthly charge date',
        'term_end_date' => 'End of the minimum term',
        'payment_method' => 'e.g. Visa ending 4242',
        'amount' => 'Amount of the invoice this email is about',
        'period' => 'Billing period, e.g. November 2026',
        'agreement_number' => 'Agreement number',
        'agreement_link' => 'Link to view and download the agreement (use in a button)',
        'payment_link' => 'Link to pay or update the payment method (use in a button)',
        'renewal_link' => 'Link to the renewal agreement (use in a button)',
        'account_link' => 'Link to the client’s account page, valid for the client link period (use in a button)',
        'card_expiry' => 'Expiry of the saved card, e.g. 10/2026',
        'old_agent_count' => 'Previous number of agents billed',
        'effective_date' => 'When a change takes effect',
        'signer_name' => 'Person asked to sign',
        'requested_by' => 'Person who asked them to sign',
        'signing_link' => 'Link to review and sign (use in a button)',
        'refund_amount' => 'Amount refunded',
        'refund_reason' => 'Reason given for the refund',
        'credit_amount' => 'Credit amount',
        'credit_reason' => 'Reason given for the credit',
        'pause_until' => 'Date the pause ends',
        'first_charge_date' => 'Existing clients: first subscription charge date',
        'service_end_date' => 'Date the service ends',
        'end_charges' => 'What’s still charged when service ends',
        'book_call_link' => 'Link to book a call',
        'onboarding_link' => 'Link to start onboarding, with the prospect’s coupon (calls)',
        'password_link' => 'Link to create or reset the client’s account password (use in a button)',
        'link_days' => 'How many days client links stay valid (Settings > Security)',
        'resume_link' => 'Link to continue onboarding where the client stopped (use in a button)',
        'next_step' => 'What the client does next, e.g. “review and sign your agreement”',
        'login_code' => 'Admin sign-in code',
        'code_minutes' => 'Minutes the sign-in code is valid',
        'alert_title' => 'Team alert title',
        'alert_text' => 'Team alert details',
        'alert_link' => 'Link to the admin page for the alert',
        'fee_table' => 'Table of today, go-live and monthly amounts',
        'receipt_details' => 'Table with receipt number, date, amount and method',
        'invoice_number' => 'Invoice number (chargeback alert)',
        'dispute_reason' => 'Reason the client gave their bank (chargeback alert)',
        'respond_by' => 'Deadline to respond to a chargeback',
        'stripe_link' => 'Link to the dispute in Stripe (chargeback alert)',
        'customer_link' => 'Link to the customer in admin (chargeback alert)',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @param  array<string, string|array<int, array{0: string, 1: string}>>  $values  table placeholders take [label, value] rows
     * @return array{subject: string, html: string, text: string}
     */
    public function render(EmailTemplate $template, array $values): array
    {
        $subject = $this->plain($template->subject, $values);
        $paragraphs = preg_split('/\R{2,}/', trim($template->body)) ?: [];

        $htmlParas = [];
        $textParas = [];
        foreach ($paragraphs as $i => $para) {
            $htmlParas[] = $this->paragraphHtml($para, $values, $i === 0);
            $textParas[] = $this->paragraphText($para, $values);
        }

        $html = view('emails.layout', [
            'subject' => $subject,
            'content' => implode("\n", $htmlParas),
            'company' => $this->settings->group('company'),
        ])->render();

        return ['subject' => $subject, 'html' => $html, 'text' => implode("\n\n", array_filter($textParas))];
    }

    private function paragraphHtml(string $para, array $values, bool $first): string
    {
        $trimmed = trim($para);
        if (preg_match('/^\{(fee_table|receipt_details)\}$/', $trimmed, $m)) {
            return $this->tableHtml((array) ($values[$m[1]] ?? []));
        }
        if (preg_match('/^\{button:([^|]+)\|\{([a-z_]+)\}\}$/', $trimmed, $m)) {
            $url = (string) ($values[$m[2]] ?? '');

            return $url === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0"><tr><td style="border-radius:8px;background:#1457EC">'
                .'<a href="'.e($url).'" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-weight:600;font-size:15px">'.e($m[1]).'</a></td></tr></table>';
        }

        $text = nl2br($this->plain($trimmed, $values, escape: true));

        return $first
            ? '<p style="margin:0 0 16px;font-size:20px;font-weight:600;color:#041527">'.$text.'</p>'
            : '<p style="margin:0 0 16px">'.$text.'</p>';
    }

    private function paragraphText(string $para, array $values): string
    {
        $trimmed = trim($para);
        if (preg_match('/^\{(fee_table|receipt_details)\}$/', $trimmed, $m)) {
            return implode("\n", array_map(fn ($r) => $r[0].': '.$r[1], (array) ($values[$m[1]] ?? [])));
        }
        if (preg_match('/^\{button:([^|]+)\|\{([a-z_]+)\}\}$/', $trimmed, $m)) {
            $url = (string) ($values[$m[2]] ?? '');

            return $url === '' ? '' : $m[1].': '.$url;
        }

        return $this->plain($trimmed, $values);
    }

    /** Replaces {placeholders}; table and button placeholders are removed inline. */
    private function plain(string $text, array $values, bool $escape = false): string
    {
        $text = $escape ? e($text) : $text;

        return (string) preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, $escape) {
            $v = $values[$m[1]] ?? null;
            if (is_array($v) || $v === null) {
                return '';
            }

            return $escape ? e((string) $v) : (string) $v;
        }, $text);
    }

    /** @param array<int, array{0: string, 1: string}> $rows */
    private function tableHtml(array $rows): string
    {
        if (! $rows) {
            return '';
        }
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #DFE5EF;border-radius:8px;border-collapse:separate;margin:8px 0 20px;font-size:14px">';
        $last = count($rows) - 1;
        foreach (array_values($rows) as $i => [$label, $value]) {
            $border = $i < $last ? 'border-bottom:1px solid #DFE5EF;' : '';
            $html .= '<tr><td style="padding:10px 14px;'.$border.'">'.e($label).'</td><td style="padding:10px 14px;'.$border.'text-align:right;white-space:nowrap"><b>'.e($value).'</b></td></tr>';
        }

        return $html.'</table>';
    }
}

<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

/**
 * The 16 automatic emails. Wording is editable in the admin (Sprint 3);
 * this seeder never overwrites an edited template.
 */
class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $key => [$name, $when, $cc, $subject, $body]) {
            EmailTemplate::firstOrCreate(['key' => $key], [
                'is_system' => true,
                'name' => $name,
                'trigger_description' => $when,
                'cc_team' => $cc,
                'subject' => $subject,
                'body' => $body,
                'is_enabled' => true,
            ]);
        }
    }

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: string, 4: string}> */
    public static function templates(): array
    {
        $sign = "\n\nSrini\nCo-Founder, RightAlly";

        return [
            'agreement_signed' => ['Agreement signed (welcome)', 'The deposit is paid by card, or a bank payment for it starts', true, 'Your RightAlly agreement is signed',
                "Welcome aboard, {first_name}\n\nThanks for choosing RightAlly for {company_name}. Your agreement is signed and your deposit of {deposit_amount} is {deposit_status}. The signed agreement is attached to this email.\n\n{fee_table}\n\nYour implementation lead will contact you within 2 business days to schedule a kick-off call.\n\n{button:Download your agreement|{agreement_link}}".$sign],
            'deposit_receipt' => ['Deposit receipt', 'A bank payment for the deposit clears', true, 'Receipt for your RightAlly deposit',
                "Hi {first_name},\n\nWe received your deposit of {deposit_amount} for {company_name}. The remaining {balance_amount} is charged on your go-live date, {go_live_date}.\n\n{receipt_details}".$sign],
            'go_live_changed' => ['Go-live date changed', 'An admin moves the go-live date', true, 'Your RightAlly go-live date is now {go_live_date}',
                "Hi {first_name},\n\nYour go-live date has moved to {go_live_date}. The remaining implementation fee of {balance_amount} will be charged on that date instead.".$sign],
            'balance_reminder' => ['Balance reminder', '3 days before the go-live charge', false, 'You go live on {go_live_date}',
                "Hi {first_name},\n\nYou go live on {go_live_date}. On that day we’ll charge {balance_amount} to {payment_method}. No action is needed.".$sign],
            'balance_paid' => ['Balance paid', 'The go-live charge succeeds', true, 'You’re live on RightAlly',
                "Hi {first_name},\n\n{company_name} is live. We received {balance_amount}. Your first monthly charge of {monthly_amount} is on {first_monthly_date}.".$sign],
            'balance_failed' => ['Balance failed', 'The go-live charge fails', true, 'Action needed: your go-live payment didn’t go through',
                "Hi {first_name},\n\nWe couldn’t charge {balance_amount} to {payment_method}. Please update your payment method and pay using the button below.\n\n{button:Update payment method|{payment_link}}".$sign],
            'monthly_receipt' => ['Monthly receipt', 'A monthly charge succeeds', false, 'Your RightAlly receipt for {period}',
                "Hi {first_name},\n\nWe received {amount} for {period} ({agent_count} agents).\n\n{receipt_details}".$sign],
            'payment_failed' => ['Payment failed', 'A monthly charge fails', true, 'Action needed: your RightAlly payment didn’t go through',
                "Hi {first_name},\n\nWe couldn’t charge {amount} for {period}. Please update your payment method and pay using the button below. Accounts unpaid for 30 days are suspended.\n\n{button:Update payment method|{payment_link}}".$sign],
            'account_suspended' => ['Account suspended', 'A payment is 30 days overdue', true, 'Your RightAlly account is suspended',
                "Hi {first_name},\n\nYour account is suspended because {amount} has been unpaid for 30 days. Pay now to restore access.\n\n{button:Pay now|{payment_link}}".$sign],
            'renewal_offer' => ['Renewal offer', '45 days before the term ends', true, 'Renew your RightAlly agreement',
                "Hi {first_name},\n\nYour agreement ends on {term_end_date}. Review and sign your renewal to keep {company_name} running without interruption.\n\n{button:Review renewal|{renewal_link}}".$sign],
            'renewal_reminder' => ['Renewal reminder', '15 days before the term ends', true, 'Reminder: your RightAlly agreement ends on {term_end_date}',
                "Hi {first_name},\n\nYour agreement ends in 15 days. If the renewal isn’t signed by {term_end_date}, your subscription will end.\n\n{button:Review renewal|{renewal_link}}".$sign],
            'agreement_expired' => ['Agreement expired', 'The term ends without renewal', true, 'Your RightAlly agreement has ended',
                "Hi {first_name},\n\nYour agreement ended on {term_end_date} and your subscription has been cancelled. Reply to this email if you’d like to continue.".$sign],
            'early_termination' => ['Early termination invoice', 'An admin records early termination', true, 'Your RightAlly early termination invoice',
                "Hi {first_name},\n\nAs your agreement ended before its 12-month minimum term, {amount} is due for the remaining months, as set out in Section 7 of your agreement.\n\n{button:View invoice|{payment_link}}".$sign],
            'admin_invite' => ['Admin invite', 'An admin is invited', false, 'You’re invited to the RightAlly admin',
                "Hi {admin_name},\n\n{invited_by} has invited you to the RightAlly onboarding admin.\n\n{button:Set your password|{invite_link}}\n\nThis link expires in 72 hours."],
            'password_reset' => ['Password reset', 'An admin requests a reset', false, 'Reset your RightAlly admin password',
                "Hi {admin_name},\n\nUse the button below to choose a new password. The link expires in 60 minutes. If you didn’t ask for this, ignore this email.\n\n{button:Reset password|{reset_link}}"],
        ];
    }
}

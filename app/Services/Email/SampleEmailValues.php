<?php

namespace App\Services\Email;

/** Realistic sample values for previews and test sends of email templates. */
final class SampleEmailValues
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'first_name' => 'Maria', 'company_name' => 'Sunline Realty Group',
            'deposit_amount' => '$255.00', 'deposit_status' => 'paid', 'balance_amount' => '$2,295.00',
            'monthly_amount' => '$660.00', 'subscription_amount' => '$660.00 a month', 'agent_count' => '8', 'go_live_date' => 'October 23, 2026',
            'first_monthly_date' => 'November 22, 2026', 'term_end_date' => 'October 22, 2027',
            'payment_method' => 'Visa ending 4242', 'amount' => '$660.00', 'period' => 'November 2026',
            'agreement_number' => 'RA-2026-0024', 'agreement_link' => url('/'), 'payment_link' => url('/'), 'renewal_link' => url('/'),
            'admin_name' => 'Sunil', 'invited_by' => 'Srini', 'invite_link' => url('/admin'), 'reset_link' => url('/admin'),
            'invoice_number' => 'INV-2026-0311', 'dispute_reason' => 'product not received', 'respond_by' => 'October 14, 2026',
            'stripe_link' => 'https://dashboard.stripe.com/disputes', 'customer_link' => url('/admin/customers'),
            'account_link' => url('/account'), 'card_expiry' => '10/2026', 'old_agent_count' => '8', 'effective_date' => 'November 22, 2026',
            'signer_name' => 'Robert Alvarez', 'requested_by' => 'Maria Alvarez', 'signing_link' => url('/'),
            'alert_title' => 'New signing: Sunline Realty Group', 'alert_text' => 'Maria Alvarez (Miami, FL) signed for 8 agents. Source: NAR2026 link.', 'alert_link' => url('/admin'),
            'refund_amount' => '$120.00', 'refund_reason' => 'Billed for 6 extra agents in error', 'credit_amount' => '$100.00', 'credit_reason' => 'Downtime on Oct 3', 'pause_until' => 'January 15, 2027',
            'first_charge_date' => 'November 1, 2026', 'service_end_date' => 'October 31, 2026', 'end_charges' => 'No further charges will be made after that date.', 'book_call_link' => url('/book-a-call'),
            'onboarding_link' => url('/?coupon=NAR2026'), 'password_link' => url('/account'), 'link_days' => '90', 'resume_link' => url('/'), 'next_step' => 'review and sign your agreement',
            'login_code' => '482913', 'code_minutes' => '10',
            'fee_table' => [['Paid today', '$255.00'], ['Due on go-live, Oct 23, 2026', '$2,295.00'], ['Monthly from Nov 22, 2026', '$660.00']],
            'receipt_details' => [['Receipt', 'INV-2026-0311'], ['Date', 'Sep 23, 2026'], ['For', 'Deposit'], ['Paid with', 'Visa ending 4242'], ['Amount', '$255.00']],
        ];
    }
}

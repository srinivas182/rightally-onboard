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
            'monthly_amount' => '$660.00', 'agent_count' => '8', 'go_live_date' => 'October 23, 2026',
            'first_monthly_date' => 'November 22, 2026', 'term_end_date' => 'October 22, 2027',
            'payment_method' => 'Visa ending 4242', 'amount' => '$660.00', 'period' => 'November 2026',
            'agreement_number' => 'RA-2026-0024', 'agreement_link' => url('/'), 'payment_link' => url('/'), 'renewal_link' => url('/'),
            'admin_name' => 'Sunil', 'invited_by' => 'Srini', 'invite_link' => url('/admin'), 'reset_link' => url('/admin'),
            'fee_table' => [['Paid today', '$255.00'], ['Due on go-live, Oct 23, 2026', '$2,295.00'], ['Monthly from Nov 22, 2026', '$660.00']],
            'receipt_details' => [['Receipt', 'INV-2026-0311'], ['Date', 'Sep 23, 2026'], ['For', 'Deposit'], ['Paid with', 'Visa ending 4242'], ['Amount', '$255.00']],
        ];
    }
}

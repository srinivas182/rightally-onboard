<?php

namespace App\Services\Email;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Onboarding\AccountController;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/** Placeholder values for emails about a customer (and optionally one invoice). */
final class CustomerEmailValues
{
    /** @return array<string, mixed> */
    public function for(Customer $customer, ?Invoice $invoice = null, array $extra = []): array
    {
        $contract = $customer->contracts()->whereNotNull('signed_at')->latest('signed_at')->first();
        $goLive = $customer->go_live_date ?? $contract?->starts_on;
        $deposit = $customer->invoices()->where('type', 'deposit')->latest('id')->first();

        $values = [
            'first_name' => $customer->first_name,
            'company_name' => $customer->company_name,
            'agent_count' => (string) $customer->agent_count,
            'payment_method' => $customer->payment_method_label ?: 'your saved payment method',
            'go_live_date' => $goLive ? Carbon::parse($goLive)->format('F j, Y') : '',
            'first_monthly_date' => $goLive ? Carbon::parse($goLive)->addDays(30)->format('F j, Y') : '',
            'agreement_link' => $this->link('onboarding.agreement', $customer),
            'payment_link' => $invoice?->hosted_invoice_url ?: $this->link('onboarding.payment', $customer),
            'account_link' => URL::temporarySignedRoute('account.show', now()->addDays(AccountController::LINK_DAYS), ['customer' => $customer->uuid]),
            'deposit_status' => $deposit?->status === InvoiceStatus::Processing ? 'being processed by your bank' : 'paid',
        ];

        if ($contract) {
            $values += $this->contractValues($contract);
        }
        if ($invoice) {
            $values['amount'] = Money::format($invoice->amount_cents);
            $values['period'] = $invoice->period_start ? $invoice->period_start->format('F Y') : $invoice->type->label();
            $values['receipt_details'] = [
                ['Receipt', $invoice->number],
                ['Date', ($invoice->paid_at ?? now())->copy()->setTimezone(BusinessClock::timezone())->format('M j, Y')],
                ['For', $invoice->type->label().($invoice->period_start ? ', '.$invoice->period_start->format('F Y') : '')],
                ['Paid with', $customer->payment_method_label ?: '—'],
                ['Amount', Money::format($invoice->amount_cents)],
            ];
        }

        return array_merge($values, $extra);
    }

    /** @return array<string, mixed> */
    private function contractValues(Contract $contract): array
    {
        $goLive = $contract->customer->go_live_date ?? $contract->starts_on;

        return [
            'agreement_number' => $contract->number,
            'deposit_amount' => Money::format($contract->deposit_cents),
            'balance_amount' => Money::format($contract->balance_cents),
            'monthly_amount' => Money::format($contract->monthlyFeeCents($contract->customer->agent_count)),
            'subscription_amount' => $contract->recurringLabel($contract->customer->agent_count),
            'term_end_date' => $contract->ends_on?->format('F j, Y') ?? '',
            'fee_table' => [
                ['Paid today', Money::format($contract->deposit_cents)],
                ['Due on go-live'.($goLive ? ', '.Carbon::parse($goLive)->format('M j, Y') : ''), Money::format($contract->balance_cents)],
                ['Monthly from '.($goLive ? Carbon::parse($goLive)->addDays(30)->format('M j, Y') : 'go-live + 30 days'), Money::format($contract->monthlyFeeCents($contract->customer->agent_count))],
            ],
        ];
    }

    /** Signed links let the client open their pages from any device for 30 days. */
    private function link(string $route, Customer $customer): string
    {
        return URL::temporarySignedRoute($route, now()->addDays(30), ['customer' => $customer->uuid]);
    }
}

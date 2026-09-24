<?php

namespace App\Services\Admin;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Figures for the admin dashboard. Revenue is money that has settled (bank payments count once they clear). */
final class DashboardStats
{
    public const ONBOARDED = [CustomerStatus::AwaitingGoLive, CustomerStatus::BalanceFailed, CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended, CustomerStatus::Paused, CustomerStatus::Cancelled, CustomerStatus::Expired];

    /** @return array{0: Carbon, 1: Carbon, 2: string} start, end (business time zone), label */
    public function range(string $range, ?string $from = null, ?string $to = null): array
    {
        $now = BusinessClock::now();

        return match ($range) {
            'day' => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Today'],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay(), $now->format('Y').' so far'],
            'custom' => $this->custom($from, $to),
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay(), $now->format('F Y')],
        };
    }

    public function revenueCents(Carbon $start, Carbon $end): int
    {
        // Settled money, less refunds and the sales tax collected (tax isn't revenue). Open chargebacks are excluded.
        return (int) Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereIn('payments.status', ['succeeded', 'refunded'])
            ->whereBetween('payments.settled_at', [$start->copy()->utc(), $end->copy()->utc()])
            ->selectRaw('COALESCE(SUM(payments.amount_cents - payments.refunded_cents - invoices.tax_cents), 0) as net')
            ->value('net');
    }

    /** Revenue by month for the last 12 months, for the chart. @return array{labels: array, values: array} */
    public function monthlyRevenue(): array
    {
        $labels = [];
        $values = [];
        $cursor = BusinessClock::now()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) {
            $labels[] = $cursor->format('M y');
            $values[] = round($this->revenueCents($cursor->copy(), $cursor->copy()->endOfMonth()) / 100, 2);
            $cursor->addMonth();
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /** @return array<string, int> */
    public function kpis(): array
    {
        $today = BusinessClock::today();
        $monthStart = BusinessClock::now()->startOfMonth()->utc();
        $goLives = Customer::where('status', CustomerStatus::AwaitingGoLive)->whereBetween('go_live_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->with('contracts')->get();

        return [
            'onboarded' => Customer::whereIn('status', self::ONBOARDED)->count(),
            'incomplete' => Customer::whereIn('status', [CustomerStatus::Draft, CustomerStatus::ContractSigned])->count(),
            'onboarded_this_month' => Customer::whereIn('status', self::ONBOARDED)->where('onboarding_started_at', '>=', $monthStart)->count(),
            'failed' => Invoice::where('status', InvoiceStatus::Failed)->whereIn('type', [InvoiceType::Balance, InvoiceType::Monthly, InvoiceType::Annual, InvoiceType::EarlyTermination])->count(),
            'failed_cents' => (int) Invoice::where('status', InvoiceStatus::Failed)->sum('amount_cents'),
            'go_lives' => $goLives->count(),
            'go_lives_cents' => (int) $goLives->sum(fn ($c) => $c->contracts->where('status', ContractStatus::Signed)->sortByDesc('signed_at')->first()?->balance_cents ?? 0),
            'suspended' => Customer::where('status', CustomerStatus::Suspended)->count(),
            'renewals' => Contract::where('status', ContractStatus::Signed)
                ->whereBetween('ends_on', [$today->toDateString(), $today->copy()->addDays(45)->toDateString()])
                ->whereHas('customer', fn ($q) => $q->whereIn('status', [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended, CustomerStatus::Paused]))
                ->whereDoesntHave('renewals', fn ($q) => $q->where('status', ContractStatus::Signed))
                ->count(),
        ];
    }

    /** Things an admin should look at, most urgent first. @return Collection<int, array{kind: string, customer: Customer, text: string, detail: string}> */
    public function attention(): Collection
    {
        $today = BusinessClock::today();
        $items = collect();

        Invoice::with('customer')->where('status', InvoiceStatus::Failed)->latest('failed_at')->limit(5)->get()
            ->each(fn (Invoice $i) => $items->push(['kind' => 'danger', 'customer' => $i->customer,
                'text' => "{$i->type->label()} payment failed", 'detail' => rtrim($i->failure_reason ?? 'Declined', '.').'. Failed '.($i->failed_at?->setTimezone(BusinessClock::timezone())->format('M j') ?? '').'.']));

        Customer::where('status', CustomerStatus::AwaitingGoLive)->whereBetween('go_live_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])->orderBy('go_live_date')->limit(5)->get()
            ->each(fn (Customer $c) => $items->push(['kind' => 'primary', 'customer' => $c,
                'text' => 'Goes live '.$c->go_live_date->format('M j'), 'detail' => 'Balance charges automatically that morning.']));

        Customer::where('status', CustomerStatus::ContractSigned)->where('updated_at', '<=', now()->subDay())->limit(5)->get()
            ->each(fn (Customer $c) => $items->push(['kind' => 'warning', 'customer' => $c,
                'text' => 'Signed but deposit not paid', 'detail' => 'Signed '.$c->updated_at->setTimezone(BusinessClock::timezone())->format('M j').'. Consider following up.']));

        Payment::with('customer', 'invoice')->where('status', 'disputed')->orderBy('dispute_due_by')->limit(5)->get()
            ->each(fn ($p) => $items->prepend(['kind' => 'danger', 'customer' => $p->customer,
                'text' => 'Chargeback on '.$p->invoice->number, 'detail' => 'Respond in Stripe by '.($p->dispute_due_by?->setTimezone(BusinessClock::timezone())->format('M j') ?? 'the deadline').'.']));

        Approval::with('customer', 'requester')->where('status', 'pending')->oldest()->limit(3)->get()
            ->each(fn ($a) => $items->prepend(['kind' => 'warning', 'customer' => $a->customer,
                'text' => "{$a->label()} waiting for approval", 'detail' => "Requested by {$a->requester->name}. Open Approvals to decide."]));
        Customer::whereNotNull('email_bounced_at')->whereNotIn('status', [CustomerStatus::Cancelled, CustomerStatus::Expired, CustomerStatus::Draft])->limit(3)->get()
            ->each(fn ($c) => $items->push(['kind' => 'danger', 'customer' => $c, 'text' => 'Emails are bouncing', 'detail' => "{$c->email}: {$c->email_bounce_reason}"]));

        return $items->take(10);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: string} */
    private function custom(?string $from, ?string $to): array
    {
        $tz = BusinessClock::timezone();
        try {
            $start = Carbon::parse((string) $from, $tz)->startOfDay();
            $end = Carbon::parse((string) $to, $tz)->endOfDay();
        } catch (\Throwable) {
            return $this->range('month');
        }
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end, $start->format('M j, Y').' to '.$end->format('M j, Y')];
    }
}

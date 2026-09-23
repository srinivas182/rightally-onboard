<?php

namespace App\Services\Admin;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\BillingDates;
use App\Support\BusinessClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Funnel and revenue reports. */
final class ReportStats
{
    public const GROUPS = ['source' => 'Source', 'coupon' => 'Coupon', 'campaign' => 'Ad campaign', 'month' => 'Month started'];

    private const ACTIVE = [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended];

    /**
     * Onboarding funnel for clients who started in the date range, grouped.
     *
     * @return Collection<int, array{group: string, started: int, signed: int, paid: int, live: int}>
     */
    public function funnel(Carbon $from, Carbon $to, string $groupBy): Collection
    {
        $customers = Customer::withTrashed()
            ->with(['coupon', 'contracts' => fn ($q) => $q->whereNotNull('signed_at'), 'invoices' => fn ($q) => $q->where('type', 'deposit')])
            ->whereBetween('onboarding_started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->get();

        return $customers->groupBy(fn (Customer $c) => match ($groupBy) {
            'coupon' => $c->coupon?->code ?? ($c->quote_id ? 'Custom quote' : 'No coupon'),
            'campaign' => $c->utm['utm_campaign'] ?? '(none)',
            'month' => $c->onboarding_started_at->copy()->setTimezone(BusinessClock::timezone())->format('Y-m'),
            default => $c->source ?: 'Direct',
        })->map(fn (Collection $g, string $key) => [
            'group' => $key,
            'started' => $g->count(),
            'signed' => $g->filter(fn ($c) => $c->contracts->isNotEmpty())->count(),
            'paid' => $g->filter(fn ($c) => $c->invoices->whereIn('status.value', ['paid', 'processing'])->isNotEmpty() || ! in_array($c->status, [CustomerStatus::Draft, CustomerStatus::ContractSigned], true))->count(),
            'live' => $g->filter(fn ($c) => $c->live_at !== null)->count(),
        ])->sortByDesc('started')->values();
    }

    /** @return array{mrr: int, customers: int, new_mrr: int, churned_mrr: int, new: int, churned: int} */
    public function mrr(): array
    {
        $month = BusinessClock::now()->startOfMonth();
        $fee = fn (Customer $c) => $c->contracts->where('status', ContractStatus::Signed)->sortByDesc('signed_at')->first()?->monthlyEquivalentCents($c->agent_count) ?? 0;

        $active = Customer::with('contracts')->whereIn('status', self::ACTIVE)->get();
        $newThisMonth = $active->filter(fn ($c) => $c->live_at && $c->live_at->gte($month->copy()->utc()));
        $churned = Customer::with('contracts')->whereIn('status', [CustomerStatus::Cancelled, CustomerStatus::Expired])
            ->where('cancelled_at', '>=', $month->copy()->utc())->get();
        $churnFee = fn (Customer $c) => $c->contracts->whereIn('status', [ContractStatus::Terminated, ContractStatus::Expired, ContractStatus::Signed])->sortByDesc('signed_at')->first()?->monthlyEquivalentCents($c->agent_count) ?? 0;

        return [
            'mrr' => (int) $active->sum($fee),
            'customers' => $active->count(),
            'new_mrr' => (int) $newThisMonth->sum($fee),
            'new' => $newThisMonth->count(),
            'churned_mrr' => (int) $churned->sum($churnFee),
            'churned' => $churned->count(),
        ];
    }

    /** Net revenue (refunds and tax removed) by month and charge type, last 12 months. */
    public function revenueByMonth(): Collection
    {
        $start = BusinessClock::now()->startOfMonth()->subMonths(11);
        $rows = Payment::query()
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereIn('payments.status', ['succeeded', 'refunded'])
            ->where('payments.settled_at', '>=', $start->copy()->utc())
            ->get(['payments.settled_at', 'payments.amount_cents', 'payments.refunded_cents', 'invoices.tax_cents', 'invoices.type']);

        $months = collect(range(0, 11))->mapWithKeys(fn ($i) => [$start->copy()->addMonths($i)->format('Y-m') => ['deposit' => 0, 'balance' => 0, 'monthly' => 0, 'annual' => 0, 'early_termination' => 0]]);
        foreach ($rows as $r) {
            $key = Carbon::parse($r->settled_at)->setTimezone(BusinessClock::timezone())->format('Y-m');
            if ($months->has($key)) {
                $m = $months[$key];
                $m[$r->type] = ($m[$r->type] ?? 0) + $r->amount_cents - $r->refunded_cents - $r->tax_cents;
                $months[$key] = $m;
            }
        }

        return $months->map(fn ($m, $k) => $m + ['month' => $k, 'total' => array_sum($m)])->values();
    }

    /** Expected charges for the next 3 months (go-live balances and monthly fees at today's agent counts). */
    public function forecast(): Collection
    {
        $customers = Customer::with('contracts')->whereIn('status', [...self::ACTIVE, CustomerStatus::AwaitingGoLive, CustomerStatus::ContractSigned])->get();

        return collect(range(1, 3))->map(function ($i) use ($customers) {
            $start = BusinessClock::now()->startOfMonth()->addMonthsNoOverflow($i - 1);
            $end = $start->copy()->endOfMonth();
            $balances = 0;
            $monthly = 0;
            foreach ($customers as $c) {
                $k = $c->contracts->where('status', ContractStatus::Signed)->sortByDesc('signed_at')->first();
                if (! $k || ! $c->go_live_date) {
                    continue;
                }
                $goLive = BusinessClock::date($c->go_live_date);
                if (in_array($c->status, [CustomerStatus::AwaitingGoLive, CustomerStatus::ContractSigned], true) && $goLive->between($start, $end)) {
                    $balances += $k->balance_cents;
                }
                $charge = BillingDates::nextCharge($c, $k, $start);
                if ($charge && $charge->lte($end) && ! in_array($c->status, [CustomerStatus::Suspended, CustomerStatus::Paused], true)) {
                    $monthly += $k->recurringFeeCents($c->agent_count);
                }
            }

            return ['month' => $start->format('F Y'), 'balances' => $balances, 'monthly' => $monthly, 'total' => $balances + $monthly];
        });
    }
}

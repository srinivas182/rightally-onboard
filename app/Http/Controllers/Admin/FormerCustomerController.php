<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\ServiceEnding;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin > Former customers: who stopped, when, why, and what they paid over their time with us. */
class FormerCustomerController extends Controller
{
    public function index(Request $request): View
    {
        $rows = $this->rows($request);
        $monthStart = BusinessClock::now()->startOfMonth();
        $yearStart = BusinessClock::now()->startOfYear();

        return view('admin.former.index', [
            'rows' => $rows,
            'ending' => Customer::whereNotNull('end_type')->whereNotIn('status', [CustomerStatus::Cancelled, CustomerStatus::Expired])->orderBy('service_ends_on')->get(),
            'stats' => [
                'month' => $rows->filter(fn ($r) => $r['ended'] && $r['ended']->gte($monthStart))->count(),
                'year' => $rows->filter(fn ($r) => $r['ended'] && $r['ended']->gte($yearStart))->count(),
                'mrr_lost_month' => $rows->filter(fn ($r) => $r['ended'] && $r['ended']->gte($monthStart))->sum('monthly'),
                'lifetime' => $rows->sum('revenue'),
            ],
            'reason' => (string) $request->query('reason'),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->rows($request);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Company', 'Contact', 'Email', 'Source', 'Coupon', 'Onboarded', 'Went live', 'Ended', 'Months active', 'Reason', 'Notes', 'Monthly fee at end', 'Revenue to date', 'Last payment']);
            foreach ($rows as $r) {
                $c = $r['customer'];
                fputcsv($out, [$c->company_name, $c->fullName(), $c->email, $c->source, $c->coupon?->code, $r['onboarded']?->toDateString(), $c->live_at?->toDateString(), $r['ended']?->toDateString(),
                    $r['months'], ServiceEnding::REASONS[$c->end_reason] ?? $c->end_reason, $c->end_notes, number_format($r['monthly'] / 100, 2, '.', ''), number_format($r['revenue'] / 100, 2, '.', ''), $r['last_payment']?->toDateString()]);
            }
            fclose($out);
        }, 'rightally-former-customers-'.BusinessClock::today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function winBack(Customer $customer, EmailSender $email, AuditLogger $audit): RedirectResponse
    {
        $email->toCustomer('win_back', $customer, null, [], ['book_call_link' => route('book')]);
        $audit->log('customer.win_back_sent', "Sent a win-back email to {$customer->email}", $customer);

        return back()->with('success', "Win-back email sent to {$customer->email}.");
    }

    /** @return Collection<int, array> */
    private function rows(Request $request): Collection
    {
        $tz = BusinessClock::timezone();

        return Customer::with(['coupon', 'contracts', 'invoices.payments'])
            ->whereIn('status', [CustomerStatus::Cancelled, CustomerStatus::Expired])
            ->when($request->filled('reason'), fn ($q) => $q->where('end_reason', $request->query('reason')))
            ->orderByDesc('cancelled_at')->get()
            ->map(function (Customer $c) use ($tz) {
                $contract = $c->contracts->whereNotNull('signed_at')->sortByDesc('signed_at')->first();
                $first = $c->contracts->whereNotNull('signed_at')->sortBy('signed_at')->first();
                $payments = $c->invoices->flatMap->payments->whereIn('status', ['succeeded', 'refunded']);
                $ended = $c->cancelled_at?->copy()->setTimezone($tz);
                $start = $c->live_at?->copy()->setTimezone($tz);

                return [
                    'customer' => $c,
                    'onboarded' => $first?->signed_at?->copy()->setTimezone($tz),
                    'ended' => $ended,
                    'months' => $start && $ended ? max(0, (int) round($start->diffInMonths($ended, true))) : 0,
                    'monthly' => $contract?->monthlyEquivalentCents($c->agent_count) ?? 0,
                    'revenue' => ServiceEnding::revenueCents($c),
                    'last_payment' => $payments->max('settled_at'),
                ];
            });
    }
}

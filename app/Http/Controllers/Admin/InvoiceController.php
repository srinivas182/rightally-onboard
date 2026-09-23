<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\InvoicePdf;
use App\Services\Email\EmailSender;
use App\Support\BillingDates;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $period = in_array($request->query('period'), ['month', 'last', 'year', 'all'], true) ? $request->query('period') : 'month';
        $type = InvoiceType::tryFrom((string) $request->query('type'));

        return view('admin.invoices.index', [
            'tab' => in_array($request->query('tab'), ['paid', 'failed', 'upcoming'], true) ? $request->query('tab') : 'failed',
            'paid' => $this->paidQuery($period, $type)->with('customer', 'payments')->paginate(25, ['*'], 'paid_page')->withQueryString(),
            'failed' => Invoice::with('customer')->where('status', InvoiceStatus::Failed)->latest('failed_at')->get(),
            'processing' => Invoice::with('customer')->where('status', InvoiceStatus::Processing)->latest('id')->get(),
            'upcoming' => $this->upcoming(),
            'period' => $period,
            'type' => $type?->value,
        ]);
    }

    public function export(Request $request, AuditLogger $audit): StreamedResponse
    {
        $period = (string) $request->query('period', 'month');
        $audit->log('invoices.exported', 'Exported paid invoices');

        return response()->streamDownload(function () use ($period, $request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Invoice', 'Customer', 'Type', 'Period', 'Agents', 'Amount', 'Stripe fee', 'Paid', 'Method']);
            $this->paidQuery($period, InvoiceType::tryFrom((string) $request->query('type')))->with('customer', 'payments')->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $i) {
                    $p = $i->payments->firstWhere('status', 'succeeded');
                    fputcsv($out, [$i->number, $i->customer->company_name, $i->type->label(), $i->period_start?->format('Y-m'), $i->agents_billed,
                        number_format($i->amount_cents / 100, 2, '.', ''), $p?->fee_cents !== null ? number_format($p->fee_cents / 100, 2, '.', '') : '',
                        $i->paid_at?->setTimezone(BusinessClock::timezone())->toDateString(), $p?->method_label]);
                }
            });
            fclose($out);
        }, 'rightally-invoices-'.$period.'-'.BusinessClock::today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return response($pdf->render($invoice), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$pdf->filename($invoice).'"']);
    }

    /** Email the client Stripe's secure page to update their payment method and pay. */
    public function resend(Invoice $invoice, EmailSender $email, AuditLogger $audit): RedirectResponse
    {
        abort_unless($invoice->status === InvoiceStatus::Failed && $invoice->hosted_invoice_url, 400);
        $template = match ($invoice->type) {
            InvoiceType::Balance => 'balance_failed',
            InvoiceType::EarlyTermination => 'early_termination',
            default => 'payment_failed',
        };
        $email->toCustomer($template, $invoice->customer, $invoice);
        $invoice->update(['payment_link_sent_at' => now()]);
        $audit->log('invoice.link_resent', "Resent payment link for {$invoice->number} to {$invoice->customer->email}", $invoice);

        return back()->with('success', "Payment link for {$invoice->number} sent to {$invoice->customer->email}.");
    }

    private function paidQuery(string $period, ?InvoiceType $type)
    {
        $now = BusinessClock::now();
        [$start, $end] = match ($period) {
            'last' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfDay()],
            'all' => [Carbon::create(2000), $now->copy()->endOfDay()],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
        };

        return Invoice::where('status', InvoiceStatus::Paid)
            ->whereBetween('paid_at', [$start->utc(), $end->utc()])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest('paid_at');
    }

    /** Charges expected in the next 30 days: go-live balances and monthly fees. @return Collection<int, array> */
    private function upcoming(): Collection
    {
        $today = BusinessClock::today();
        $until = $today->copy()->addDays(30);
        $rows = collect();

        Customer::with(['contracts' => fn ($q) => $q->where('status', ContractStatus::Signed)])
            ->whereIn('status', [CustomerStatus::AwaitingGoLive, CustomerStatus::Live, CustomerStatus::PaymentFailed])->get()
            ->each(function (Customer $c) use ($until, $rows) {
                $contract = $c->contracts->sortByDesc('signed_at')->first();
                if (! $contract || ! $c->go_live_date) {
                    return;
                }
                if ($c->status === CustomerStatus::AwaitingGoLive) {
                    $date = BusinessClock::date($c->go_live_date);
                    if ($date->lte($until)) {
                        $rows->push(['date' => $date, 'customer' => $c, 'type' => 'Balance at go-live', 'amount' => $contract->balance_cents]);
                    }

                    return;
                }
                $next = BillingDates::nextCharge($c, $contract);
                if ($next && $next->lte($until)) {
                    $rows->push(['date' => $next, 'customer' => $c, 'type' => ($contract->isAnnual() ? 'Yearly' : 'Monthly').", {$c->agent_count} agents", 'amount' => $contract->recurringFeeCents($c->agent_count)]);
                }
            });

        return $rows->sortBy('date')->values();
    }
}

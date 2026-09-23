<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AgentCountSource;
use App\Enums\ContractStatus;
use App\Enums\CustomerStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\AgentCountService;
use App\Services\Billing\EarlyTerminationService;
use App\Services\Billing\GoLiveService;
use App\Services\Billing\SubscriptionService;
use App\Services\Email\EmailSender;
use App\Support\BusinessClock;
use App\Support\Money;
use App\Support\UsPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        return view('admin.customers.index', [
            'customers' => $this->query($request)->paginate(25)->withQueryString(),
            'q' => (string) $request->query('q'),
            'status' => (string) $request->query('status'),
            'statuses' => CustomerStatus::cases(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->audit->log('customers.exported', 'Exported the customer list');

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Company', 'Signer', 'Title', 'Email', 'Phone', 'City', 'State', 'Status', 'Go-live', 'Agents billed', 'Monthly fee', 'Payment method', 'Source', 'Started']);
            $this->query($request)->with('contracts')->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $c) {
                    $contract = $c->contracts->where('status', ContractStatus::Signed)->sortByDesc('signed_at')->first();
                    fputcsv($out, [$c->company_name, $c->fullName(), $c->title, $c->email, UsPhone::format($c->phone_e164), $c->city, $c->state_code,
                        $c->status->label(), $c->go_live_date?->toDateString(), $c->agent_count,
                        $contract ? number_format($contract->monthlyFeeCents($c->agent_count) / 100, 2, '.', '') : '',
                        $c->payment_method_label, $c->source, $c->onboarding_started_at?->setTimezone(BusinessClock::timezone())->toDateString()]);
                }
            });
            fclose($out);
        }, 'rightally-customers-'.BusinessClock::today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(Customer $customer, GoLiveService $goLive, EarlyTerminationService $termination): View
    {
        $customer->load(['contracts' => fn ($q) => $q->latest('id'), 'contracts.template', 'invoices' => fn ($q) => $q->latest('id'),
            'agentCountLogs' => fn ($q) => $q->latest('id'), 'emailLogs' => fn ($q) => $q->latest('id')->limit(50), 'coupon']);
        $contract = $customer->contracts->where('status', ContractStatus::Signed)->sortByDesc('signed_at')->first();
        $canTerminate = in_array($customer->status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::Suspended], true);

        return view('admin.customers.show', [
            'customer' => $customer,
            'contract' => $contract,
            'canChangeGoLive' => $goLive->canChange($customer),
            'lockDate' => $goLive->lockDate($customer),
            'termination' => $canTerminate && $contract ? $termination->quote($customer) : null,
            'activity' => ActivityLog::with('admin')
                ->where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', $customer->getMorphClass())->where('subject_id', $customer->id))
                    ->orWhere(fn ($w) => $w->where('subject_type', (new \App\Models\Contract)->getMorphClass())->whereIn('subject_id', $customer->contracts->pluck('id')))
                    ->orWhere(fn ($w) => $w->where('subject_type', (new \App\Models\Invoice)->getMorphClass())->whereIn('subject_id', $customer->invoices->pluck('id'))))
                ->latest('id')->limit(100)->get(),
            'customEmails' => EmailTemplate::where('is_system', false)->where('is_enabled', true)->orderBy('name')->get(),
            'newToken' => session('agent_token'),
        ]);
    }

    public function updateGoLive(Request $request, Customer $customer, GoLiveService $goLive): RedirectResponse
    {
        $request->validate(['go_live_date' => ['required', 'date']]);
        $goLive->change($customer, $request->input('go_live_date'), $request->user('admin'));

        return back()->with('success', 'Go-live date changed to '.BusinessClock::date($customer->fresh()->go_live_date)->format('M j, Y').'. The client has been emailed.');
    }

    public function updateAgents(Request $request, Customer $customer, AgentCountService $agents): RedirectResponse
    {
        $data = $request->validate(['agent_count' => ['required', 'integer', 'min:1', 'max:100000'], 'note' => ['nullable', 'string', 'max:200']]);
        $billed = $agents->set($customer, (int) $data['agent_count'], AgentCountSource::Admin, $request->user('admin'), $data['note'] ?? null);

        return back()->with('success', "Agents billed: {$billed}. Applies from the next monthly charge.");
    }

    public function updateLive(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'live_url' => ['nullable', 'url:https', 'max:255'],
            'live_host' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
        ], ['live_url.url' => 'Enter a full https:// address.', 'live_host.regex' => 'Enter a host name like sunline.rightally.io.']);
        $customer->update($data);
        $this->audit->log('customer.live_updated', "Updated live site details for {$customer->company_name}", $customer, $data);

        return back()->with('success', 'Live site details saved.');
    }

    public function newToken(Request $request, Customer $customer, AgentCountService $agents): RedirectResponse
    {
        $token = $agents->newToken($customer);
        $this->audit->log('customer.token_created', "Created a new agent API token for {$customer->company_name}", $customer);

        return redirect()->to(route('admin.customers.show', $customer).'#overview')->with('agent_token', $token)
            ->with('success', 'New API token created. Copy it now: it won’t be shown again. The previous token no longer works.');
    }

    public function resendWelcome(Customer $customer, EmailSender $email): RedirectResponse
    {
        $contract = $customer->contracts()->where('status', ContractStatus::Signed)->latest('signed_at')->first();
        abort_unless($contract, 404);
        $email->toCustomer('agreement_signed', $customer, $customer->invoices()->where('type', 'deposit')->latest('id')->first(),
            $contract->pdf_path ? [['path' => $contract->pdf_path, 'name' => "RightAlly-Agreement-{$contract->number}.pdf"]] : []);
        $this->audit->log('customer.email_resent', "Resent the welcome email and agreement to {$customer->email}", $customer);

        return back()->with('success', "Welcome email and signed agreement sent to {$customer->email}.");
    }

    public function sendEmail(Request $request, Customer $customer, EmailSender $email): RedirectResponse
    {
        $template = EmailTemplate::where('is_system', false)->where('is_enabled', true)->findOrFail($request->integer('template_id'));
        $email->toCustomer($template->key, $customer);
        $this->audit->log('customer.email_sent', "Sent “{$template->name}” to {$customer->email}", $customer);

        return redirect()->to(route('admin.customers.show', $customer).'#emails')->with('success', "“{$template->name}” sent to {$customer->email}.");
    }

    public function suspend(Request $request, Customer $customer): RedirectResponse
    {
        if (! in_array($customer->status, [CustomerStatus::Live, CustomerStatus::PaymentFailed, CustomerStatus::AwaitingGoLive, CustomerStatus::BalanceFailed], true)) {
            throw ValidationException::withMessages(['status' => 'Only active customers can be suspended.']);
        }
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:200']])['reason'];
        $customer->update(['status' => CustomerStatus::Suspended, 'suspended_at' => now()]);
        $this->audit->log('customer.suspended', "Suspended {$customer->company_name}: {$reason}", $customer, ['reason' => $reason]);

        return back()->with('success', "{$customer->company_name} is suspended. Billing continues; reactivate to restore access.");
    }

    public function reactivate(Customer $customer): RedirectResponse
    {
        abort_unless($customer->status === CustomerStatus::Suspended, 400);
        $hasFailed = $customer->invoices()->where('status', InvoiceStatus::Failed)->exists();
        $status = $hasFailed ? CustomerStatus::PaymentFailed : ($customer->live_at ? CustomerStatus::Live : CustomerStatus::AwaitingGoLive);
        $customer->update(['status' => $status, 'suspended_at' => null]);
        $this->audit->log('customer.reactivated', "Reactivated {$customer->company_name}", $customer);

        return back()->with('success', "{$customer->company_name} is reactivated ({$status->label()}).");
    }

    public function terminate(Request $request, Customer $customer, EarlyTerminationService $termination): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:250'], 'confirm' => ['required', 'in:'.$customer->company_name]],
            ['confirm.in' => 'Type the company name exactly to confirm.']);
        $invoice = $termination->terminate($customer, $request->user('admin'), $data['reason']);

        return back()->with('success', $invoice
            ? "Agreement terminated. Early termination invoice {$invoice->number} for ".Money::format($invoice->amount_cents)." has been issued and emailed."
            : 'Agreement terminated. No remaining months were due.');
    }

    private function query(Request $request)
    {
        $q = trim((string) $request->query('q'));
        $status = CustomerStatus::tryFrom((string) $request->query('status'));

        return Customer::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('company_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('first_name', 'like', "%{$q}%")
                ->orWhere('city', 'like', "%{$q}%")))
            ->latest('id');
    }
}

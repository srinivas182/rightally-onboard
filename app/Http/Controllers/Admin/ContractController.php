<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\RenewalNotice;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\RenewalService;
use App\Services\Email\EmailSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Signed agreements and agreement templates. */
class ContractController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q'));

        $contracts = Contract::with('customer', 'template')
            ->where('status', '!=', ContractStatus::Draft)
            ->whereNotNull('signed_at')
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('number', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('company_name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%"));
            }))
            ->latest('signed_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.contracts.index', [
            'contracts' => $contracts,
            'templates' => ContractTemplate::withCount('contracts')->orderBy('type')->orderByDesc('id')->get(),
            'renewals' => Contract::with('customer', 'previous')->where('type', ContractType::Renewal)
                ->whereIn('status', [ContractStatus::Draft, ContractStatus::Signed])->latest('id')->limit(100)->get()
                ->map(fn (Contract $r) => ['contract' => $r, 'notices' => RenewalNotice::where('contract_id', $r->previous_contract_id)->pluck('sent_at', 'kind')]),
            'q' => $q,
        ]);
    }

    /** Resend the renewal offer email with a fresh link. */
    public function resendRenewal(Contract $contract, EmailSender $email, RenewalService $renewals, AuditLogger $audit): RedirectResponse
    {
        abort_unless($contract->type === ContractType::Renewal && $contract->status === ContractStatus::Draft, 400);
        $email->toCustomer('renewal_offer', $contract->customer, null, [], [
            'renewal_link' => $renewals->renewalLink($contract),
            'term_end_date' => $contract->previous->ends_on->format('F j, Y'),
        ]);
        $audit->log('contract.renewal_resent', "Resent renewal {$contract->number} to {$contract->customer->email}", $contract);

        return redirect()->to(route('admin.contracts.index').'#renewals')->with('success', "Renewal offer resent to {$contract->customer->email}.");
    }

    public function pdf(Contract $contract, AuditLogger $audit): StreamedResponse
    {
        abort_unless($contract->pdf_path && Storage::disk('local')->exists($contract->pdf_path), 404);
        $audit->log('contract.downloaded', "Downloaded agreement {$contract->number}", $contract);

        return Storage::disk('local')->download($contract->pdf_path, "RightAlly-Agreement-{$contract->number}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }
}

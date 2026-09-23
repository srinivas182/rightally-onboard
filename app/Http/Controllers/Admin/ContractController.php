<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Services\Audit\AuditLogger;
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
            'q' => $q,
        ]);
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

<?php

namespace App\Http\Controllers\Onboarding;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\SignRequest;
use App\Models\Contract;
use App\Services\Contracts\ContractRenderer;
use App\Services\Contracts\ContractSigner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Reviewing and signing a renewal agreement from the emailed link. */
class RenewalController extends Controller
{
    public function show(Contract $contract, ContractRenderer $renderer): View
    {
        abort_unless($contract->type === ContractType::Renewal, 404);
        $contract->loadMissing('template', 'customer', 'previous');

        return view('renewal.show', [
            'contract' => $contract,
            'customer' => $contract->customer,
            'body' => $contract->isSigned() ? $contract->rendered_html : $renderer->body($contract),
            'companySignature' => $renderer->companySignatureDataUri($contract),
            'expired' => $contract->status === ContractStatus::Expired,
            'signUrl' => URL::temporarySignedRoute('renewal.sign', now()->addHours(12), ['contract' => $contract->uuid]),
            'pdfUrl' => URL::temporarySignedRoute('renewal.pdf', now()->addHours(12), ['contract' => $contract->uuid]),
        ]);
    }

    public function sign(SignRequest $request, Contract $contract, ContractSigner $signer): RedirectResponse
    {
        abort_unless($contract->type === ContractType::Renewal, 404);
        if ($contract->status === ContractStatus::Draft) {
            $signer->sign($contract, trim($request->validated('typed_name')), (string) $request->signaturePng(), $request->ip(), $request->userAgent());
        }

        return redirect()->to(URL::temporarySignedRoute('renewal.show', now()->addDays(7), ['contract' => $contract->uuid]));
    }

    public function pdf(Contract $contract): StreamedResponse
    {
        abort_unless($contract->type === ContractType::Renewal && $contract->pdf_path, 404);

        return Storage::disk('local')->download($contract->pdf_path, "RightAlly-Renewal-{$contract->number}.pdf", ['Content-Type' => 'application/pdf']);
    }
}

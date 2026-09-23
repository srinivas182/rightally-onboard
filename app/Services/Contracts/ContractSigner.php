<?php

namespace App\Services\Contracts;

use App\Enums\AgentCountSource;
use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Enums\CustomerStatus;
use App\Models\AgentCountLog;
use App\Models\Contract;
use App\Models\Coupon;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsService;
use App\Support\BusinessClock;
use App\Support\SignatureImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Signs a draft agreement: stores the drawn signature and E-SIGN evidence,
 * freezes the exact terms, produces the PDF and its SHA-256 fingerprint.
 */
final class ContractSigner
{
    public function __construct(
        private readonly ContractRenderer $renderer,
        private readonly ContractPdf $pdf,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function sign(Contract $contract, string $typedName, string $signaturePng, ?string $ip, ?string $userAgent): Contract
    {
        return DB::transaction(function () use ($contract, $typedName, $signaturePng, $ip, $userAgent) {
            /** @var Contract $contract */
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($contract->status !== ContractStatus::Draft) {
                throw new RuntimeException('This agreement has already been signed.');
            }

            $customer = $contract->customer;
            $dir = "contracts/{$contract->uuid}";
            $disk = Storage::disk('local');

            $disk->put("{$dir}/client-signature.png", SignatureImage::trim($signaturePng));
            $companySig = $this->copyCompanySignature($dir);

            $isRenewal = $contract->type === ContractType::Renewal;
            // Renewals keep the term dates set when they were offered; new agreements start at the target go-live date.
            $goLive = $isRenewal ? $contract->starts_on->copy() : BusinessClock::today()->addDays((int) $this->settings->get('pricing', 'go_live_days'));
            $now = now();

            $contract->forceFill([
                'client_typed_name' => $typedName,
                'client_title' => $customer->title,
                'signer_email' => $contract->signer_email ?? $customer->email,
                'client_signature_path' => "{$dir}/client-signature.png",
                'company_signature_path' => $companySig,
                'esign_consent_at' => $now,
                'signed_at' => $now,
                'signer_ip' => $ip,
                'signer_user_agent' => $userAgent ? substr($userAgent, 0, 1000) : null,
                'starts_on' => $goLive->toDateString(),
                'ends_on' => $goLive->copy()->addMonthsNoOverflow($contract->term_months)->subDay()->toDateString(),
            ]);
            $contract->setRelation('customer', $customer);

            // Freeze the exact terms the client read, then build the PDF from them.
            $contract->rendered_html = $this->renderer->body($contract);
            $contract->status = ContractStatus::Signed;
            $bytes = $this->pdf->render($contract);
            $pdfPath = "{$dir}/{$contract->number}.pdf";
            $disk->put($pdfPath, $bytes);

            $contract->pdf_path = $pdfPath;
            $contract->document_sha256 = hash('sha256', $bytes);
            $contract->save();

            if ($isRenewal) {
                $this->audit->log('contract.renewal_signed', "{$customer->company_name} signed renewal {$contract->number}", $contract, ['signer' => $typedName, 'ip' => $ip], 'client');

                return $contract;
            }

            $customer->update(['status' => CustomerStatus::ContractSigned, 'go_live_date' => $goLive->toDateString()]);

            AgentCountLog::create([
                'customer_id' => $customer->id,
                'old_count' => null,
                'new_count' => $contract->agent_count,
                'source' => AgentCountSource::Onboarding,
                'note' => "Entered {$customer->agent_count_entered} at onboarding",
            ]);

            if ($contract->coupon_id) {
                Coupon::whereKey($contract->coupon_id)->increment('times_used');
            }

            $this->audit->log('contract.signed', "{$customer->company_name} signed agreement {$contract->number}", $contract, [
                'signer' => $typedName, 'ip' => $ip,
            ], 'client');

            return $contract;
        });
    }

    /** Snapshot the company signature image (if one is used) into the agreement's folder. */
    private function copyCompanySignature(string $dir): ?string
    {
        if ($this->settings->get('signature', 'style') !== 'image') {
            return null;
        }
        $src = $this->settings->get('signature', 'image_path');
        if (! $src || ! Storage::disk('local')->exists($src)) {
            return null;
        }
        Storage::disk('local')->copy($src, "{$dir}/company-signature.png");

        return "{$dir}/company-signature.png";
    }
}

<?php

namespace App\Services\Contracts;

use App\Enums\ContractType;
use App\Models\Contract;
use App\Services\Settings\SettingsService;
use App\Support\BusinessClock;
use App\Support\Money;
use App\Support\UsStates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an agreement template plus a contract's price snapshot into HTML,
 * for the signing page and the PDF. The same method produces both, so the
 * PDF always contains exactly the text the client read.
 */
final class ContractRenderer
{
    /** Placeholders admins can use in templates, with a description for the editor. */
    public const PLACEHOLDERS = [
        'agreement_number' => 'Agreement number, e.g. RA-2026-0024',
        'effective_date' => 'Date the client signs',
        'company_legal_name' => 'Settings > Company legal name',
        'company_dba' => 'Settings > Company "doing business as"',
        'company_address' => 'Settings > Company address',
        'client_company' => 'Brokerage or company name',
        'client_name' => 'Signer’s full name',
        'client_title' => 'Signer’s title',
        'client_address' => 'Client’s full address',
        'client_email' => 'Client’s email',
        'setup_fee' => 'Set-up fee',
        'coupon_code' => 'Coupon code (blank if none)',
        'discount_percent' => 'Coupon discount %',
        'discount_amount' => 'Coupon discount amount',
        'implementation_fee' => 'Set-up fee after discount',
        'deposit_percent' => 'Deposit %',
        'deposit_amount' => 'Deposit amount',
        'balance_amount' => 'Balance due at go-live',
        'go_live_days' => 'Days from signing to target go-live',
        'go_live_date' => 'Target go-live date',
        'platform_fee' => 'Monthly platform fee',
        'per_agent_fee' => 'Monthly fee per agent',
        'min_agents' => 'Minimum agents billed',
        'agent_count' => 'Agents billed',
        'monthly_fee' => 'Monthly fee at the agents billed',
        'min_monthly_fee' => 'Monthly fee at the minimum agents',
        'term_months' => 'Minimum term in months',
        'term_start_date' => 'Start of the term (renewals)',
        'term_end_date' => 'End of the term (renewals)',
        'previous_agreement_number' => 'Agreement being renewed (renewals)',
        'fee_table' => 'The fee summary table',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    /** The agreement terms: template with every placeholder filled in. */
    public function body(Contract $contract): string
    {
        $values = $this->values($contract);
        $feeTable = $this->feeTableHtml($contract);

        return (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function (array $m) use ($values, $feeTable) {
            if ($m[1] === 'fee_table') {
                return $feeTable;
            }

            return array_key_exists($m[1], $values) ? e($values[$m[1]]) : $m[0];
        }, $contract->template->body_html);
    }

    /** @return array<string, string> */
    public function values(Contract $contract): array
    {
        $c = $contract->customer;
        $signedOn = $contract->signed_at?->copy()->setTimezone(BusinessClock::timezone()) ?? BusinessClock::today();
        $goLive = $contract->starts_on ?? BusinessClock::today()->addDays($this->goLiveDays($contract));

        return [
            'agreement_number' => $contract->number,
            'effective_date' => $signedOn->format('F j, Y'),
            'company_legal_name' => $contract->company_legal_name,
            'company_dba' => (string) $contract->company_dba,
            'company_address' => $contract->company_address,
            'client_company' => $c->company_name,
            'client_name' => $c->fullName(),
            'client_title' => $c->title,
            'client_address' => $this->clientAddress($contract),
            'client_email' => $c->email,
            'setup_fee' => Money::format($contract->setup_fee_cents),
            'coupon_code' => (string) $contract->coupon_code,
            'discount_percent' => $this->pct($contract->discount_percent),
            'discount_amount' => Money::format($contract->discount_cents),
            'implementation_fee' => Money::format($contract->implementation_fee_cents),
            'deposit_percent' => $this->pct($contract->deposit_percent),
            'deposit_amount' => Money::format($contract->deposit_cents),
            'balance_amount' => Money::format($contract->balance_cents),
            'go_live_days' => (string) $this->goLiveDays($contract),
            'go_live_date' => Carbon::parse($goLive)->format('F j, Y'),
            'platform_fee' => Money::format($contract->platform_fee_cents),
            'per_agent_fee' => Money::format($contract->per_agent_fee_cents),
            'min_agents' => (string) $contract->min_agents,
            'agent_count' => (string) $contract->agent_count,
            'monthly_fee' => Money::format($contract->monthlyFeeCents()),
            'min_monthly_fee' => Money::format($contract->monthlyFeeCents($contract->min_agents)),
            'term_months' => (string) $contract->term_months,
            'term_start_date' => $contract->starts_on ? Carbon::parse($contract->starts_on)->format('F j, Y') : '',
            'term_end_date' => $contract->ends_on ? Carbon::parse($contract->ends_on)->format('F j, Y') : '',
            'previous_agreement_number' => (string) $contract->previous?->number,
        ];
    }

    public function feeTableHtml(Contract $contract): string
    {
        $v = $this->values($contract);
        if ($contract->type === ContractType::Renewal) {
            $rows = [
                ['Renewal term', "{$v['term_start_date']} to {$v['term_end_date']}"],
                ['Platform fee per month', $v['platform_fee']],
                ["Per agent per month (minimum {$v['min_agents']})", $v['per_agent_fee']],
                ["<b>Monthly fee at {$v['agent_count']} agents</b>", '<b>'.e($v['monthly_fee']).'</b>'],
            ];

            return $this->feeRows('Renewal fees', $rows);
        }
        $rows = [['Set-up fee', $v['setup_fee']]];
        if ($contract->discount_cents > 0) {
            $rows[] = ["Discount ({$v['coupon_code']}, {$v['discount_percent']}%)", '-'.$v['discount_amount']];
        }
        $rows[] = ['<b>Implementation fee</b>', '<b>'.e($v['implementation_fee']).'</b>'];
        $rows[] = ["Deposit on signing ({$v['deposit_percent']}%)", $v['deposit_amount']];
        $rows[] = ["Balance on go-live date (target {$v['go_live_date']})", $v['balance_amount']];
        $rows[] = ["Monthly: {$v['platform_fee']} platform + {$v['per_agent_fee']} × {$v['agent_count']} agents (minimum {$v['min_agents']})", $v['monthly_fee']];

        return $this->feeRows('Fee summary', $rows);
    }

    /** @param array<int, array{0: string, 1: string}> $rows labels/amounts starting with <b> are trusted markup */
    private function feeRows(string $title, array $rows): string
    {
        $html = '<div class="fee-box"><div class="hd">'.e($title).'</div><table><tbody>';
        foreach ($rows as [$label, $amount]) {
            $l = str_starts_with($label, '<b>') ? $label : e($label);
            $a = str_starts_with($amount, '<b>') ? $amount : e($amount);
            $html .= "<tr><td>{$l}</td><td class=\"amt\">{$a}</td></tr>";
        }

        return $html.'</tbody></table></div>';
    }

    /** Company signature as an <img> data URI when an image is set, else null (use the signature font). */
    public function companySignatureDataUri(Contract $contract): ?string
    {
        $path = $contract->company_signature_path ?: $this->liveCompanySignaturePath();

        return $path && Storage::disk('local')->exists($path)
            ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path))
            : null;
    }

    public function clientSignatureDataUri(Contract $contract): ?string
    {
        $path = $contract->client_signature_path;

        return $path && Storage::disk('local')->exists($path)
            ? 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($path))
            : null;
    }

    public function clientAddress(Contract $contract): string
    {
        $c = $contract->customer;

        return "{$c->street}, {$c->city}, {$c->state_code} {$c->zip}";
    }

    public function stateName(string $code): string
    {
        return UsStates::name($code);
    }

    private function liveCompanySignaturePath(): ?string
    {
        return $this->settings->get('signature', 'style') === 'image'
            ? $this->settings->get('signature', 'image_path')
            : null;
    }

    private function goLiveDays(Contract $contract): int
    {
        return (int) ($contract->go_live_days ?? $this->settings->get('pricing', 'go_live_days'));
    }

    private function pct(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}

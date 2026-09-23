<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ContractType;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Contracts\ContractRenderer;
use App\Services\Contracts\HtmlSanitizer;
use App\Services\Pricing\QuoteCalculator;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Versioned agreement templates. Published versions are read-only so every
 * signed agreement matches its template exactly; to change wording, create a
 * new version (a draft copy), edit it, then publish it.
 */
class ContractTemplateController extends Controller
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly AuditLogger $audit,
    ) {}

    public function show(ContractTemplate $template, ContractRenderer $renderer, QuoteCalculator $quotes, SettingsService $settings): View
    {
        return view('admin.contracts.template-show', [
            'template' => $template,
            'preview' => $renderer->body($this->sampleContract($template, $quotes, $settings)),
        ]);
    }

    /** Start a new draft version from an existing template. */
    public function duplicate(Request $request, ContractTemplate $template): RedirectResponse
    {
        $existingDraft = ContractTemplate::where('type', $template->type)->whereNull('published_at')->first();
        if ($existingDraft) {
            return redirect()->route('admin.contracts.templates.edit', $existingDraft)
                ->with('status', 'A draft already exists for this agreement type. Continue editing it.');
        }

        $draft = ContractTemplate::create([
            'type' => $template->type,
            'version' => $this->nextVersion($template->type),
            'title' => $template->title,
            'body_html' => $template->body_html,
            'is_active' => false,
            'published_at' => null,
            'created_by' => $request->user('admin')->id,
        ]);
        $this->audit->log('template.drafted', "Started agreement template v{$draft->version}", $draft);

        return redirect()->route('admin.contracts.templates.edit', $draft);
    }

    public function edit(ContractTemplate $template): View|RedirectResponse
    {
        if ($template->isPublished()) {
            return redirect()->route('admin.contracts.templates.show', $template);
        }

        return view('admin.contracts.template-edit', [
            'template' => $template,
            'placeholders' => ContractRenderer::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request, ContractTemplate $template): RedirectResponse
    {
        abort_if($template->isPublished(), 403, 'Published templates can’t be edited. Create a new version.');

        $data = $request->validate([
            'version' => ['required', 'string', 'max:20', 'regex:/^\d+(\.\d+){0,2}$/', Rule::unique('contract_templates')->where('type', $template->type->value)->ignore($template->id)],
            'title' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:200000'],
        ], ['version.regex' => 'Use a version number like 1.1 or 2.0.']);

        $template->update([
            'version' => $data['version'],
            'title' => $data['title'],
            'body_html' => $this->sanitizer->clean($data['body_html']),
        ]);
        $this->audit->log('template.updated', "Edited draft agreement template v{$template->version}", $template);

        return $request->boolean('preview')
            ? redirect()->route('admin.contracts.templates.show', $template)
            : redirect()->route('admin.contracts.templates.edit', $template)->with('success', 'Draft saved.');
    }

    public function publish(ContractTemplate $template): RedirectResponse
    {
        abort_if($template->isPublished(), 403);

        DB::transaction(function () use ($template) {
            ContractTemplate::where('type', $template->type)->where('is_active', true)->update(['is_active' => false]);
            $template->update(['is_active' => true, 'published_at' => now()]);
        });
        $this->audit->log('template.published', "Published agreement template v{$template->version}", $template);

        return redirect()->to(route('admin.contracts.index').'#templates')
            ->with('success', "Version {$template->version} is now used for new agreements.");
    }

    public function destroy(ContractTemplate $template): RedirectResponse
    {
        abort_if($template->isPublished() || $template->contracts()->exists(), 403);
        $version = $template->version;
        $template->delete();
        $this->audit->log('template.deleted', "Deleted draft agreement template v{$version}");

        return redirect()->to(route('admin.contracts.index').'#templates')->with('success', "Draft v{$version} deleted.");
    }

    private function nextVersion(ContractType $type): string
    {
        $max = ContractTemplate::where('type', $type)->pluck('version')
            ->map(fn ($v) => (float) $v)->max() ?? 0;

        return number_format(floor($max * 10 + 1) / 10, 1, '.', '');
    }

    /** An unsaved agreement with sample client details, for previews. */
    private function sampleContract(ContractTemplate $template, QuoteCalculator $quotes, SettingsService $settings): Contract
    {
        $quote = $quotes->quote(8);
        $company = $settings->group('company');
        $sig = $settings->group('signature');

        $customer = new Customer([
            'first_name' => 'Maria', 'last_name' => 'Alvarez', 'title' => 'Managing Broker',
            'company_name' => 'Sunline Realty Group', 'email' => 'maria@example.com',
            'street' => '1200 Brickell Ave, Suite 410', 'city' => 'Miami', 'state_code' => 'FL', 'zip' => '33131',
        ]);
        $contract = new Contract([
            'number' => 'RA-SAMPLE',
            'setup_fee_cents' => $quote->setupFeeCents, 'coupon_code' => null, 'discount_percent' => 0, 'discount_cents' => 0,
            'implementation_fee_cents' => $quote->implementationFeeCents, 'deposit_percent' => $quote->depositPercent,
            'deposit_cents' => $quote->depositCents, 'balance_cents' => $quote->balanceCents,
            'platform_fee_cents' => $quote->platformFeeCents, 'per_agent_fee_cents' => $quote->perAgentFeeCents,
            'min_agents' => $quote->minAgents, 'agent_count' => $quote->agentsBilled, 'term_months' => 12,
            'company_legal_name' => $company['legal_name'], 'company_dba' => $company['dba'], 'company_address' => $company['address'],
            'company_signatory_name' => $sig['signatory_name'], 'company_signatory_title' => $sig['signatory_title'],
        ]);
        $contract->setRelation('customer', $customer);
        $contract->setRelation('template', $template);

        return $contract;
    }
}

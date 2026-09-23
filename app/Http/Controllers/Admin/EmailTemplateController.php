<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailRenderer;
use App\Services\Email\EmailSender;
use App\Services\Email\SampleEmailValues;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Automatic emails (sent by the system at set moments: editable, can be
 * switched off, not deletable) and custom emails (one-off messages sent
 * from a customer's page).
 */
class EmailTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $all = EmailTemplate::orderBy('id')->get();

        return view('admin.email-templates.index', [
            'system' => $all->where('is_system', true),
            'custom' => $all->where('is_system', false),
            'recent' => EmailLog::latest('id')->limit(15)->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.email-templates.edit', [
            'template' => new EmailTemplate(['is_system' => false, 'is_enabled' => true, 'cc_team' => false, 'body' => "Hi {first_name},\n\n"]),
            'placeholders' => EmailRenderer::PLACEHOLDERS,
            'preview' => null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $template = EmailTemplate::create($data + [
            'key' => 'custom-'.Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'is_system' => false,
            'trigger_description' => 'Sent manually from a customer page',
            'updated_by' => $request->user('admin')->id,
        ]);
        $this->audit->log('email_template.created', "Created custom email “{$template->name}”", $template);

        return redirect()->route('admin.email-templates.edit', $template)->with('success', 'Custom email created.');
    }

    public function edit(EmailTemplate $template, EmailRenderer $renderer): View
    {
        return view('admin.email-templates.edit', [
            'template' => $template,
            'placeholders' => EmailRenderer::PLACEHOLDERS,
            'preview' => $renderer->render($template, SampleEmailValues::all()),
        ]);
    }

    public function update(Request $request, EmailTemplate $template): RedirectResponse
    {
        $template->update($this->validated($request, ! $template->is_system) + ['updated_by' => $request->user('admin')->id]);
        $this->audit->log('email_template.updated', "Edited email “{$template->name}”", $template, ['changed' => array_keys($template->getChanges())]);

        return redirect()->route('admin.email-templates.edit', $template)->with('success', 'Email saved. The preview shows the saved version.');
    }

    public function reset(EmailTemplate $template): RedirectResponse
    {
        $default = EmailTemplateSeeder::templates()[$template->key] ?? null;
        abort_unless($template->is_system && $default, 404);
        [, , $cc, $subject, $body] = $default;
        $template->update(['subject' => $subject, 'body' => $body, 'cc_team' => $cc]);
        $this->audit->log('email_template.reset', "Reset email “{$template->name}” to the default", $template);

        return redirect()->route('admin.email-templates.edit', $template)->with('success', 'Restored the default wording.');
    }

    public function test(Request $request, EmailTemplate $template, EmailSender $sender): RedirectResponse
    {
        $to = $request->user('admin')->email;
        $log = $sender->test($template, $to, SampleEmailValues::all());
        $log->refresh();

        return match ($log->status) {
            'failed' => back()->with('warning', "The test email couldn’t be sent: {$log->error}"),
            'logged' => back()->with('warning', 'Brevo isn’t set up yet (Settings > Email), so the test was written to the application log instead of being sent.'),
            default => back()->with('success', "Test email sent to {$to}."),
        };
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        abort_if($template->is_system, 403, 'Automatic emails can’t be deleted. Switch them off instead.');
        $name = $template->name;
        $template->delete();
        $this->audit->log('email_template.deleted', "Deleted custom email “{$name}”");

        return redirect()->to(route('admin.email-templates.index').'#custom')->with('success', "Deleted “{$name}”.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $withName): array
    {
        $data = $request->validate(array_filter([
            'name' => $withName ? ['required', 'string', 'max:120'] : null,
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
        ]));

        return $data + ['cc_team' => $request->boolean('cc_team'), 'is_enabled' => $request->boolean('is_enabled')];
    }
}

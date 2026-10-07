<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\CustomerEraser;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SettingsSchema;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Settings tabs. Each tab saves on its own; validation rules come from
 * SettingsSchema so the form, the rules and the defaults can't drift apart.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        // Secret for the GoHighLevel appointments webhook URL, created on first visit.
        if (blank($this->settings->get('calls', 'ghl_webhook_secret'))) {
            $this->settings->setMany('calls', ['ghl_webhook_secret' => Str::random(40)]);
        }
        // Secret for the Brevo delivery webhook URL, created on first visit.
        if (blank($this->settings->get('email', 'brevo_webhook_token'))) {
            $this->settings->setMany('email', ['brevo_webhook_token' => Str::random(40)]);
        }
        $values = [];
        foreach (SettingsSchema::groups() as $group => $def) {
            foreach ($def['fields'] as $key => $field) {
                $values[$group][$key] = ($field['secret'] ?? false)
                    ? $this->settings->mask($group, $key)   // never send secrets to the browser
                    : $this->settings->get($group, $key);
            }
        }

        return view('admin.settings.index', [
            'ghlWebhookUrl' => route('ghl.appointments', $this->settings->get('calls', 'ghl_webhook_secret')),
            'brevoWebhookUrl' => route('brevo.webhook', $this->settings->get('email', 'brevo_webhook_token')),
            'groups' => SettingsSchema::groups(),
            'v' => $values,
            'signatureUrl' => $values['signature']['image_path'] ? route('admin.settings.signature') : null,
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        abort_unless(isset(SettingsSchema::groups()[$group]) && $group !== 'signature', 404);

        $fields = SettingsSchema::groups()[$group]['fields'];
        $rules = collect($fields)->map(fn ($f) => $f['rules'])->filter()->all();
        if ($group === 'tax') {
            $request->merge(['enabled' => $request->boolean('enabled') ? '1' : '0']);
        }
        if ($group === 'pricing') {
            $request->merge(['require_coupon' => $request->boolean('require_coupon') ? '1' : '0', 'annual_enabled' => $request->boolean('annual_enabled') ? '1' : '0', 'annual_discount_percent' => $request->input('annual_discount_percent', '10') ?? '10']);
        }
        if ($group === 'calls') {
            $request->merge(['enabled' => $request->boolean('enabled') ? '1' : '0']);
        }
        if ($group === 'alerts') {
            foreach (['email', 'new_signing', 'new_lead', 'follow_ups', 'payment_failed', 'go_lives', 'chargebacks'] as $flag) {
                $request->merge([$flag => $request->boolean($flag) ? '1' : '0']);
            }
        }
        if ($group === 'stripe' && $request->input('mode') === 'live') {
            $this->requireLiveKeys($request);
        }

        $data = $request->validateWithBag($group, $rules, [
            '*.starts_with' => 'This doesn’t look like the right kind of key. Check you copied the matching key from Stripe.',
        ]);

        $changed = $this->settings->setMany($group, $data, $request->user('admin')->id);
        $this->logChanges($group, $changed);

        return redirect()->to(route('admin.settings.index').'#t-'.$group)
            ->with('success', $changed ? SettingsSchema::groups()[$group]['label'].' settings saved.' : 'No changes to save.');
    }

    public function updateSignature(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('signature', [
            'signatory_name' => ['required', 'string', 'max:120'],
            'signatory_title' => ['required', 'string', 'max:120'],
            'style' => ['required', 'in:font,image'],
            'image' => ['nullable', 'required_if:style,image', 'file', 'mimes:png', 'max:1024', 'dimensions:min_width=300'],
        ], ['image.required_if' => 'Upload a PNG of the signature, or choose Name in signature font.']);

        if ($request->hasFile('image')) {
            $old = $this->settings->get('signature', 'image_path');
            $path = $request->file('image')->store('signatures', 'local'); // private disk
            $this->settings->set('signature', 'image_path', $path, $request->user('admin')->id);
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        } elseif ($data['style'] === 'image' && ! $this->settings->get('signature', 'image_path')) {
            return redirect()->to(route('admin.settings.index').'#t-signature')
                ->withErrors(['image' => 'Upload a PNG of the signature.'], 'signature');
        }

        unset($data['image']);
        $changed = $this->settings->setMany('signature', $data, $request->user('admin')->id);
        $this->logChanges('signature', $request->hasFile('image') ? [...$changed, 'image_path'] : $changed);

        return redirect()->to(route('admin.settings.index').'#t-signature')->with('success', 'Signature settings saved.');
    }

    /** Stream the stored signature image to admins (it is not public). */
    public function signature()
    {
        $path = $this->settings->get('signature', 'image_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, 'signature.png', ['Cache-Control' => 'private, max-age=300']);
    }

    public function clearStripeLive(Request $request): RedirectResponse
    {
        abort_if($this->settings->get('stripe', 'mode') === 'live', 422, 'Switch to test mode before removing live keys.');
        foreach (['live_publishable_key', 'live_secret_key', 'live_webhook_secret'] as $key) {
            $this->settings->forget('stripe', $key);
        }
        $this->audit->log('settings.stripe_live_cleared', 'Removed Stripe live keys');

        return redirect()->to(route('admin.settings.index').'#t-stripe')->with('success', 'Live keys removed.');
    }

    /** Going live without complete live keys would break every payment. */
    private function requireLiveKeys(Request $request): void
    {
        $missing = collect(['live_publishable_key', 'live_secret_key', 'live_webhook_secret'])
            ->filter(fn ($k) => ! $request->filled($k) && ! $this->settings->get('stripe', $k));

        if ($missing->isNotEmpty()) {
            throw new HttpResponseException(
                redirect()->to(route('admin.settings.index').'#t-stripe')
                    ->withErrors(['mode' => 'Add all three live keys before switching to live mode.'])
            );
        }
    }

    /** @param array<int, string> $changed */
    private function logChanges(string $group, array $changed): void
    {
        if (! $changed) {
            return;
        }
        $shown = [];
        foreach ($changed as $key) {
            $shown[$key] = SettingsSchema::isSecret($group, $key) ? '[hidden]' : $this->settings->get($group, $key);
        }
        $this->audit->log("settings.{$group}", 'Changed '.SettingsSchema::groups()[$group]['label'].' settings', null, $shown);
    }

    /** Super admins, Stripe test mode only: remove every customer and their data before going live. */
    public function resetData(Request $request, CustomerEraser $eraser, StripeClient $stripe): RedirectResponse
    {
        abort_unless($request->user('admin')->isSuperAdmin(), 403);
        if ($stripe->mode() !== 'test') {
            return redirect()->to(route('admin.settings.index').'#t-reset')->withErrors(['reset' => 'Resetting is only allowed while Stripe is in test mode.'], 'reset');
        }
        $request->validateWithBag('reset', [
            'confirm' => ['required', 'in:RESET'],
            'password' => ['required', 'current_password:admin'],
        ], ['confirm.in' => 'Type RESET in capitals.', 'password.current_password' => 'That password is incorrect.']);

        $count = $eraser->resetAll();
        $this->audit->log('data.reset', "Reset all client data ({$count} customers removed)");

        return redirect()->to(route('admin.settings.index').'#t-reset')->with('success', "Done. {$count} customers and all their agreements, invoices, payments, emails and history were removed. Admins, settings, templates and coupons are unchanged.");
    }
}

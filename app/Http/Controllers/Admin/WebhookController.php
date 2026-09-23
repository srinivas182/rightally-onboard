<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Audit\AuditLogger;
use App\Services\Integrations\Webhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Outgoing webhook endpoints (Settings > Alerts and integrations). */
class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $secret = 'whsec_ra_'.Str::random(32);
        $endpoint = WebhookEndpoint::create($data + ['secret' => $secret, 'is_active' => true]);
        $this->audit->log('webhook.created', "Added webhook “{$endpoint->name}”", $endpoint, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        return $this->back()->with('success', "Webhook added. Its signing secret (shown once): {$secret}");
    }

    public function update(Request $request, WebhookEndpoint $endpoint): RedirectResponse
    {
        $endpoint->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);
        $this->audit->log('webhook.updated', "Updated webhook “{$endpoint->name}”", $endpoint);

        return $this->back()->with('success', 'Webhook saved.');
    }

    public function destroy(WebhookEndpoint $endpoint): RedirectResponse
    {
        $name = $endpoint->name;
        $endpoint->delete();
        $this->audit->log('webhook.deleted', "Deleted webhook “{$name}”");

        return $this->back()->with('success', "Webhook “{$name}” deleted.");
    }

    public function test(WebhookEndpoint $endpoint, Webhooks $webhooks): RedirectResponse
    {
        $d = $webhooks->test($endpoint)->fresh();

        return $this->back()->with($d->status === 'delivered' ? 'success' : 'warning', $d->status === 'delivered'
            ? "Test delivered to {$endpoint->name} (HTTP {$d->response_code})."
            : "Test queued for {$endpoint->name}. ".($d->last_error ? "Last error: {$d->last_error}" : 'Check Recent deliveries in a minute.'));
    }

    public function retry(WebhookDelivery $delivery): RedirectResponse
    {
        $delivery->update(['status' => 'pending', 'attempts' => 0]);
        DeliverWebhook::dispatch($delivery->id);

        return $this->back()->with('success', 'Delivery retried.');
    }

    private function validated(Request $request): array
    {
        return $request->validateWithBag('webhook', [
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url:https', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(array_keys(Webhooks::EVENTS))],
        ], ['url.url' => 'Enter a full https:// address.', 'events.required' => 'Choose at least one event.']);
    }

    private function back(): RedirectResponse
    {
        return redirect()->to(route('admin.settings.index').'#t-alerts');
    }
}

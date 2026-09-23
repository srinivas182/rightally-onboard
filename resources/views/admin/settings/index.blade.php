@extends('layouts.admin')
@section('title', 'Settings')
@section('menu', 'settings')
@section('content')
<h1 class="h3 mb-4">Settings</h1>
<ul class="nav nav-tabs tabs-scroll mb-4" role="tablist" data-remember-tab="settings">
    @foreach (['company' => 'Company', 'pricing' => 'Pricing', 'renewal' => 'Renewal pricing', 'signature' => 'Signature', 'stripe' => 'Stripe', 'email' => 'Email', 'security' => 'Security', 'alerts' => 'Alerts and integrations', 'tax' => 'Tax', 'legal' => 'Legal pages'] as $tab => $label)
        <li class="nav-item"><a class="nav-link {{ $loop->first ? 'active' : '' }}" data-bs-toggle="tab" href="#t-{{ $tab }}">{{ $label }}</a></li>
    @endforeach
</ul>

<div class="tab-content panel p-3 p-md-4" style="max-width:860px">

    {{-- Company --}}
    <div class="tab-pane fade show active" id="t-company" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'company') }}">@csrf @method('put')
            <p class="text-slate">Shown on every agreement, invoice and email footer.</p>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'company', 'key' => 'legal_name', 'label' => 'Legal name', 'value' => $v['company']['legal_name']])
                @include('admin.settings._field', ['group' => 'company', 'key' => 'dba', 'label' => 'Doing business as', 'value' => $v['company']['dba']])
                @include('admin.settings._field', ['group' => 'company', 'key' => 'address', 'label' => 'Registered address', 'value' => $v['company']['address'], 'col' => 'col-12'])
                @include('admin.settings._field', ['group' => 'company', 'key' => 'phone', 'label' => 'Phone', 'value' => $v['company']['phone'], 'type' => 'tel'])
                @include('admin.settings._field', ['group' => 'company', 'key' => 'support_email', 'label' => 'Support email', 'value' => $v['company']['support_email'], 'type' => 'email'])
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save company details</button>
        </form>
    </div>

    {{-- Pricing --}}
    <div class="tab-pane fade" id="t-pricing" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'pricing') }}">@csrf @method('put')
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'setup_fee', 'label' => 'Set-up fee', 'value' => $v['pricing']['setup_fee'], 'prefix' => '$', 'type' => 'number', 'step' => '0.01', 'col' => 'col-sm-4'])
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'deposit_percent', 'label' => 'Deposit', 'value' => $v['pricing']['deposit_percent'], 'suffix' => '%', 'type' => 'number', 'step' => '0.01', 'col' => 'col-sm-4'])
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'go_live_days', 'label' => 'Go-live after', 'value' => $v['pricing']['go_live_days'], 'suffix' => 'days', 'type' => 'number', 'col' => 'col-sm-4'])
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'platform_fee', 'label' => 'Platform fee per month', 'value' => $v['pricing']['platform_fee'], 'prefix' => '$', 'type' => 'number', 'step' => '0.01', 'col' => 'col-sm-4'])
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'per_agent_fee', 'label' => 'Per agent per month', 'value' => $v['pricing']['per_agent_fee'], 'prefix' => '$', 'type' => 'number', 'step' => '0.01', 'col' => 'col-sm-4'])
                @include('admin.settings._field', ['group' => 'pricing', 'key' => 'min_agents', 'label' => 'Minimum agents billed', 'value' => $v['pricing']['min_agents'], 'type' => 'number', 'col' => 'col-sm-4'])
            </div>
            <div class="form-text mt-3">Changes apply to new agreements only. Signed agreements keep the prices they were signed with.</div>
            <button class="btn btn-primary mt-4" type="submit">Save pricing</button>
        </form>
    </div>

    {{-- Renewal --}}
    <div class="tab-pane fade" id="t-renewal" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'renewal') }}">@csrf @method('put')
            <p class="text-slate">Used for renewal agreements. Leave blank to renew each customer at their original contract rates. There is no set-up fee on renewal.</p>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'renewal', 'key' => 'platform_fee', 'label' => 'Renewal platform fee per month', 'value' => $v['renewal']['platform_fee'], 'prefix' => '$', 'type' => 'number', 'step' => '0.01', 'placeholder' => 'Original rate'])
                @include('admin.settings._field', ['group' => 'renewal', 'key' => 'per_agent_fee', 'label' => 'Renewal per agent per month', 'value' => $v['renewal']['per_agent_fee'], 'prefix' => '$', 'type' => 'number', 'step' => '0.01', 'placeholder' => 'Original rate'])
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save renewal pricing</button>
        </form>
    </div>

    {{-- Signature --}}
    <div class="tab-pane fade" id="t-signature" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.signature.update') }}" enctype="multipart/form-data">@csrf
            <p class="text-slate">Applied automatically to RightAlly’s side of every agreement. Nobody at RightAlly needs to sign each one.</p>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'signature', 'key' => 'signatory_name', 'label' => 'Signatory name', 'value' => $v['signature']['signatory_name']])
                @include('admin.settings._field', ['group' => 'signature', 'key' => 'signatory_title', 'label' => 'Title', 'value' => $v['signature']['signatory_title']])
                <div class="col-12"><span class="form-label d-block">Signature style</span>
                    <div class="d-flex gap-4 flex-wrap">
                        <div class="form-check"><input class="form-check-input" type="radio" name="style" id="sgF" value="font" @checked(old('style', $v['signature']['style']) === 'font')><label class="form-check-label" for="sgF">Name in signature font</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="style" id="sgI" value="image" @checked(old('style', $v['signature']['style']) === 'image')><label class="form-check-label" for="sgI">Uploaded image</label></div>
                    </div></div>
                <div class="col-12"><label class="form-label" for="image">Signature image <span class="text-slate">(PNG, transparent background, at least 300 px wide)</span></label>
                    <input type="file" class="form-control @error('image', 'signature') is-invalid @enderror" id="image" name="image" accept="image/png">
                    @error('image', 'signature')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-12"><div class="co-sig"><div class="small text-slate">Current signature</div>
                    @if ($v['signature']['style'] === 'image' && $signatureUrl)
                        <img src="{{ $signatureUrl }}" alt="Signature" style="max-height:64px;max-width:260px">
                    @else
                        <div class="sig-font" style="color:#0839B2">{{ $v['signature']['signatory_name'] }}</div>
                    @endif
                    <div class="small">{{ $v['signature']['signatory_name'] }}, {{ $v['signature']['signatory_title'] }}</div></div></div>
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save signature</button>
        </form>
    </div>

    {{-- Stripe --}}
    <div class="tab-pane fade" id="t-stripe" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'stripe') }}">@csrf @method('put')
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                <div><b>Mode</b><div class="small text-slate">Test mode uses test keys. No real money moves.</div></div>
                <div class="btn-group" role="group" aria-label="Stripe mode">
                    <input type="radio" class="btn-check" name="mode" id="mT" value="test" @checked(old('mode', $v['stripe']['mode']) === 'test')><label class="btn btn-outline-warning" for="mT">Test</label>
                    <input type="radio" class="btn-check" name="mode" id="mL" value="live" @checked(old('mode', $v['stripe']['mode']) === 'live')><label class="btn btn-outline-success" for="mL">Live</label>
                </div>
            </div>
            @error('mode')<div class="alert alert-danger small">{{ $message }}</div>@enderror
            <h3 class="h6 mt-4">Test keys</h3>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'test_publishable_key', 'label' => 'Publishable key', 'value' => $v['stripe']['test_publishable_key'], 'col' => 'col-12', 'placeholder' => 'pk_test_…'])
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'test_secret_key', 'label' => 'Secret key', 'value' => $v['stripe']['test_secret_key'], 'col' => 'col-12', 'secret' => true])
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'test_webhook_secret', 'label' => 'Webhook signing secret', 'value' => $v['stripe']['test_webhook_secret'], 'col' => 'col-12', 'secret' => true])
            </div>
            <h3 class="h6 mt-4">Live keys</h3>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'live_publishable_key', 'label' => 'Publishable key', 'value' => $v['stripe']['live_publishable_key'], 'col' => 'col-12', 'placeholder' => 'pk_live_…'])
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'live_secret_key', 'label' => 'Secret key', 'value' => $v['stripe']['live_secret_key'], 'col' => 'col-12', 'secret' => true])
                @include('admin.settings._field', ['group' => 'stripe', 'key' => 'live_webhook_secret', 'label' => 'Webhook signing secret', 'value' => $v['stripe']['live_webhook_secret'], 'col' => 'col-12', 'secret' => true])
            </div>
            <div class="form-text mt-3">Secret keys are stored encrypted and never shown again, only their last 4 characters.</div>
            <div class="fee-mini mt-3 small">
                <b>Webhook</b>: in the Stripe dashboard (Developers &gt; Webhooks), add an endpoint for each mode:
                <div class="input-group input-group-sm my-2" style="max-width:520px"><input class="form-control font-monospace" value="{{ route('stripe.webhook') }}" readonly aria-label="Webhook URL"><button class="btn btn-outline-secondary" type="button" data-copy="{{ route('stripe.webhook') }}">Copy</button></div>
                Events: <span class="font-monospace">{{ implode(', ', \App\Services\Stripe\StripeEventHandler::TYPES) }}</span>. Paste the endpoint’s signing secret above.
            </div>
            <div class="d-flex gap-2 mt-4 flex-wrap">
                <button class="btn btn-primary" type="submit">Save Stripe settings</button>
                @if ($v['stripe']['live_secret_key'] && $v['stripe']['mode'] === 'test')
                    <button class="btn btn-outline-danger" type="submit" form="clearLive" data-confirm="Remove the live Stripe keys?">Remove live keys</button>
                @endif
            </div>
        </form>
        <form id="clearLive" method="post" action="{{ route('admin.settings.stripe.clear-live') }}">@csrf @method('delete')</form>
    </div>

    {{-- Email --}}
    <div class="tab-pane fade" id="t-email" role="tabpanel">
        <div class="alert alert-light border small mb-4"><b>Delivery tracking.</b> In Brevo: Transactional &gt; Settings &gt; Webhook, add this URL and tick Delivered, Opened, Hard bounce, Soft bounce, Blocked, Spam, Invalid email and Error. Bounces then show on the customer page and dashboard.
            <div class="input-group input-group-sm mt-2"><input class="form-control font-monospace" value="{{ $brevoWebhookUrl }}" readonly aria-label="Brevo webhook URL"><button class="btn btn-outline-secondary" type="button" data-copy="{{ $brevoWebhookUrl }}">Copy</button></div></div>
        <form method="post" action="{{ route('admin.settings.update', 'email') }}">@csrf @method('put')
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'email', 'key' => 'brevo_api_key', 'label' => 'Brevo API key', 'value' => $v['email']['brevo_api_key'], 'col' => 'col-12', 'secret' => true])
                @include('admin.settings._field', ['group' => 'email', 'key' => 'from_name', 'label' => 'From name', 'value' => $v['email']['from_name']])
                @include('admin.settings._field', ['group' => 'email', 'key' => 'from_email', 'label' => 'From email', 'value' => $v['email']['from_email'], 'type' => 'email', 'help' => 'Must be a sender verified in Brevo.'])
                @include('admin.settings._field', ['group' => 'email', 'key' => 'team_cc', 'label' => 'Team CC addresses', 'value' => $v['email']['team_cc'], 'col' => 'col-12', 'help' => 'Comma-separated. Each email template chooses whether to CC the team.'])
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save email settings</button>
        </form>
    </div>

    {{-- Security --}}
    <div class="tab-pane fade" id="t-security" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'security') }}">@csrf @method('put')
            <p class="text-slate">Cloudflare Turnstile stops bots submitting the onboarding form. It’s off until both keys are saved. Get the keys from the Cloudflare dashboard under Turnstile, and add your onboarding domain to the widget.</p>
            <div class="row g-3">
                @include('admin.settings._field', ['group' => 'security', 'key' => 'turnstile_site_key', 'label' => 'Turnstile site key', 'value' => $v['security']['turnstile_site_key'], 'col' => 'col-12'])
                @include('admin.settings._field', ['group' => 'security', 'key' => 'turnstile_secret_key', 'label' => 'Turnstile secret key', 'value' => $v['security']['turnstile_secret_key'], 'col' => 'col-12', 'secret' => true])
            </div>
            <button class="btn btn-primary mt-4" type="submit">Save security settings</button>
        </form>
    </div>

    {{-- Alerts and integrations --}}
    <div class="tab-pane fade" id="t-alerts" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'alerts') }}">@csrf @method('put')
            <h3 class="h6">Team alerts</h3>
            <p class="text-slate small">Sent to the team CC addresses (Settings &gt; Email) and/or a Slack channel.</p>
            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" id="al_email" name="email" value="1" @checked($v['alerts']['email'] === '1')><label class="form-check-label" for="al_email">Send alerts by email</label></div>
            <div class="row g-2 mb-3">
                @foreach (\App\Services\Integrations\TeamAlerts::KINDS as $k => $label)
                    <div class="col-sm-6"><div class="form-check"><input class="form-check-input" type="checkbox" id="al_{{ $k }}" name="{{ $k }}" value="1" @checked($v['alerts'][$k] === '1')><label class="form-check-label" for="al_{{ $k }}">{{ $label }}</label></div></div>
                @endforeach
            </div>
            @include('admin.settings._field', ['group' => 'alerts', 'key' => 'slack_webhook_url', 'label' => 'Slack incoming webhook URL', 'value' => $v['alerts']['slack_webhook_url'], 'col' => 'col-12', 'secret' => true, 'placeholder' => 'https://hooks.slack.com/services/…', 'help' => 'Optional. In Slack: Apps > Incoming Webhooks > Add to a channel, then paste the URL here.'])
            <button class="btn btn-primary mt-3" type="submit">Save alerts</button>
        </form>

        <hr class="my-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h3 class="h6 mb-0">Webhooks (CRM, Zapier)</h3>
            <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#webhookNew"><svg class="ic me-1" aria-hidden="true"><use href="#i-plus"/></svg>Add webhook</button>
        </div>
        <p class="text-slate small">We POST a signed JSON event to each address when something happens to a customer. For GoHighLevel or any CRM, use a Zapier “Catch Hook” URL or the CRM’s inbound webhook. Customers’ own RightAlly sites are notified automatically about live, suspended, reactivated, cancelled and ended accounts (see docs/integration-rightally-sites.md).</p>
        @php $endpoints = \App\Models\WebhookEndpoint::orderBy('name')->get(); @endphp
        @forelse ($endpoints as $ep)
            <div class="border rounded p-3 mb-2">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div><b>{{ $ep->name }}</b> @if ($ep->is_active)<span class="st st-live">On</span>@else<span class="st st-draft">Off</span>@endif
                        <div class="small text-slate text-break">{{ $ep->url }}</div>
                        <div class="small text-slate">{{ implode(', ', $ep->events) }}</div></div>
                    <div class="d-flex gap-2">
                        <form method="post" action="{{ route('admin.webhooks.test', $ep) }}">@csrf<button class="btn btn-sm btn-outline-secondary">Send test</button></form>
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#webhook{{ $ep->id }}">Edit</button>
                        <form method="post" action="{{ route('admin.webhooks.destroy', $ep) }}">@csrf @method('delete')<button class="btn btn-sm btn-outline-danger" data-confirm="Delete this webhook?">Delete</button></form>
                    </div>
                </div>
            </div>
        @empty
            <div class="text-slate small mb-2">No webhooks yet.</div>
        @endforelse

        @php $recent = \App\Models\WebhookDelivery::latest('id')->limit(15)->get(); @endphp
        @if ($recent->isNotEmpty())
            <h3 class="h6 mt-4">Recent deliveries</h3>
            <div class="table-responsive"><table class="table table-sm small">
                <thead><tr><th>When</th><th>Event</th><th>To</th><th>Status</th><th></th></tr></thead>
                <tbody>@foreach ($recent as $d)
                    <tr><td class="text-nowrap">{{ $d->created_at->setTimezone(\App\Support\BusinessClock::timezone())->format('M j, g:i A') }}</td><td class="font-monospace">{{ $d->event }}</td>
                        <td class="text-break" style="max-width:260px">{{ $d->url }}</td>
                        <td><span class="st {{ ['delivered' => 'st-live', 'pending' => 'st-wait', 'failed' => 'st-fail'][$d->status] ?? 'st-draft' }}">{{ ucfirst($d->status) }}</span>@if ($d->last_error)<div class="text-slate">{{ Str::limit($d->last_error, 80) }}</div>@endif</td>
                        <td class="text-end">@if ($d->status === 'failed')<form method="post" action="{{ route('admin.webhooks.retry', $d) }}">@csrf<button class="btn btn-sm btn-link p-0">Retry</button></form>@endif</td></tr>
                @endforeach</tbody>
            </table></div>
        @endif
    </div>

    {{-- Legal pages --}}
    <div class="tab-pane fade" id="t-legal" role="tabpanel">
        <p class="text-slate">Shown in the footer of every onboarding page and email. Drafts are provided for your attorney to review.</p>
        @foreach (\App\Models\LegalPage::orderBy('slug')->get() as $lp)
            <div class="d-flex justify-content-between align-items-center border-bottom py-2"><div><b>{{ $lp->title }}</b><div class="small text-slate">Last saved {{ $lp->updated_at->format('M j, Y') }}</div></div>
                <div class="d-flex gap-2"><a class="btn btn-sm btn-outline-secondary" href="{{ route('legal', $lp->slug) }}" target="_blank" rel="noopener">View</a><a class="btn btn-sm btn-primary" href="{{ route('admin.legal.edit', $lp) }}">Edit</a></div></div>
        @endforeach
    </div>

    {{-- Tax --}}
    <div class="tab-pane fade" id="t-tax" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'tax') }}">@csrf @method('put')
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tx" name="enabled" value="1" @checked($v['tax']['enabled'] === '1')><label class="form-check-label" for="tx">Charge sales tax with Stripe Tax</label></div>
            <div class="form-text">Off by default. When on, Stripe Tax adds sales tax on top of every charge (deposit, go-live balance, monthly fees, early termination), calculated from the client’s address, and records it for your tax reports. Before switching on: add your tax registrations in Stripe (Tax &gt; Registrations) and confirm with your accountant where RightAlly must collect tax. Existing monthly subscriptions keep their current setting.</div>
            <button class="btn btn-primary mt-4" type="submit">Save tax setting</button>
        </form>
    </div>
</div>
@php $whEvents = \App\Services\Integrations\Webhooks::EVENTS; @endphp
@foreach (array_merge([null], \App\Models\WebhookEndpoint::orderBy('name')->get()->all()) as $ep)
<div class="modal fade" id="{{ $ep ? 'webhook'.$ep->id : 'webhookNew' }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ $ep ? route('admin.webhooks.update', $ep) : route('admin.webhooks.store') }}">@csrf @if ($ep) @method('put') @endif
        <div class="modal-header"><h2 class="modal-title h5">{{ $ep ? 'Edit webhook' : 'Add webhook' }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="120" value="{{ $ep?->name }}" placeholder="e.g. GoHighLevel via Zapier"></div>
            <div class="mb-3"><label class="form-label">URL</label><input class="form-control" name="url" required maxlength="500" value="{{ $ep?->url }}" placeholder="https://hooks.zapier.com/hooks/catch/…"></div>
            <span class="form-label d-block">Events</span>
            @foreach ($whEvents as $k => $label)
                <div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="{{ $k }}" id="ev{{ $ep?->id ?? 'n' }}_{{ $loop->index }}" @checked($ep ? in_array($k, $ep->events, true) : true)><label class="form-check-label" for="ev{{ $ep?->id ?? 'n' }}_{{ $loop->index }}">{{ $label }} <span class="text-slate font-monospace small">{{ $k }}</span></label></div>
            @endforeach
            @if ($ep)<div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="act{{ $ep->id }}" @checked($ep->is_active)><label class="form-check-label" for="act{{ $ep->id }}">Active</label></div>@endif
            @if ($errors->webhook->any())<div class="alert alert-danger small mt-3 mb-0">{{ $errors->webhook->first() }}</div>@endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save webhook</button></div>
    </form></div></div>
@endforeach
@endsection

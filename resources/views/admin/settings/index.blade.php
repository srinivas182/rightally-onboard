@extends('layouts.admin')
@section('title', 'Settings')
@section('menu', 'settings')
@section('content')
<h1 class="h3 mb-4">Settings</h1>
<ul class="nav nav-tabs tabs-scroll mb-4" role="tablist" data-remember-tab="settings">
    @foreach (['company' => 'Company', 'pricing' => 'Pricing', 'renewal' => 'Renewal pricing', 'signature' => 'Signature', 'stripe' => 'Stripe', 'email' => 'Email', 'security' => 'Security', 'tax' => 'Tax'] as $tab => $label)
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
            <div class="form-text mt-3">Secret keys are stored encrypted and never shown again, only their last 4 characters. The connection test and webhook address arrive in Sprint 3.</div>
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

    {{-- Tax --}}
    <div class="tab-pane fade" id="t-tax" role="tabpanel">
        <form method="post" action="{{ route('admin.settings.update', 'tax') }}">@csrf @method('put')
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="tx" name="enabled" value="1" @checked($v['tax']['enabled'] === '1')><label class="form-check-label" for="tx">Charge sales tax with Stripe Tax</label></div>
            <div class="form-text">Off by default. When on, Stripe calculates tax from each customer’s address. Your Stripe Tax registrations must be set up in Stripe first.</div>
            <button class="btn btn-primary mt-4" type="submit">Save tax setting</button>
        </form>
    </div>
</div>
@endsection

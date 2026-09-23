@extends('layouts.onboarding')
@section('title', 'Your details')
@if ($turnstileSiteKey)
    @push('head')<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@endif
@section('content')
@php $v = fn (string $key, $fallback = '') => old($key, $customer?->{$key} ?? ($prefill[$key] ?? $fallback)); @endphp
<p class="step-kicker">Step 1 of 5</p>
<h1>{{ $customer ? 'Update your details' : 'Let’s set up your brokerage on RightAlly' }}</h1>
<p class="lead">Tell us who is signing and where your business is based. These details go on your agreement.</p>

@include('partials.flash', ['hideErrorSummary' => false])
@if ($quoteProblem ?? null)<div class="alert alert-warning">{{ $quoteProblem }}</div>@endif
@if ($customQuote)
    <div class="alert alert-info d-flex gap-2 align-items-start">
        <svg class="ic mt-1" aria-hidden="true"><use href="#i-tag"/></svg>
        <div><b>Your custom quote is applied.</b> This pricing was prepared for you and is valid until {{ $customQuote->expires_at->setTimezone(\App\Support\BusinessClock::timezone())->format('F j, Y') }}.@if ($customQuote->note)<div class="small mt-1">{{ $customQuote->note }}</div>@endif</div>
    </div>
@endif

<form method="post" action="{{ $action }}" novalidate id="detailsForm"
      data-pricing='@json($quote->forBrowser())' data-coupon-url="{{ route('onboarding.coupon') }}">
    @csrf
    @if ($method === 'put') @method('put') @endif

    <fieldset class="mb-4"><legend>About you</legend>
        <div class="row g-3">
            <div class="col-sm-6"><label class="form-label" for="first_name">First name</label>
                <input class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" value="{{ $v('first_name') }}" required autocomplete="given-name" maxlength="80">
                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="last_name">Last name</label>
                <input class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" value="{{ $v('last_name') }}" required autocomplete="family-name" maxlength="80">
                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="title">Title</label>
                <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ $v('title') }}" required placeholder="e.g. Owner, Managing Broker" autocomplete="organization-title" maxlength="80">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="company_name">Brokerage or company name</label>
                <input class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" value="{{ $v('company_name') }}" required autocomplete="organization" maxlength="160">
                @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="email">Email</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ $v('email') }}" required autocomplete="email" maxlength="160">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">Your signed agreement and receipts go here.</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="phone">Phone</label>
                <div class="input-group has-validation"><span class="input-group-text">+1</span>
                    <input type="tel" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $phone) }}" required autocomplete="tel-national" inputmode="tel" placeholder="(305) 555-0148" maxlength="30">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div></div>
        </div>
    </fieldset>

    <fieldset class="mb-4"><legend>Your team</legend>
        <div class="row g-3 align-items-start">
            <div class="col-sm-6"><label class="form-label" for="agents">Number of agents</label>
                <div class="input-group has-validation">
                    <button class="btn btn-outline-secondary" type="button" data-agstep="-1" aria-label="Fewer agents">−</button>
                    <input type="number" class="form-control text-center @error('agents') is-invalid @enderror" id="agents" name="agents" min="1" max="5000" value="{{ $agents }}" inputmode="numeric" required>
                    <button class="btn btn-outline-secondary" type="button" data-agstep="1" aria-label="More agents">+</button>
                    @error('agents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text" id="agHelp" data-min="{{ $quote->minAgents }}">Agents who will use RightAlly. You can change this later.</div></div>
            <div class="col-sm-6"><div class="fee-mini"><div class="small text-slate" data-l="periodfee">{{ $quote->billingInterval === 'year' ? 'Your yearly fee' : 'Your monthly fee' }}</div>
                <div class="fs-4 fw-semibold num" data-l="monthly">{{ \App\Support\Money::format($quote->billingInterval === 'year' ? $quote->annualFeeCents() : $quote->monthlyFeeCents()) }}</div>
                <div class="small text-slate">{{ \App\Support\Money::format($quote->platformFeeCents) }} platform + {{ \App\Support\Money::format($quote->perAgentFeeCents) }} × <span data-l="agents">{{ $quote->agentsBilled }}</span> agents</div></div></div>
        </div>
    </fieldset>

    <fieldset class="mb-4"><legend>Business address</legend>
        <div class="row g-3">
            <div class="col-12"><label class="form-label" for="street">Street address</label>
                <input class="form-control @error('street') is-invalid @enderror" id="street" name="street" value="{{ $v('street') }}" required autocomplete="street-address" maxlength="255">
                @error('street')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="city">City</label>
                <input class="form-control @error('city') is-invalid @enderror" id="city" name="city" value="{{ $v('city') }}" required autocomplete="address-level2" maxlength="100">
                @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="state_code">State</label>
                <select class="form-select @error('state_code') is-invalid @enderror" id="state_code" name="state_code" required autocomplete="address-level1">
                    <option value="">Choose a state</option>
                    @foreach ($states as $code => $name)<option value="{{ $code }}" @selected($v('state_code') === $code)>{{ $name }}</option>@endforeach
                </select>
                @error('state_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="zip">ZIP code</label>
                <input class="form-control @error('zip') is-invalid @enderror" id="zip" name="zip" value="{{ $v('zip') }}" required inputmode="numeric" autocomplete="postal-code" maxlength="10">
                @error('zip')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="country">Country</label>
                <input class="form-control locked" id="country" value="United States" readonly aria-readonly="true"></div>
        </div>
    </fieldset>

    @if ($quote->annualAvailable)
    <fieldset class="mb-4"><legend>Subscription billing</legend>
        <div class="row g-2" role="radiogroup">
            <div class="col-sm-6"><label class="choice h-100 {{ $quote->billingInterval === 'month' ? 'is-on' : '' }}"><input class="form-check-input me-2" type="radio" name="billing" value="month" @checked($quote->billingInterval === 'month')>
                <span><b>Monthly</b><span class="d-block small text-slate" data-billing-note="month">Pay each month</span></span></label></div>
            <div class="col-sm-6"><label class="choice h-100 {{ $quote->billingInterval === 'year' ? 'is-on' : '' }}"><input class="form-check-input me-2" type="radio" name="billing" value="year" @checked($quote->billingInterval === 'year')>
                <span><b>Yearly</b> <span class="badge text-bg-success">Save {{ rtrim(rtrim(number_format($quote->annualDiscountPercent, 2), '0'), '.') }}%</span><span class="d-block small text-slate" data-billing-note="year">Pay 12 months in advance</span></span></label></div>
        </div>
    </fieldset>
    @endif

    @unless ($customQuote)
    <fieldset class="mb-4"><legend>Coupon <span class="fw-normal text-slate">(optional)</span></legend>
        <div class="input-group has-validation" style="max-width:420px">
            <input class="form-control text-uppercase @error('coupon') is-invalid @enderror" id="coupon" name="coupon" value="{{ $couponCode }}" placeholder="Enter code" aria-describedby="couponMsg" maxlength="40" autocomplete="off">
            <button class="btn btn-outline-primary" type="button" id="couponApply">Apply</button>
        </div>
        @php
            $msg = $errors->first('coupon') ?: $couponMessage;
            $ok = ! $msg && $quote->coupon;
        @endphp
        <div id="couponMsg" class="mt-2 small {{ $msg ? 'text-danger' : ($ok ? 'coupon-ok' : 'text-slate') }}" aria-live="polite">
            @if ($msg){{ $msg }}@elseif ($ok){{ $quote->coupon->code }} applied{{ $fromLink ? ' from your link' : '' }}. {{ rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.') }}% off your implementation fee.@endif
        </div>
    </fieldset>
    @endunless

    @if ($turnstileSiteKey)
        <div class="mb-3">
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="auto"></div>
            @error('turnstile')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    @endif

    <p class="small text-slate">By continuing you agree to our <a href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">Terms of Use</a> and <a href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">Privacy Policy</a>.</p>
    <div class="d-flex flex-column flex-sm-row gap-2">
        <button class="btn btn-primary btn-lg px-5" type="submit">{{ $customer ? 'Save and review agreement' : 'Continue to agreement' }}</button>
        @if ($customer)<a class="btn btn-link" href="{{ route('onboarding.agreement', $customer) }}">Cancel</a>@endif
    </div>
</form>
@endsection

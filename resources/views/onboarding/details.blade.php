@extends('layouts.onboarding')
@section('title', __('Your details'))
@if ($turnstileSiteKey)
    @push('head')<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@endif
@section('content')
@php $v = fn (string $key, $fallback = '') => old($key, $customer?->{$key} ?? ($prefill[$key] ?? $fallback)); @endphp
<p class="step-kicker">{{ __('Step :n of 5', ['n' => 1]) }}</p>
@unless ($customer)<p class="small mb-2">{{ __('Already started?') }} <a href="{{ route('account.request') }}">{{ __('Get a link to continue where you left off') }}</a></p>@endunless
<h1>{{ $customer ? __('Update your details') : __('Let’s set up your brokerage on RightAlly') }}</h1>
<p class="lead">{{ __('Tell us who is signing and where your business is based. These details go on your agreement.') }}</p>

@include('partials.flash', ['hideErrorSummary' => false])
@if ($quoteProblem ?? null)<div class="alert alert-warning">{{ $quoteProblem }}</div>@endif
@if ($customQuote)
    <div class="alert alert-info d-flex gap-2 align-items-start">
        <svg class="ic mt-1" aria-hidden="true"><use href="#i-tag"/></svg>
        <div><b>{{ __('Your custom quote is applied.') }}</b> {{ __('This pricing was prepared for you and is valid until :date.', ['date' => $customQuote->expires_at->setTimezone(\App\Support\BusinessClock::timezone())->translatedFormat('F j, Y')]) }}@if ($customQuote->note)<div class="small mt-1">{{ $customQuote->note }}</div>@endif</div>
    </div>
@endif

<form method="post" action="{{ $action }}" novalidate id="detailsForm"
      data-pricing='@json($quote->forBrowser())' data-coupon-url="{{ route('onboarding.coupon') }}">
    @csrf
    @if ($method === 'put') @method('put') @endif

    <fieldset class="mb-4"><legend>{{ __('About you') }}</legend>
        <div class="row g-3">
            <div class="col-sm-6"><label class="form-label" for="first_name">{{ __('First name') }}</label>
                <input class="form-control @error('first_name') is-invalid @enderror" id="first_name" name="first_name" value="{{ $v('first_name') }}" required autocomplete="given-name" maxlength="80">
                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="last_name">{{ __('Last name') }}</label>
                <input class="form-control @error('last_name') is-invalid @enderror" id="last_name" name="last_name" value="{{ $v('last_name') }}" required autocomplete="family-name" maxlength="80">
                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="title">{{ __('Title') }}</label>
                <input class="form-control @error('title') is-invalid @enderror" id="title" name="title" value="{{ $v('title') }}" required placeholder="{{ __('e.g. Owner, Managing Broker') }}" autocomplete="organization-title" maxlength="80">
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="company_name">{{ __('Brokerage or company name') }}</label>
                <input class="form-control @error('company_name') is-invalid @enderror" id="company_name" name="company_name" value="{{ $v('company_name') }}" required autocomplete="organization" maxlength="160">
                @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="email">{{ __('Email') }}</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ $v('email') }}" required autocomplete="email" maxlength="160" pattern="[^@\s]+@[^@\s]+\.[A-Za-z]{2,}" title="{{ __('Enter a valid email address, for example name@brokerage.com.') }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@else<div class="form-text">{{ __('Your signed agreement and receipts go here.') }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="phone">{{ __('Phone') }}</label>
                <div class="input-group has-validation"><span class="input-group-text">+1</span>
                    <input type="tel" class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone" value="{{ old('phone', $phone) }}" required autocomplete="tel-national" inputmode="tel" placeholder="(305) 555-0148" maxlength="30">
                    @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror</div></div>
        </div>
    </fieldset>

    <fieldset class="mb-4"><legend>{{ __('Your team') }}</legend>
        <div class="row g-3 align-items-start">
            <div class="col-sm-6"><label class="form-label" for="agents">{{ __('Number of agents') }}</label>
                <div class="input-group has-validation">
                    <button class="btn btn-outline-secondary" type="button" data-agstep="-1" aria-label="{{ __('Fewer agents') }}">−</button>
                    <input type="number" class="form-control text-center @error('agents') is-invalid @enderror" id="agents" name="agents" min="1" max="5000" value="{{ $agents }}" inputmode="numeric" required>
                    <button class="btn btn-outline-secondary" type="button" data-agstep="1" aria-label="{{ __('More agents') }}">+</button>
                    @error('agents')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text" id="agHelp" data-min="{{ $quote->minAgents }}">{{ __('Agents who will use RightAlly. You can change this later.') }}</div></div>
            <div class="col-sm-6"><div class="fee-mini"><div class="small text-slate" data-l="periodfee">{{ $quote->billingInterval === 'year' ? __('Your yearly fee') : __('Your monthly fee') }}</div>
                <div class="fs-4 fw-semibold num" data-l="monthly">{{ \App\Support\Money::format($quote->billingInterval === 'year' ? $quote->annualFeeCents() : $quote->monthlyFeeCents()) }}</div>
                <div class="small text-slate">{{ \App\Support\Money::format($quote->platformFeeCents) }} {{ __('platform') }} + {{ \App\Support\Money::format($quote->perAgentFeeCents) }} × <span data-l="agents">{{ $quote->agentsBilled }}</span> {{ __('agents') }}</div></div></div>
        </div>
    </fieldset>

    <fieldset class="mb-4"><legend>{{ __('Business address') }}</legend>
        <div class="row g-3">
            <div class="col-12"><label class="form-label" for="street">{{ __('Street address') }}</label>
                <input class="form-control @error('street') is-invalid @enderror" id="street" name="street" value="{{ $v('street') }}" required autocomplete="street-address" maxlength="255">
                @error('street')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="city">{{ __('City') }}</label>
                <input class="form-control @error('city') is-invalid @enderror" id="city" name="city" value="{{ $v('city') }}" required autocomplete="address-level2" maxlength="100">
                @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="state_code">{{ __('State') }}</label>
                <select class="form-select @error('state_code') is-invalid @enderror" id="state_code" name="state_code" required autocomplete="address-level1">
                    <option value="">{{ __('Choose a state') }}</option>
                    @foreach ($states as $code => $name)<option value="{{ $code }}" @selected($v('state_code') === $code)>{{ $name }}</option>@endforeach
                </select>
                @error('state_code')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="zip">{{ __('ZIP code') }}</label>
                <input class="form-control @error('zip') is-invalid @enderror" id="zip" name="zip" value="{{ $v('zip') }}" required inputmode="numeric" autocomplete="postal-code" maxlength="10">
                @error('zip')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            <div class="col-sm-6"><label class="form-label" for="country">{{ __('Country') }}</label>
                <input class="form-control locked" id="country" value="{{ __('United States') }}" readonly aria-readonly="true"></div>
        </div>
    </fieldset>

    @if ($quote->annualAvailable)
    <fieldset class="mb-4"><legend>{{ __('Subscription billing') }}</legend>
        <div class="row g-2" role="radiogroup">
            <div class="col-sm-6"><label class="choice h-100 {{ $quote->billingInterval === 'month' ? 'is-on' : '' }}"><input class="form-check-input me-2" type="radio" name="billing" value="month" @checked($quote->billingInterval === 'month')>
                <span><b>{{ __('Monthly') }}</b><span class="d-block small text-slate" data-billing-note="month">{{ __('Pay each month') }}</span></span></label></div>
            <div class="col-sm-6"><label class="choice h-100 {{ $quote->billingInterval === 'year' ? 'is-on' : '' }}"><input class="form-check-input me-2" type="radio" name="billing" value="year" @checked($quote->billingInterval === 'year')>
                <span><b>{{ __('Yearly') }}</b> <span class="badge text-bg-success">{{ __('Save') }} {{ rtrim(rtrim(number_format($quote->annualDiscountPercent, 2), '0'), '.') }}%</span><span class="d-block small text-slate" data-billing-note="year">{{ __('Pay 12 months in advance') }}</span></span></label></div>
        </div>
    </fieldset>
    @endif

    @unless ($customQuote)
    <fieldset class="mb-4"><legend>{{ __('Coupon') }} <span class="fw-normal text-slate">({{ __('optional') }})</span></legend>
        <label class="visually-hidden" for="coupon">{{ __('Coupon code') }}</label>
        <div class="input-group has-validation" style="max-width:420px">
            <div class="coupon-field flex-grow-1">
                <input class="form-control text-uppercase @error('coupon') is-invalid @enderror" id="coupon" name="coupon" value="{{ $couponCode }}" placeholder="{{ __('Enter code') }}" aria-describedby="couponMsg" maxlength="40" autocomplete="off">
                <button type="button" class="coupon-clear {{ $couponCode ? '' : 'd-none' }}" id="couponClear" aria-label="{{ __('Remove coupon') }}" title="{{ __('Remove coupon') }}">×</button>
            </div>
            <button class="btn btn-outline-primary" type="button" id="couponApply">{{ __('Apply') }}</button>
        </div>
        @php
            $msg = $errors->first('coupon') ?: $couponMessage;
            $ok = ! $msg && $quote->coupon;
        @endphp
        <div id="couponMsg" class="mt-2 small {{ $msg ? 'text-danger' : ($ok ? 'coupon-ok' : 'text-slate') }}" aria-live="polite">
            @if ($msg){{ $msg }}@elseif ($ok){{ __($fromLink ? ':code applied from your link. :pct% off your implementation fee.' : ':code applied. :pct% off your implementation fee.', ['code' => $quote->coupon->code, 'pct' => rtrim(rtrim(number_format($quote->discountPercent, 2), '0'), '.')]) }}@endif
        </div>
    </fieldset>
    @endunless

    @if ($turnstileSiteKey)
        <div class="mb-3">
            <div class="cf-turnstile" data-sitekey="{{ $turnstileSiteKey }}" data-theme="auto"></div>
            @error('turnstile')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    @endif

    <p class="small text-slate">{{ __('By continuing you agree to our') }} <a href="{{ route('legal', 'terms') }}" target="_blank" rel="noopener">{{ __('Terms of Use') }}</a> {{ __('and') }} <a href="{{ route('legal', 'privacy') }}" target="_blank" rel="noopener">{{ __('Privacy Policy') }}</a>.</p>
    <div class="d-flex flex-column flex-sm-row gap-2">
        <button class="btn btn-primary btn-lg px-5" type="submit">{{ $customer ? __('Save and review agreement') : __('Continue to agreement') }}</button>
        @if ($customer)<a class="btn btn-link" href="{{ route('onboarding.agreement', $customer) }}">{{ __('Cancel') }}</a>@endif
    </div>
</form>
@endsection

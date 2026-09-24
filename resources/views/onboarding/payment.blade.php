@extends('layouts.onboarding')
@section('title', __('Payment method'))
@if ($clientSecret)
    @push('head')<script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@endif
@section('content')
@php
    $taxCents = (int) ($customer->invoices()->where('type', 'deposit')->latest('id')->value('tax_cents') ?? 0);
    $deposit = \App\Support\Money::format($contract->deposit_cents + $taxCents);
    $company = $contract->company_legal_name.($contract->company_dba ? ' d/b/a '.$contract->company_dba : '');
@endphp
<p class="step-kicker">{{ __('Step :n of 5', ['n' => 4]) }}</p>
<h1>{{ __('Add your payment method') }}</h1>
<p class="lead">
    @if ($taxCents)
        {{ __('We’ll charge the deposit of :amount (including :tax sales tax) now.', ['amount' => $deposit, 'tax' => \App\Support\Money::format($taxCents)]) }}
    @else
        {{ __('We’ll charge the deposit of :amount now.', ['amount' => $deposit]) }}
    @endif
</p>

@if ($paymentError)
    <div class="alert {{ $clientSecret ? 'alert-danger' : 'alert-warning' }}" role="alert">{{ $paymentError }}
        @if ($fixEmail ?? false)
            <form method="post" action="{{ route('onboarding.email', $customer) }}" class="d-flex gap-2 flex-wrap mt-2">@csrf
                <label class="visually-hidden" for="fixEmail">{{ __('Email') }}</label>
                <input type="email" class="form-control form-control-sm" style="max-width:280px" id="fixEmail" name="email" value="{{ old('email', $customer->email) }}" required pattern="[^@\s]+@[^@\s]+\.[A-Za-z]{2,}">
                <button class="btn btn-sm btn-primary">{{ __('Save and continue') }}</button>
            </form>
            @error('email')<div class="small mt-1">{{ $message }}</div>@enderror
        @endif
    </div>
@endif

@if ($clientSecret)
<form id="paymentForm" data-key="{{ $publishableKey }}" data-secret="{{ $clientSecret }}" data-locale="{{ app()->getLocale() }}"
      data-return="{{ route('onboarding.payment.return', $customer) }}" data-email="{{ $customer->email }}" data-name="{{ $customer->fullName() }}">
    <div class="fee-mini mb-3">
        <div id="paymentElement" aria-live="polite"><div class="text-slate small py-4 text-center">{{ __('Loading secure payment form…') }}</div></div>
    </div>
    <p class="small text-slate mb-3">
        {{ __('Card or US bank account. Bank payments have lower fees and take up to 4 business days to confirm.') }}
        {{ __($contract->isAnnual()
            ? 'By paying, you authorize :company to charge this payment method for the deposit now and, automatically, for the balance on your go-live date and each yearly fee, as set out in your agreement.'
            : 'By paying, you authorize :company to charge this payment method for the deposit now and, automatically, for the balance on your go-live date and each monthly fee, as set out in your agreement.', ['company' => $company]) }}
    </p>
    <div class="alert alert-danger small d-none" id="paymentErr" role="alert"></div>
    <div class="d-flex flex-column flex-sm-row gap-2">
        <button class="btn btn-primary btn-lg px-5" type="submit" id="payBtn" disabled>{{ __('Pay :amount now', ['amount' => $deposit]) }}</button>
        <a class="btn btn-link" href="{{ route('onboarding.schedule', $customer) }}">{{ __('Back') }}</a>
    </div>
    <p class="small text-slate mt-3 d-flex gap-2 align-items-center"><svg class="ic" aria-hidden="true"><use href="#i-lock"/></svg>{{ __('Your payment details go straight to Stripe. RightAlly never sees your card or bank login.') }}</p>
</form>
@else
    <div class="mt-4"><a class="btn btn-link px-0" href="{{ route('onboarding.schedule', $customer) }}">{{ __('Back to payment schedule') }}</a></div>
@endif
@endsection

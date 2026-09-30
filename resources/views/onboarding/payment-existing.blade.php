@extends('layouts.onboarding')
@section('title', __('Payment method'))
@if ($clientSecret)
    @push('head')<script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@endif
@section('content')
@php $m = fn (int $c) => \App\Support\Money::format($c); @endphp
<p class="step-kicker">{{ __('Step :n of 5', ['n' => 4]) }}</p>
<h1>{{ __('Confirm your payment method') }}</h1>
<p class="lead">{{ __('Nothing is charged today. Your subscription of :amount starts on :date.', ['amount' => $contract->recurringLabel(), 'date' => $contract->first_charge_on?->translatedFormat('F j, Y') ?? '']) }}</p>

@if ($paymentError)<div class="alert alert-warning" role="alert">{{ $paymentError }}</div>@endif

@if ($savedLabel)
    <div class="fee-mini mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><div class="small text-slate">{{ __('On file') }}</div><div class="fs-5 fw-semibold">{{ $savedLabel }}</div></div>
        <a class="small" href="{{ route('onboarding.payment', ['customer' => $customer, 'new' => 1]) }}">{{ __('Use a different card or bank account') }}</a>
    </div>
    <p class="small text-slate">{{ __('By continuing, you authorize :company to charge this payment method for the subscription fees in your agreement, starting on the first charge date.', ['company' => $contract->company_legal_name.($contract->company_dba ? ' d/b/a '.$contract->company_dba : '')]) }}</p>
    <form method="post" action="{{ route('onboarding.existing.activate', $customer) }}">@csrf
        <button class="btn btn-primary btn-lg px-5">{{ __('Confirm and finish') }}</button>
    </form>
@elseif ($clientSecret)
    <form id="paymentForm" data-mode="setup" data-key="{{ $publishableKey }}" data-secret="{{ $clientSecret }}" data-locale="{{ app()->getLocale() }}"
          data-return="{{ route('onboarding.existing.return', $customer) }}" data-email="{{ $customer->email }}" data-name="{{ $customer->fullName() }}">
        <div class="fee-mini mb-3"><div id="paymentElement" aria-live="polite"><div class="text-slate small py-4 text-center">{{ __('Loading secure payment form…') }}</div></div></div>
        <p class="small text-slate">{{ __('By saving, you authorize :company to charge this payment method for the subscription fees in your agreement, starting on the first charge date.', ['company' => $contract->company_legal_name.($contract->company_dba ? ' d/b/a '.$contract->company_dba : '')]) }}</p>
        <div class="alert alert-danger small d-none" id="paymentErr" role="alert"></div>
        <button class="btn btn-primary btn-lg px-5" type="submit" id="payBtn" disabled>{{ __('Save and finish') }}</button>
    </form>
@endif
@endsection

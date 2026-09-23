@extends('layouts.onboarding')
@section('title', 'Payment method')
@if ($clientSecret)
    @push('head')<script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@endif
@section('content')
@php
    $taxCents = (int) ($customer->invoices()->where('type', 'deposit')->latest('id')->value('tax_cents') ?? 0);
    $deposit = \App\Support\Money::format($contract->deposit_cents + $taxCents);
@endphp
<p class="step-kicker">Step 4 of 5</p>
<h1>Add your payment method</h1>
<p class="lead">We’ll charge the deposit of {{ $deposit }}@if ($taxCents) (including {{ \App\Support\Money::format($taxCents) }} sales tax)@endif now and keep this method on file for the scheduled payments in your agreement.</p>

@if ($paymentError)
    <div class="alert {{ $clientSecret ? 'alert-danger' : 'alert-warning' }}" role="alert">{{ $paymentError }}</div>
@endif

@if ($clientSecret)
<form id="paymentForm" data-key="{{ $publishableKey }}" data-secret="{{ $clientSecret }}"
      data-return="{{ route('onboarding.payment.return', $customer) }}" data-email="{{ $customer->email }}" data-name="{{ $customer->fullName() }}">
    <div class="fee-mini mb-3">
        <div id="paymentElement" aria-live="polite"><div class="text-slate small py-4 text-center">Loading secure payment form…</div></div>
    </div>
    <p class="small text-slate mb-3">
        Card or US bank account. Bank payments have lower fees and take up to 4 business days to confirm.
        By paying, you authorize {{ $contract->company_legal_name }}{{ $contract->company_dba ? ' d/b/a '.$contract->company_dba : '' }} to charge this payment method for the deposit now and, automatically, for the balance on your go-live date and each monthly fee, as set out in your agreement.
    </p>
    <div class="alert alert-danger small d-none" id="paymentErr" role="alert"></div>
    <div class="d-flex flex-column flex-sm-row gap-2">
        <button class="btn btn-primary btn-lg px-5" type="submit" id="payBtn" disabled>Pay {{ $deposit }} now</button>
        <a class="btn btn-link" href="{{ route('onboarding.schedule', $customer) }}">Back</a>
    </div>
    <p class="small text-slate mt-3 d-flex gap-2 align-items-center"><svg class="ic" aria-hidden="true"><use href="#i-lock"/></svg>Your payment details go straight to Stripe. RightAlly never sees your card or bank login.</p>
</form>
@else
    <div class="mt-4"><a class="btn btn-link px-0" href="{{ route('onboarding.schedule', $customer) }}">Back to payment schedule</a></div>
@endif
@endsection

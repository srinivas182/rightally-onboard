@extends('layouts.page')
@section('title', 'Update payment method')
@push('head')<script src="https://js.stripe.com/v3/" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}"></script>@endpush
@section('content')
<a href="{{ route('account.show', $customer) }}" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Your account</a>
<h1 class="h2">Update payment method</h1>
<p class="text-slate">Currently: {{ $customer->payment_method_label ?? 'none' }}. The new card or bank account will be used for all future RightAlly charges for {{ $customer->company_name }}. Nothing is charged now.</p>
<form id="paymentForm" data-mode="setup" data-key="{{ $publishableKey }}" data-secret="{{ $clientSecret }}"
      data-return="{{ route('account.payment-method.return', $customer) }}" data-email="{{ $customer->email }}" data-name="{{ $customer->fullName() }}" style="max-width:560px">
    <div class="fee-mini mb-3"><div id="paymentElement" aria-live="polite"><div class="text-slate small py-4 text-center">Loading secure form…</div></div></div>
    <p class="small text-slate">By saving, you authorize {{ app(\App\Services\Settings\SettingsService::class)->get('company', 'legal_name') }} to charge this payment method for the amounts in your agreement.</p>
    <div class="alert alert-danger small d-none" id="paymentErr" role="alert"></div>
    <button class="btn btn-primary btn-lg px-5" type="submit" id="payBtn" disabled>Save payment method</button>
</form>
@endsection

@extends('layouts.onboarding')
@section('title', 'Payment method')
@section('content')
<p class="step-kicker">Step 4 of 5</p>
<h1>Add your payment method</h1>
<p class="lead">We’ll charge the deposit of {{ \App\Support\Money::format($contract->deposit_cents) }} and keep this method on file for the scheduled payments.</p>
<div class="fee-mini d-flex gap-2 align-items-start">
    <svg class="ic mt-1 text-primary" aria-hidden="true"><use href="#i-lock"/></svg>
    <div><b>Online payment is being set up.</b> Your signed agreement is saved. We’ll email you a secure payment link shortly, or contact us at <a href="mailto:{{ app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email') }}">{{ app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email') }}</a>.</div>
</div>
<div class="mt-4"><a class="btn btn-link px-0" href="{{ route('onboarding.schedule', $customer) }}">Back to payment schedule</a></div>
@endsection

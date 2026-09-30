@extends('layouts.onboarding')
@section('title', __('All set'))
@section('content')
<div class="d-inline-grid rounded-circle mb-3" style="width:64px;height:64px;place-items:center;background:rgba(14,143,99,.12);color:var(--bs-success)">
    <svg class="ic" style="width:32px;height:32px" aria-hidden="true"><use href="#i-check"/></svg>
</div>
<h1>{{ __('You’re all set, :name', ['name' => $customer->first_name]) }}</h1>
<p class="lead">{{ __('Your agreement is signed and your payment method is saved. Nothing changes for your team: RightAlly keeps running as it does today.') }}</p>
<div class="fee-mini mb-4">
    <div class="d-flex justify-content-between"><span>{{ __('First charge') }}</span><b>{{ $contract->first_charge_on?->translatedFormat('F j, Y') }}</b></div>
    <div class="d-flex justify-content-between"><span>{{ __('Subscription') }}</span><b>{{ $contract->recurringLabel($customer->agent_count) }}</b></div>
    <div class="d-flex justify-content-between"><span>{{ __('Payment method') }}</span><b>{{ $customer->payment_method_label }}</b></div>
</div>
<div class="d-flex flex-column flex-sm-row gap-2">
    <a class="btn btn-outline-primary" href="{{ route('onboarding.pdf', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>{{ __('Download signed agreement') }}</a>
    <a class="btn btn-link" href="{{ route('account.login', ['email' => $customer->email]) }}">{{ __('Set up your account login') }}</a>
</div>
@endsection

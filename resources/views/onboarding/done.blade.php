@extends('layouts.onboarding')
@section('title', __('All set'))
@section('content')
@php
    $processing = $invoice->status === \App\Enums\InvoiceStatus::Processing;
    $goLive = \Illuminate\Support\Carbon::parse($customer->go_live_date ?? $contract->starts_on);
    $amount = \App\Support\Money::format($invoice->amount_cents);
@endphp
<div class="d-inline-grid rounded-circle mb-3" style="width:64px;height:64px;place-items:center;background:rgba(14,143,99,.12);color:var(--bs-success)">
    <svg class="ic" style="width:32px;height:32px" aria-hidden="true"><use href="#i-check"/></svg>
</div>
<h1>{{ __('Welcome to RightAlly, :name', ['name' => $customer->first_name]) }}</h1>
<p class="lead">
    @if ($processing)
        {{ __('Your agreement is signed and your bank payment of :amount is on its way. Bank payments take up to 4 business days to confirm; we’ll email your receipt when it clears.', ['amount' => $amount]) }}
    @else
        {{ __('Your deposit of :amount is paid and your agreement is signed.', ['amount' => $amount]) }}
    @endif
    {{ __('We’ve emailed your signed agreement to :email.', ['email' => $customer->email]) }}
</p>
<h2 class="h5 mb-3">{{ __('What happens next') }}</h2>
<ol class="tl mb-4">
    <li class="now"><span class="n">1</span><b>{{ __('Kick-off call within 2 business days') }}</b><div class="small text-slate">{{ __('Your implementation lead will reach out to schedule it.') }}</div></li>
    <li><span class="n">2</span><b>{{ __('We build your RightAlly instance') }}</b><div class="small text-slate">{{ __('Target go-live: :date.', ['date' => $goLive->translatedFormat('F j, Y')]) }}</div></li>
    <li><span class="n">3</span><b>{{ __('You go live') }}</b><div class="small text-slate">{{ __('The remaining :amount is charged to :method that day.', ['amount' => \App\Support\Money::format($contract->balance_cents), 'method' => $customer->payment_method_label ?: __('your saved payment method')]) }}</div></li>
</ol>
<div class="d-flex flex-column flex-sm-row gap-2">
    <a class="btn btn-outline-primary" href="{{ route('onboarding.pdf', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>{{ __('Download signed agreement') }}</a>
    <a class="btn btn-link" href="{{ route('account.login', ['email' => $customer->email]) }}">{{ __('Set up your account login') }}</a>
    <a class="btn btn-link" href="mailto:{{ app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email') }}">{{ __('Contact support') }}</a>
</div>
@endsection

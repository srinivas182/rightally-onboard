@extends('layouts.onboarding')
@section('title', 'All set')
@section('content')
@php
    $processing = $invoice->status === \App\Enums\InvoiceStatus::Processing;
    $goLive = \Illuminate\Support\Carbon::parse($customer->go_live_date ?? $contract->starts_on);
@endphp
<div class="d-inline-grid rounded-circle mb-3" style="width:64px;height:64px;place-items:center;background:rgba(14,143,99,.12);color:var(--bs-success)">
    <svg class="ic" style="width:32px;height:32px" aria-hidden="true"><use href="#i-check"/></svg>
</div>
<h1>Welcome to RightAlly, {{ $customer->first_name }}</h1>
<p class="lead">
    @if ($processing)
        Your agreement is signed and your bank payment of <b>{{ \App\Support\Money::format($invoice->amount_cents) }}</b> is on its way. Bank payments take up to 4 business days to confirm; we’ll email your receipt when it clears.
    @else
        Your deposit of <b>{{ \App\Support\Money::format($invoice->amount_cents) }}</b> is paid and your agreement is signed.
    @endif
    We’ve emailed your signed agreement to {{ $customer->email }}.
</p>
<h2 class="h5 mb-3">What happens next</h2>
<ol class="tl mb-4">
    <li class="now"><span class="n">1</span><b>Kick-off call within 2 business days</b><div class="small text-slate">Your implementation lead will reach out to schedule it.</div></li>
    <li><span class="n">2</span><b>We build your RightAlly instance</b><div class="small text-slate">Target go-live: {{ $goLive->format('F j, Y') }}.</div></li>
    <li><span class="n">3</span><b>You go live</b><div class="small text-slate">The remaining {{ \App\Support\Money::format($contract->balance_cents) }} is charged to {{ $customer->payment_method_label ?: 'your saved payment method' }} that day.</div></li>
</ol>
<div class="d-flex flex-column flex-sm-row gap-2">
    <a class="btn btn-outline-primary" href="{{ route('onboarding.pdf', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>Download signed agreement</a>
    <a class="btn btn-link" href="mailto:{{ app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email') }}">Contact support</a>
</div>
@endsection

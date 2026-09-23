@extends('layouts.onboarding')
@section('title', 'Payment schedule')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $today = \App\Support\BusinessClock::today();
    $firstMonthly = \Illuminate\Support\Carbon::parse($contract->starts_on)->addDays(30);
@endphp
<p class="step-kicker">Step 3 of 5</p>
<h1>Your payment schedule</h1>
<p class="lead">Here’s exactly what you pay and when. Every charge is emailed to you with a receipt.</p>
<ol class="tl mb-4">
    <li class="now"><span class="n">1</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>Today, {{ $today->format('M j, Y') }}</b><div class="text-slate small">Deposit: {{ rtrim(rtrim(number_format((float) $contract->deposit_percent, 2), '0'), '.') }}% of your implementation fee</div></div><div class="amt">{{ $m($contract->deposit_cents) }}</div></div></li>
    <li><span class="n">2</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>Go-live date, {{ \Illuminate\Support\Carbon::parse($contract->starts_on)->format('M j, Y') }}</b><div class="text-slate small">Remaining implementation fee, charged automatically. If go-live moves, this date moves with it and we’ll tell you at least 2 days ahead.</div></div><div class="amt">{{ $m($contract->balance_cents) }}</div></div></li>
    <li><span class="n">3</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>First monthly charge, {{ $firstMonthly->format('M j, Y') }}</b><div class="text-slate small">{{ $m($contract->platform_fee_cents) }} platform fee + {{ $m($contract->per_agent_fee_cents) }} × {{ $contract->agent_count }} agents. Then every month, based on your agent count on the billing date (minimum {{ $contract->min_agents }}).</div></div><div class="amt">{{ $m($contract->monthlyFeeCents()) }}</div></div></li>
</ol>
<div class="fee-mini mb-4 small"><b>Minimum term:</b> {{ $contract->term_months }} months from go-live. Renewal is never automatic; we’ll send a renewal agreement 45 days before your term ends.</div>
<div class="d-flex flex-column flex-sm-row gap-2">
    <a class="btn btn-primary btn-lg px-5" href="{{ route('onboarding.payment', $customer) }}">Continue to payment</a>
    <a class="btn btn-link" href="{{ route('onboarding.agreement', $customer) }}">Back to agreement</a>
</div>
@endsection

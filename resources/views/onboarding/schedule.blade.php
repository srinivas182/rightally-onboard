@extends('layouts.onboarding')
@section('title', __('Payment schedule'))
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $today = \App\Support\BusinessClock::today();
    $firstCharge = \Illuminate\Support\Carbon::parse($contract->starts_on)->addDays(30);
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
@endphp
<p class="step-kicker">{{ __('Step :n of 5', ['n' => 3]) }}</p>
<h1>{{ __('Your payment schedule') }}</h1>
<p class="lead">{{ __('Here’s exactly what you pay and when. Every charge is emailed to you with a receipt.') }}</p>
<ol class="tl mb-4">
    <li class="now"><span class="n">1</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>{{ __('Today, :date', ['date' => $today->translatedFormat('M j, Y')]) }}</b><div class="text-slate small">{{ __('Deposit: :pct% of your implementation fee', ['pct' => $pct($contract->deposit_percent)]) }}</div></div><div class="amt">{{ $m($contract->deposit_cents) }}</div></div></li>
    <li><span class="n">2</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>{{ __('Go-live date, :date', ['date' => \Illuminate\Support\Carbon::parse($contract->starts_on)->translatedFormat('M j, Y')]) }}</b><div class="text-slate small">{{ __('Remaining implementation fee, charged automatically. If go-live moves, this date moves with it and we’ll tell you at least 2 days ahead.') }}</div></div><div class="amt">{{ $m($contract->balance_cents) }}</div></div></li>
    @if ($contract->isAnnual())
        <li><span class="n">3</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>{{ __('First yearly charge, :date', ['date' => $firstCharge->translatedFormat('M j, Y')]) }}</b><div class="text-slate small">{{ __('12 months of platform fee and :agents agents, less :pct%. Then once a year, based on your agent count on the billing date (minimum :min).', ['agents' => $contract->agent_count, 'pct' => $pct($contract->annual_discount_percent), 'min' => $contract->min_agents]) }}</div></div><div class="amt">{{ $m($contract->annualFeeCents()) }}</div></div></li>
    @else
        <li><span class="n">3</span><div class="d-flex justify-content-between flex-wrap gap-2"><div><b>{{ __('First monthly charge, :date', ['date' => $firstCharge->translatedFormat('M j, Y')]) }}</b><div class="text-slate small">{{ __(':platform platform fee + :per × :agents agents. Then every month, based on your agent count on the billing date (minimum :min).', ['platform' => $m($contract->platform_fee_cents), 'per' => $m($contract->per_agent_fee_cents), 'agents' => $contract->agent_count, 'min' => $contract->min_agents]) }}</div></div><div class="amt">{{ $m($contract->monthlyFeeCents()) }}</div></div></li>
    @endif
</ol>
<div class="fee-mini mb-4 small"><b>{{ __('Minimum term:') }}</b> {{ __(':months months from go-live. Renewal is never automatic; we’ll send a renewal agreement 45 days before your term ends.', ['months' => $contract->term_months]) }}</div>
<div class="d-flex flex-column flex-sm-row gap-2">
    <a class="btn btn-primary btn-lg px-5" href="{{ route('onboarding.payment', $customer) }}">{{ __('Continue to payment') }}</a>
    <a class="btn btn-link" href="{{ route('onboarding.agreement', $customer) }}">{{ __('Back to agreement') }}</a>
</div>
@endsection

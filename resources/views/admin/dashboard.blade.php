@extends('layouts.admin')
@section('title', 'Dashboard')
@section('menu', 'dashboard')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $mShort = fn (int $c) => '$'.number_format($c / 100);
    $canCustomers = auth('admin')->user()->can('menu.customers');
    $canInvoices = auth('admin')->user()->can('menu.invoices');
    $canContracts = auth('admin')->user()->can('menu.contracts');
    $kpi = function (string $label, string $value, string $sub, ?string $href, string $extra = '') {
        $tag = $href ? 'a' : 'div';
        $attr = $href ? ' href="'.e($href).'"' : '';
        return "<{$tag} class=\"kpi {$extra}\"{$attr}><div class=\"l\">".e($label)."</div><div class=\"v num\">{$value}</div><div class=\"small text-slate\">".e($sub)."</div></{$tag}>";
    };
@endphp
<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">Dashboard</h1><div class="text-slate">{{ \App\Support\BusinessClock::now()->format('l, M j, Y') }}</div></div>
    <div class="btn-group seg" role="group" aria-label="Revenue period">
        @foreach (['day' => 'Today', 'month' => 'Month', 'year' => 'Year'] as $key => $label)
            <a class="btn btn-outline-secondary {{ $range === $key ? 'active' : '' }}" href="{{ route('admin.dashboard', ['range' => $key]) }}" @if ($range === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        <button class="btn btn-outline-secondary {{ $range === 'custom' ? 'active' : '' }}" type="button" data-bs-toggle="collapse" data-bs-target="#rangePick" aria-expanded="{{ $range === 'custom' ? 'true' : 'false' }}"><svg class="ic" aria-hidden="true"><use href="#i-cal"/></svg> Range</button>
    </div>
</div>
<div class="collapse {{ $range === 'custom' ? 'show' : '' }} mb-3" id="rangePick">
    <form class="panel p-3" method="get"><input type="hidden" name="range" value="custom">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4"><label class="form-label small" for="from">From</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="{{ $from }}" required></div>
            <div class="col-sm-4"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="{{ $to }}" required></div>
            <div class="col-sm-4"><button class="btn btn-primary btn-sm w-100">Show revenue</button></div>
        </div>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-2">{!! $kpi('Customers onboarded', number_format($kpis['onboarded']), '+'.$kpis['onboarded_this_month'].' this month', $canCustomers ? route('admin.customers.index') : null) !!}</div>
    <div class="col-6 col-xl-2">{!! $kpi('Revenue received', $mShort($revenueCents), $rangeLabel, $canInvoices ? route('admin.invoices.index', ['tab' => 'paid']) : null) !!}</div>
    <div class="col-6 col-xl-2">{!! $kpi('Failed payments', '<span class="'.($kpis['failed'] ? 'text-danger' : '').'">'.$kpis['failed'].'</span>', $m($kpis['failed_cents']).' outstanding', $canInvoices ? route('admin.invoices.index', ['tab' => 'failed']) : null, $kpis['failed'] ? 'alert-k' : '') !!}</div>
    <div class="col-6 col-xl-2">{!! $kpi('Go-lives next 7 days', (string) $kpis['go_lives'], $m($kpis['go_lives_cents']).' to charge', $canInvoices ? route('admin.invoices.index', ['tab' => 'upcoming']) : null) !!}</div>
    <div class="col-6 col-xl-2">{!! $kpi('Suspended', (string) $kpis['suspended'], 'Unpaid 30+ days', $canCustomers ? route('admin.customers.index', ['status' => 'suspended']) : null) !!}</div>
    <div class="col-6 col-xl-2">{!! $kpi('Renewals due', (string) $kpis['renewals'], 'Next 45 days, unsigned', $canContracts ? route('admin.contracts.index').'#renewals' : null) !!}</div>
</div>

<div class="row g-3">
    <div class="col-xl-8"><div class="panel h-100">
        <div class="panel-h"><h2>Revenue received</h2><span class="small text-slate">Last 12 months, settled payments</span></div>
        <div class="p-3" style="height:300px"><canvas id="revChart" aria-label="Revenue by month" role="img" data-chart='@json($chart)'></canvas></div>
    </div></div>
    <div class="col-xl-4"><div class="panel h-100">
        <div class="panel-h"><h2>Needs attention</h2></div>
        @forelse ($attention as $item)
            @php $href = $canCustomers ? route('admin.customers.show', $item['customer']) : null; @endphp
            <{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif class="d-flex gap-2 px-3 py-3 border-bottom text-reset text-decoration-none">
                <svg class="ic mt-1 text-{{ $item['kind'] }}" aria-hidden="true"><use href="#i-{{ $item['kind'] === 'danger' ? 'alert' : ($item['kind'] === 'primary' ? 'cal' : 'file') }}"/></svg>
                <div><b>{{ $item['customer']->company_name }}</b>: {{ $item['text'] }}<div class="small text-slate">{{ $item['detail'] }}</div></div>
            </{{ $href ? 'a' : 'div' }}>
        @empty
            <div class="p-4 text-center text-slate">Nothing needs attention right now.</div>
        @endforelse
    </div></div>
</div>
@endsection

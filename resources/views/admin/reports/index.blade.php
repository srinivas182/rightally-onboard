@extends('layouts.admin')
@section('title', 'Reports')
@section('menu', 'reports')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $pct = fn (int $a, int $b) => $b ? round($a / $b * 100).'%' : '—';
    $totals = ['started' => $funnel->sum('started'), 'details' => $funnel->sum('details'), 'signed' => $funnel->sum('signed'), 'paid' => $funnel->sum('paid'), 'live' => $funnel->sum('live')];
@endphp
<h1 class="h3 mb-4">Reports</h1>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist" data-remember-tab="reports">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#funnel">Onboarding funnel</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#revenue">Revenue</a></li>
</ul>
<div class="tab-content">
<div class="tab-pane fade show active" id="funnel" role="tabpanel">
    <form method="get" class="panel p-3 mb-3"><div class="row g-2 align-items-end">
        <div class="col-sm-3"><label class="form-label small" for="from">Started from</label><input type="date" class="form-control form-control-sm" id="from" name="from" value="{{ $from }}"></div>
        <div class="col-sm-3"><label class="form-label small" for="to">To</label><input type="date" class="form-control form-control-sm" id="to" name="to" value="{{ $to }}"></div>
        <div class="col-sm-3"><label class="form-label small" for="group">Group by</label><select class="form-select form-select-sm" id="group" name="group">@foreach ($groups as $k => $l)<option value="{{ $k }}" @selected($group === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-sm-3 d-flex gap-2"><button class="btn btn-primary btn-sm flex-grow-1">Show</button><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.reports.export', ['report' => 'funnel', 'from' => $from, 'to' => $to, 'group' => $group]) }}">CSV</a></div>
    </div></form>
    <div class="row g-3 mb-3">
        @foreach (['started' => 'Started', 'details' => 'Details done', 'signed' => 'Signed', 'paid' => 'Paid deposit', 'live' => 'Live'] as $k => $l)
            <div class="col-6 col-lg"><div class="kpi"><div class="l">{{ $l }}</div><div class="v num">{{ $totals[$k] }}</div>
                <div class="small text-slate">@if ($k === 'started')clients who began @else{{ $pct($totals[$k], $totals['started']) }} of started @endif</div></div></div>
        @endforeach
    </div>
    <div class="panel">
        @if ($funnel->isEmpty())<div class="p-4 text-center text-slate">No one started onboarding in this period.</div>@else
        <div class="table-responsive"><table class="table">
            <thead><tr><th>{{ $groups[$group] }}</th><th class="text-end">Started</th><th class="text-end">Details done</th><th class="text-end">Signed</th><th class="text-end">Paid</th><th class="text-end">Live</th><th class="text-end">Start → sign</th><th class="text-end">Sign → pay</th><th class="text-end">Start → live</th></tr></thead>
            <tbody>@foreach ($funnel as $r)
                <tr><td>{{ $r['group'] }}</td><td class="text-end num">{{ $r['started'] }}</td><td class="text-end num">{{ $r['details'] }}</td><td class="text-end num">{{ $r['signed'] }}</td><td class="text-end num">{{ $r['paid'] }}</td><td class="text-end num">{{ $r['live'] }}</td>
                    <td class="text-end num">{{ $pct($r['signed'], $r['started']) }}</td><td class="text-end num">{{ $pct($r['paid'], $r['signed']) }}</td><td class="text-end num fw-semibold">{{ $pct($r['live'], $r['started']) }}</td></tr>
            @endforeach</tbody>
        </table></div>@endif
        <div class="p-3 small text-slate border-top">Clients are counted in the period they started onboarding. “Paid” includes bank payments still clearing. Sign → pay shows how many signers finish; the deposit reminders help here.</div>
    </div>
</div>

<div class="tab-pane fade" id="revenue" role="tabpanel">
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="kpi"><div class="l">Monthly recurring revenue</div><div class="v num">{{ $m($mrr['mrr']) }}</div><div class="small text-slate">{{ $mrr['customers'] }} live {{ Str::plural('customer', $mrr['customers']) }}; yearly plans counted as 1/12</div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="l">Annual run rate</div><div class="v num">{{ $m($mrr['mrr'] * 12) }}</div><div class="small text-slate">MRR × 12</div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="l">New this month</div><div class="v num {{ $mrr['new_mrr'] ? 'text-success' : '' }}">{{ $mrr['new_mrr'] ? '+' : '' }}{{ $m($mrr['new_mrr']) }}</div><div class="small text-slate">{{ $mrr['new'] }} went live</div></div></div>
        <div class="col-6 col-lg-3"><div class="kpi"><div class="l">Churned this month</div><div class="v num {{ $mrr['churned_mrr'] ? 'text-danger' : '' }}">{{ $mrr['churned_mrr'] ? '-' : '' }}{{ $m($mrr['churned_mrr']) }}</div><div class="small text-slate">{{ $mrr['churned'] }} cancelled or ended</div></div></div>
    </div>
    <div class="row g-3">
        <div class="col-xl-8"><div class="panel">
            <div class="panel-h"><h2>Revenue by month</h2><a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.reports.export', ['report' => 'revenue']) }}">CSV</a></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Month</th><th class="text-end">Deposits</th><th class="text-end">Balances</th><th class="text-end">Subscriptions</th><th class="text-end">Early term.</th><th class="text-end">Total</th></tr></thead>
                <tbody>@foreach ($revenue->reverse() as $r)
                    <tr><td>{{ \Illuminate\Support\Carbon::parse($r['month'].'-01')->format('M Y') }}</td><td class="text-end num">{{ $m($r['deposit']) }}</td><td class="text-end num">{{ $m($r['balance']) }}</td><td class="text-end num">{{ $m($r['monthly'] + $r['annual']) }}</td><td class="text-end num">{{ $m($r['early_termination']) }}</td><td class="text-end num fw-semibold">{{ $m($r['total']) }}</td></tr>
                @endforeach</tbody>
            </table></div>
            <div class="p-3 small text-slate border-top">Money settled in each month, less refunds and sales tax. Open chargebacks are excluded.</div>
        </div></div>
        <div class="col-xl-4"><div class="panel">
            <div class="panel-h"><h2>Next 3 months</h2></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Month</th><th class="text-end">Expected</th></tr></thead>
                <tbody>@foreach ($forecast as $f)
                    <tr><td>{{ $f['month'] }}<div class="small text-slate">{{ $m($f['monthly']) }} subscriptions + {{ $m($f['balances']) }} go-live balances</div></td><td class="text-end num fw-semibold">{{ $m($f['total']) }}</td></tr>
                @endforeach</tbody>
            </table></div>
            <div class="p-3 small text-slate border-top">At today’s agent counts, assuming every charge succeeds. Excludes new sign-ups.</div>
        </div></div>
    </div>
</div>
</div>
@endsection

@extends('layouts.admin')
@section('title', 'Former customers')
@section('menu', 'former')
@section('content')
@php $m = fn (int $c) => \App\Support\Money::format($c); @endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">Former customers</h1><div class="small text-slate">Customers whose service ended. Their records stay for accounting.</div></div>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.former.export', request()->query()) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>Export CSV</a>
</div>
<div class="kpi-grid mb-4">
    <div><div class="kpi"><div class="l">Ended this month</div><div class="v num">{{ $stats['month'] }}</div><div class="small text-slate">{{ $m($stats['mrr_lost_month']) }} monthly revenue lost</div></div></div>
    <div><div class="kpi"><div class="l">Ended this year</div><div class="v num">{{ $stats['year'] }}</div><div class="small text-slate">&nbsp;</div></div></div>
    <div><div class="kpi"><div class="l">Ending soon</div><div class="v num">{{ $ending->count() }}</div><div class="small text-slate">End scheduled</div></div></div>
    <div><div class="kpi"><div class="l">Revenue from former customers</div><div class="v num">{{ $m($stats['lifetime']) }}</div><div class="small text-slate">Total received, net</div></div></div>
</div>

@if ($ending->isNotEmpty())
<div class="panel mb-3"><div class="panel-h"><h2>Ending soon</h2></div>
    <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Customer</th><th>Ends on</th><th>How</th><th>Reason</th></tr></thead><tbody>
    @foreach ($ending as $c)
        <tr><td><a class="fw-semibold" href="{{ route('admin.customers.show', $c) }}">{{ $c->company_name }}</a></td><td class="small">{{ $c->service_ends_on?->format('M j, Y') }}</td><td class="small">{{ \App\Services\Billing\ServiceEnding::TYPES[$c->end_type] ?? $c->end_type }}</td><td class="small">{{ \App\Services\Billing\ServiceEnding::REASONS[$c->end_reason] ?? $c->end_reason }}</td></tr>
    @endforeach
    </tbody></table></div></div>
@endif

<div class="panel">
    <div class="panel-h">
        <form method="get"><select class="form-select form-select-sm" name="reason" aria-label="Reason" onchange="this.form.submit()" style="max-width:240px"><option value="">All reasons</option>@foreach (\App\Services\Billing\ServiceEnding::REASONS as $k => $l)<option value="{{ $k }}" @selected($reason === $k)>{{ $l }}</option>@endforeach</select></form>
        <span class="small text-slate">{{ $rows->count() }} former {{ Str::plural('customer', $rows->count()) }}</span>
    </div>
    @if ($rows->isEmpty())
        <div class="p-4 p-md-5 text-center text-slate">No former customers.</div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Customer</th><th>Onboarded</th><th>Ended</th><th class="text-end">Months</th><th>Reason</th><th class="text-end">Monthly at end</th><th class="text-end">Revenue to date</th><th>Last payment</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>@foreach ($rows as $r)
            @php $c = $r['customer']; @endphp
            <tr>
                <td><a class="fw-semibold" href="{{ route('admin.customers.show', $c) }}">{{ $c->company_name }}</a><div class="small text-slate">{{ $c->source ?: 'Direct' }}{{ $c->coupon ? ' · '.$c->coupon->code : '' }}</div></td>
                <td class="small text-nowrap">{{ $r['onboarded']?->format('M j, Y') ?? '—' }}</td>
                <td class="small text-nowrap">{{ $r['ended']?->format('M j, Y') ?? '—' }}</td>
                <td class="text-end num">{{ $r['months'] }}</td>
                <td class="small">{{ \App\Services\Billing\ServiceEnding::REASONS[$c->end_reason] ?? ($c->status->value === 'expired' ? 'Term ended, not renewed' : '—') }}@if ($c->end_notes)<div class="text-slate">{{ Str::limit($c->end_notes, 80) }}</div>@endif</td>
                <td class="text-end num">{{ $m($r['monthly']) }}</td>
                <td class="text-end num fw-semibold">{{ $m($r['revenue']) }}</td>
                <td class="small text-nowrap">{{ $r['last_payment'] ? \Illuminate\Support\Carbon::parse($r['last_payment'])->setTimezone(\App\Support\BusinessClock::timezone())->format('M j, Y') : '—' }}</td>
                <td class="text-end"><form method="post" action="{{ route('admin.former.win-back', $c) }}">@csrf<button class="btn btn-sm btn-outline-primary">Win back</button></form></td>
            </tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
@endsection

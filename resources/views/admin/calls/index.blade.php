@extends('layouts.admin')
@section('title', 'Calls')
@section('menu', 'calls')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">Calls</h1><div class="small text-slate">Booked on your GoHighLevel calendar at <a href="{{ route('book') }}" target="_blank" rel="noopener">{{ route('book') }}</a>. Times in {{ $tz }}.</div></div>
    <button class="btn btn-outline-secondary btn-sm" type="button" data-copy="{{ route('book') }}">Copy booking link</button>
</div>

@if ($summary->isNotEmpty())
<div class="panel mb-3"><div class="panel-h"><h2>By coupon</h2><span class="small text-slate">All time</span></div>
    <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Coupon</th><th class="text-end">Calls booked</th><th class="text-end">Held</th><th class="text-end">Onboarded</th><th class="text-end">Call → onboarded</th></tr></thead>
        <tbody>@foreach ($summary as $r)
            <tr><td>{{ $r->coupon }}</td><td class="text-end num">{{ $r->booked }}</td><td class="text-end num">{{ $r->held }}</td><td class="text-end num">{{ $r->onboarded }}</td><td class="text-end num">{{ $r->booked ? round($r->onboarded / $r->booked * 100).'%' : '—' }}</td></tr>
        @endforeach</tbody>
    </table></div>
</div>
@endif

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link {{ $tab === 'upcoming' ? 'active' : '' }}" href="{{ route('admin.calls.index', ['tab' => 'upcoming', 'coupon' => $coupon ?: null, 'status' => $status ?: null]) }}">Upcoming</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'past' ? 'active' : '' }}" href="{{ route('admin.calls.index', ['tab' => 'past', 'coupon' => $coupon ?: null, 'status' => $status ?: null]) }}">Past</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'all' ? 'active' : '' }}" href="{{ route('admin.calls.index', ['tab' => 'all', 'coupon' => $coupon ?: null, 'status' => $status ?: null]) }}">All</a></li>
</ul>
<div class="panel">
    <div class="panel-h">
        <form method="get" class="d-flex gap-2 flex-wrap"><input type="hidden" name="tab" value="{{ $tab }}">
            <select class="form-select form-select-sm" name="coupon" aria-label="Coupon" onchange="this.form.submit()" style="max-width:180px"><option value="">All coupons</option>@foreach ($coupons as $c)<option value="{{ $c }}" @selected($coupon === $c)>{{ $c }}</option>@endforeach</select>
            <select class="form-select form-select-sm" name="status" aria-label="Status" onchange="this.form.submit()" style="max-width:160px"><option value="">All statuses</option>@foreach (\App\Models\CallBooking::STATUSES as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select>
        </form>
        <span class="small text-slate">{{ $calls->total() }} {{ Str::plural('call', $calls->total()) }}</span>
    </div>
    @if ($calls->isEmpty())
        <div class="p-4 p-md-5 text-center text-slate">{{ $tab === 'upcoming' ? 'No upcoming calls.' : 'No past calls yet.' }} Calls appear here as soon as someone books on your calendar.</div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>Who</th><th>Coupon</th><th>Source</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>@foreach ($calls as $c)
            <tr>
                <td class="small text-nowrap">{{ $c->starts_at?->setTimezone($tz)->format('D, M j, g:i A') ?? '—' }}@if ($c->client_timezone)<div class="text-slate">their time: {{ $c->starts_at?->setTimezone($c->client_timezone)->format('g:i A T') }}</div>@endif</td>
                <td><b>{{ $c->name ?: '—' }}</b>@if ($c->company_name)<span class="text-slate">, {{ $c->company_name }}</span>@endif
                    <div class="small text-slate">{{ $c->email }}{{ $c->phone ? ' · '.$c->phone : '' }}</div>
                    @if ($c->customer)<div class="small"><a href="{{ route('admin.customers.show', $c->customer) }}">Customer: {{ $c->customer->company_name }}</a></div>@endif
                    @if ($c->admin_notes)<div class="small fst-italic text-slate">{{ $c->admin_notes }}</div>@endif</td>
                <td>@if ($c->coupon_code)<span class="badge text-bg-light border">{{ $c->coupon_code }}</span>@else<span class="text-slate small">—</span>@endif</td>
                <td class="small">{{ $c->source ?: '—' }}</td>
                <td><span class="st {{ \App\Models\CallBooking::PILLS[$c->status] ?? 'st-draft' }}">{{ \App\Models\CallBooking::STATUSES[$c->status] ?? $c->status }}</span></td>
                <td class="text-end text-nowrap">
                    @if ($c->meeting_link && $c->status === 'scheduled')<a class="btn btn-sm btn-outline-primary" href="{{ $c->meeting_link }}" target="_blank" rel="noopener">Join</a>@endif
                    <div class="dropdown d-inline-block">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">More</button>
                        <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:260px">
                            @if ($c->email && $c->status !== 'onboarded')
                                <form method="post" action="{{ route('admin.calls.onboarding-link', $c) }}">@csrf<button class="dropdown-item">Email onboarding link{{ $c->coupon_code ? ' ('.$c->coupon_code.')' : '' }}</button></form>
                                <hr class="dropdown-divider">
                            @endif
                            <form method="post" action="{{ route('admin.calls.update', $c) }}" class="px-2">@csrf @method('put')
                                <label class="form-label small mb-1">Status</label>
                                <select class="form-select form-select-sm mb-2" name="status">@foreach (\App\Models\CallBooking::STATUSES as $k => $l)<option value="{{ $k }}" @selected($c->status === $k)>{{ $l }}</option>@endforeach</select>
                                <label class="form-label small mb-1">Notes</label>
                                <textarea class="form-control form-control-sm mb-2" name="admin_notes" rows="2" maxlength="1000">{{ $c->admin_notes }}</textarea>
                                <button class="btn btn-sm btn-primary w-100">Save</button>
                            </form>
                        </div>
                    </div>
                </td>
            </tr>
        @endforeach</tbody>
    </table></div>
    <div class="p-3 border-top">{{ $calls->links() }}</div>
    @endif
</div>
<p class="small text-slate mt-3">Status changes in GoHighLevel (cancelled, showed, no-show) update here automatically. A call is marked <b>Onboarded</b> when the same email pays its onboarding deposit.</p>
@endsection

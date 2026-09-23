@extends('layouts.admin')
@section('title', 'Contracts')
@section('menu', 'contracts')
@section('content')
@php $tz = \App\Support\BusinessClock::timezone(); @endphp
<h1 class="h3 mb-4">Contracts</h1>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist" data-remember-tab="contracts">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#signed">Signed</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#renewals">Renewals @if ($renewals->where('contract.status', \App\Enums\ContractStatus::Draft)->count())<span class="badge text-bg-warning">{{ $renewals->where('contract.status', \App\Enums\ContractStatus::Draft)->count() }}</span>@endif</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#templates">Templates</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="signed" role="tabpanel">
        <div class="panel">
            <div class="panel-h">
                <form method="get" class="d-flex gap-2 flex-grow-1" role="search">
                    <input class="form-control form-control-sm" style="max-width:260px" type="search" name="q" value="{{ $q }}" placeholder="Search number, company or email" aria-label="Search agreements">
                    <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
                </form>
                <span class="small text-slate">{{ $contracts->total() }} {{ Str::plural('agreement', $contracts->total()) }}</span>
            </div>
            @if ($contracts->isEmpty())
                <div class="p-4 text-center text-slate">{{ $q ? 'No agreements match “'.$q.'”.' : 'Signed agreements appear here as soon as clients sign.' }}</div>
            @else
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Agreement</th><th>Customer</th><th>Signed</th><th>Go-live</th><th>Term ends</th><th>Template</th><th class="text-end">Monthly</th><th></th></tr></thead>
                <tbody>
                @foreach ($contracts as $k)
                    <tr>
                        <td class="num">{{ $k->number }}</td>
                        <td><b>{{ $k->customer->company_name }}</b><div class="small text-slate">{{ $k->client_typed_name }}, {{ $k->customer->email }}</div></td>
                        <td class="small">{{ $k->signed_at?->copy()->setTimezone($tz)->format('M j, Y g:i A') }}</td>
                        <td class="small">{{ $k->starts_on?->format('M j, Y') }}</td>
                        <td class="small">{{ $k->ends_on?->format('M j, Y') }}</td>
                        <td class="small">v{{ $k->template->version }}</td>
                        <td class="text-end num">{{ \App\Support\Money::format($k->monthlyFeeCents()) }}</td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('admin.contracts.pdf', $k) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>PDF</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="p-3 border-top">{{ $contracts->links('pagination::bootstrap-5') }}</div>
            @endif
        </div>
    </div>

    <div class="tab-pane fade" id="renewals" role="tabpanel">
        <div class="panel">
            @if ($renewals->isEmpty())
                <div class="p-4 text-center text-slate">Renewal agreements are sent automatically 45 days before a term ends, and appear here.</div>
            @else
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Customer</th><th>Current term ends</th><th>Renewal</th><th class="text-end">New monthly</th><th>Offer sent</th><th>Reminder</th><th>Status</th><th></th></tr></thead>
                <tbody>@foreach ($renewals as $row)
                    @php $r = $row['contract']; @endphp
                    <tr><td><b>{{ $r->customer->company_name }}</b></td><td class="small">{{ $r->previous->ends_on?->format('M j, Y') }}</td><td class="small num">{{ $r->number }}</td>
                        <td class="text-end num">{{ \App\Support\Money::format($r->monthlyFeeCents($r->customer->agent_count)) }}</td>
                        <td class="small">{{ $row['notices']['offer'] ?? null ? \Illuminate\Support\Carbon::parse($row['notices']['offer'])->setTimezone($tz)->format('M j') : '—' }}</td>
                        <td class="small">{{ $row['notices']['reminder'] ?? null ? \Illuminate\Support\Carbon::parse($row['notices']['reminder'])->setTimezone($tz)->format('M j') : '—' }}</td>
                        <td>@if ($r->isSigned())<span class="st st-live">Signed</span>@else<span class="st st-wait">Awaiting signature</span>@endif</td>
                        <td class="text-end text-nowrap">
                            @if ($r->isSigned())<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.contracts.pdf', $r) }}">PDF</a>
                            @else<form method="post" action="{{ route('admin.contracts.resend-renewal', $r) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary"><svg class="ic me-1" aria-hidden="true"><use href="#i-send"/></svg>Resend</button></form>@endif
                        </td></tr>
                @endforeach</tbody>
            </table></div>
            @endif
        </div>
    </div>

    <div class="tab-pane fade" id="templates" role="tabpanel">
        <div class="panel">
            <div class="panel-h"><h2>Agreement templates</h2></div>
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Version</th><th>Type</th><th>Title</th><th>Published</th><th class="text-end">Signed with it</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($templates as $t)
                    <tr>
                        <td class="fw-semibold">v{{ $t->version }}</td>
                        <td>{{ ucfirst($t->type->value) }}</td>
                        <td class="small">{{ $t->title }}</td>
                        <td class="small">{{ $t->published_at?->copy()->setTimezone($tz)->format('M j, Y') ?? '—' }}</td>
                        <td class="text-end num">{{ $t->contracts_count }}</td>
                        <td>
                            @if (! $t->isPublished())<span class="st st-wait">Draft</span>
                            @elseif ($t->is_active)<span class="st st-live">In use</span>
                            @else<span class="st st-draft">Retired</span>@endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if ($t->isPublished())
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.contracts.templates.show', $t) }}">View</a>
                                @if ($t->is_active)
                                    <form method="post" action="{{ route('admin.contracts.templates.duplicate', $t) }}" class="d-inline">@csrf<button class="btn btn-sm btn-primary">New version</button></form>
                                @endif
                            @else
                                <a class="btn btn-sm btn-primary" href="{{ route('admin.contracts.templates.edit', $t) }}">Edit draft</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div class="p-3 small text-slate border-top">Published versions can’t be edited, so every signed agreement matches its template exactly. To change the wording, create a new version, edit the draft, then publish it. A Florida attorney should review wording changes before you publish.</div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.admin')
@section('title', 'Contracts')
@section('menu', 'contracts')
@section('content')
@php $tz = \App\Support\BusinessClock::timezone(); @endphp
<h1 class="h3 mb-4">Contracts</h1>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist" data-remember-tab="contracts">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#signed">Signed</a></li>
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

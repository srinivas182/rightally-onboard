@extends('layouts.admin')
@section('title', 'Customers')
@section('menu', 'customers')
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Customers</h1>
    <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.customers.export', request()->query()) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>Export CSV</a>
</div>
<div class="panel">
    <div class="panel-h">
        <form method="get" class="d-flex gap-2 flex-wrap flex-grow-1" role="search">
            <input class="form-control form-control-sm" style="max-width:260px" type="search" name="q" value="{{ $q }}" placeholder="Search company, name, email, city" aria-label="Search customers">
            <select class="form-select form-select-sm" style="max-width:190px" name="status" aria-label="Status" onchange="this.form.submit()">
                <option value="">All statuses ({{ $counts['all'] }})</option>
                <option value="incomplete" @selected($status === 'incomplete')>Not completed ({{ $counts['incomplete'] }})</option>
                @foreach ($statuses as $s)<option value="{{ $s->value }}" @selected($status === $s->value)>{{ $s->label() }}</option>@endforeach
            </select>
            <button class="btn btn-outline-secondary btn-sm" type="submit">Search</button>
        </form>
        <span class="small text-slate">{{ $customers->total() }} {{ Str::plural('customer', $customers->total()) }}</span>
    </div>
    @if ($customers->isEmpty())
        <div class="p-4 p-md-5 text-center text-slate">{{ $q || $status ? 'No customers match these filters.' : 'Customers appear here as soon as someone starts onboarding.' }}</div>
    @else
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Customer</th><th>Status</th><th>Stopped at</th><th>Go-live</th><th class="text-end">Agents</th><th>Payment method</th><th>Source</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        @foreach ($customers as $c)
            <tr>
                <td><a class="fw-semibold text-reset text-decoration-none" href="{{ route('admin.customers.show', $c) }}">{{ $c->company_name }}</a><div class="small text-slate">{{ $c->fullName() }}{{ $c->city ? ', '.$c->city.' '.$c->state_code : '' }}</div></td>
                <td><span class="st {{ $c->status->pill() }}">{{ $c->status->label() }}</span></td>
                <td class="small">@if (\App\Services\Onboarding\ResumeLinks::isIncomplete($c)){{ \App\Models\Customer::STAGES[$c->onboarding_stage ?? ($c->status === \App\Enums\CustomerStatus::ContractSigned ? 'payment' : 'agreement')] ?? '—' }}<div class="text-slate">{{ $c->updated_at->diffForHumans() }}</div>@else<span class="text-slate">—</span>@endif</td>
                <td class="small text-nowrap">{{ $c->go_live_date?->format('M j, Y') ?? '—' }}</td>
                <td class="text-end num">{{ $c->agent_count }}</td>
                <td class="small">{{ $c->payment_method_label ?? '—' }}</td>
                <td class="small">{{ $c->source ?? '—' }}</td>
                <td class="text-end text-nowrap">
                    @if (\App\Services\Onboarding\ResumeLinks::isIncomplete($c))
                        <form method="post" action="{{ route('admin.customers.resume-link', $c) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" title="Email a link to continue onboarding">Send link</button></form>
                    @endif
                    <a href="{{ route('admin.customers.show', $c) }}" class="btn btn-sm btn-outline-primary">Open</a>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="p-3 border-top">{{ $customers->links() }}</div>
    @endif
</div>
@endsection

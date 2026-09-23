@extends('layouts.admin')
@section('title', 'Invoices')
@section('menu', 'invoices')
@section('content')
@php
    $tz = \App\Support\BusinessClock::timezone();
    $m = fn (int $c) => \App\Support\Money::format($c);
    $canCustomers = auth('admin')->user()->can('menu.customers');
    $who = fn ($c) => $canCustomers ? '<a class="fw-semibold text-reset" href="'.e(route('admin.customers.show', $c)).'">'.e($c->company_name).'</a>' : '<b>'.e($c->company_name).'</b>';
@endphp
<h1 class="h3 mb-4">Invoices</h1>
<ul class="nav nav-tabs tabs-scroll mb-3" role="tablist">
    <li class="nav-item"><a class="nav-link {{ $tab === 'paid' ? 'active' : '' }}" data-bs-toggle="tab" href="#paid">Paid</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'failed' ? 'active' : '' }}" data-bs-toggle="tab" href="#failed">Failed @if ($failed->count())<span class="badge text-bg-danger">{{ $failed->count() }}</span>@endif</a></li>
    <li class="nav-item"><a class="nav-link {{ $tab === 'upcoming' ? 'active' : '' }}" data-bs-toggle="tab" href="#upcoming">Upcoming</a></li>
    @php $waiting = \App\Models\Approval::where('status', 'pending')->count(); @endphp
    <li class="nav-item"><a class="nav-link" href="{{ route('admin.approvals.index') }}">Approvals @if ($waiting)<span class="badge text-bg-warning">{{ $waiting }}</span>@endif</a></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade {{ $tab === 'paid' ? 'show active' : '' }}" id="paid" role="tabpanel"><div class="panel">
        <div class="panel-h">
            <form method="get" class="d-flex gap-2 flex-wrap"><input type="hidden" name="tab" value="paid">
                <select class="form-select form-select-sm" name="type" aria-label="Type" onchange="this.form.submit()" style="max-width:170px">
                    <option value="">All types</option>@foreach (\App\Enums\InvoiceType::cases() as $t)<option value="{{ $t->value }}" @selected($type === $t->value)>{{ $t->label() }}</option>@endforeach
                </select>
                <select class="form-select form-select-sm" name="period" aria-label="Period" onchange="this.form.submit()" style="max-width:150px">
                    @foreach (['month' => 'This month', 'last' => 'Last month', 'year' => 'This year', 'all' => 'All time'] as $k => $l)<option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>@endforeach
                </select>
            </form>
            <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.invoices.export', ['period' => $period, 'type' => $type]) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>Export CSV</a>
        </div>
        @if ($paid->isEmpty())<div class="p-4 text-center text-slate">No paid invoices in this period.</div>@else
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Invoice</th><th>Customer</th><th>Type</th><th class="text-end">Amount</th><th>Paid</th><th>Method</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
            <tbody>@foreach ($paid as $i)
                <tr><td class="num">{{ $i->number }}</td><td>{!! $who($i->customer) !!}</td><td class="small">{{ $i->type->label() }}@if ($i->period_start), {{ $i->period_start->format('M Y') }}@endif</td>
                    <td class="text-end num">{{ $m($i->amount_cents) }}</td><td class="small">{{ $i->paid_at?->setTimezone($tz)->format('M j, Y') }}</td><td class="small">{{ $i->payments->firstWhere('status', 'succeeded')?->method_label }}</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.invoices.pdf', $i) }}" target="_blank" rel="noopener">PDF</a></td></tr>
            @endforeach</tbody>
        </table></div>
        <div class="p-3 border-top">{{ $paid->links() }}</div>@endif
    </div></div>

    <div class="tab-pane fade {{ $tab === 'failed' ? 'show active' : '' }}" id="failed" role="tabpanel">
        <div class="panel mb-3">
            @if ($failed->isEmpty())<div class="p-4 text-center text-slate">No failed payments.</div>@else
            <div class="table-responsive"><table class="table">
                <thead><tr><th>Customer</th><th>Invoice</th><th class="text-end">Amount</th><th>Failed</th><th>Reason</th><th>Link sent</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>@foreach ($failed as $i)
                    @php $days = $i->failed_at ? (int) $i->failed_at->diffInDays(now()) : null; @endphp
                    <tr><td>{!! $who($i->customer) !!}</td><td class="small">{{ $i->number }}<div class="text-slate">{{ $i->type->label() }}</div></td><td class="text-end num">{{ $m($i->amount_cents) }}</td>
                        <td class="small">{{ $i->failed_at?->setTimezone($tz)->format('M j, Y') ?? '—' }}@if ($days !== null)<div class="{{ $days >= 25 ? 'text-danger' : 'text-slate' }}">{{ $days }} {{ Str::plural('day', $days) }} ago{{ $days >= 25 && $days < 30 ? ', suspends at 30' : '' }}</div>@endif</td>
                        <td class="small">{{ $i->failure_reason }}</td><td class="small">{{ $i->payment_link_sent_at?->setTimezone($tz)->format('M j') ?? '—' }}</td>
                        <td class="text-end text-nowrap">
                            @if ($i->hosted_invoice_url)
                                <form method="post" action="{{ route('admin.invoices.resend', $i) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary"><svg class="ic me-1" aria-hidden="true"><use href="#i-send"/></svg>Resend link</button></form>
                            @else<span class="small text-slate">Client retries from the onboarding link</span>@endif
                        </td></tr>
                @endforeach</tbody>
            </table></div>
            <div class="p-3 small text-slate border-top">The link opens Stripe’s secure page, where the client updates their card or bank account and pays. The new method is saved for future charges. Stripe also retries failed monthly charges automatically.</div>@endif
        </div>
        @if ($processing->isNotEmpty())
            <div class="panel"><div class="panel-h"><h2>Bank payments in progress</h2><span class="small text-slate">Usually clear within 4 business days</span></div>
                <div class="table-responsive"><table class="table"><tbody>@foreach ($processing as $i)
                    <tr><td>{!! $who($i->customer) !!}</td><td class="small">{{ $i->number }}, {{ $i->type->label() }}</td><td class="text-end num">{{ $m($i->amount_cents) }}</td><td class="small">{{ $i->updated_at->setTimezone($tz)->format('M j, Y') }}</td></tr>
                @endforeach</tbody></table></div></div>
        @endif
    </div>

    <div class="tab-pane fade {{ $tab === 'upcoming' ? 'show active' : '' }}" id="upcoming" role="tabpanel"><div class="panel">
        @if ($upcoming->isEmpty())<div class="p-4 text-center text-slate">No charges expected in the next 30 days.</div>@else
        <div class="table-responsive"><table class="table">
            <thead><tr><th>Date</th><th>Customer</th><th>Charge</th><th class="text-end">Amount</th><th>Method</th></tr></thead>
            <tbody>@foreach ($upcoming as $u)
                <tr><td class="small text-nowrap">{{ $u['date']->format('M j, Y') }}</td><td>{!! $who($u['customer']) !!}</td><td class="small">{{ $u['type'] }}</td><td class="text-end num">{{ $m($u['amount']) }}</td><td class="small">{{ $u['customer']->payment_method_label }}</td></tr>
            @endforeach</tbody>
        </table></div>
        <div class="p-3 small text-slate border-top">Next 30 days. Balances are charged by the daily run on the go-live date; monthly fees are charged by Stripe.</div>@endif
    </div></div>
</div>
@endsection

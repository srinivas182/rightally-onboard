@extends('layouts.admin')
@section('title', 'Approvals')
@section('menu', 'invoices')
@section('content')
@php $m = fn (int $c) => \App\Support\Money::format($c); $tz = \App\Support\BusinessClock::timezone(); $me = auth('admin')->user(); @endphp
<h1 class="h3 mb-1">Approvals</h1>
<p class="text-slate mb-4">Early terminations, and refunds or credits over {{ $m(\App\Services\Billing\ApprovalService::threshold()) }}, need a second admin. You can’t approve your own requests.</p>
<div class="panel mb-4">
    <div class="panel-h"><h2>Waiting</h2><span class="small text-slate">{{ $pending->count() }}</span></div>
    @forelse ($pending as $a)
        <div class="p-3 border-bottom">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div><b>{{ $a->label() }}</b> for <a href="{{ route('admin.customers.show', $a->customer) }}">{{ $a->customer->company_name }}</a>: <b class="num">{{ $m($a->amount_cents) }}</b>
                    <div class="small text-slate">Requested by {{ $a->requester->name }}, {{ $a->created_at->setTimezone($tz)->format('M j, g:i A') }}. Reason: {{ $a->reason }}</div>
                    @if ($a->action === 'early_termination')<div class="small text-slate">{{ $a->payload['months'] ?? '?' }} remaining months at {{ $m((int) ($a->payload['monthly_cents'] ?? 0)) }}. The subscription ends and the amount is charged when approved.</div>@endif
                    @if ($a->action === 'refund')<div class="small text-slate">Invoice {{ $a->payload['invoice'] ?? '' }}</div>@endif
                </div>
                @if ($a->requested_by !== $me->id)
                    <div class="d-flex gap-2 align-items-start">
                        <form method="post" action="{{ route('admin.approvals.approve', $a) }}">@csrf<button class="btn btn-primary btn-sm" data-confirm="Approve and carry out this {{ strtolower($a->label()) }} now?">Approve</button></form>
                        <form method="post" action="{{ route('admin.approvals.reject', $a) }}" class="d-flex gap-1">@csrf<input class="form-control form-control-sm" name="note" placeholder="Reason to reject" required maxlength="250"><button class="btn btn-outline-danger btn-sm">Reject</button></form>
                    </div>
                @else
                    <span class="small text-slate">Waiting for another admin</span>
                @endif
            </div>
        </div>
    @empty
        <div class="p-4 text-center text-slate">Nothing waiting.</div>
    @endforelse
</div>
@if ($decided->isNotEmpty())
<div class="panel"><div class="panel-h"><h2>Recent decisions</h2></div>
    <div class="table-responsive"><table class="table small">
        <thead><tr><th>Request</th><th class="text-end">Amount</th><th>Requested by</th><th>Decision</th><th>Result</th></tr></thead>
        <tbody>@foreach ($decided as $a)
            <tr><td>{{ $a->label() }}, {{ $a->customer->company_name }}</td><td class="text-end num">{{ $m($a->amount_cents) }}</td><td>{{ $a->requester->name }}</td>
                <td><span class="st {{ ['approved' => 'st-live', 'rejected' => 'st-draft', 'failed' => 'st-fail'][$a->status] ?? 'st-draft' }}">{{ ucfirst($a->status) }}</span> by {{ $a->decider?->name }}, {{ $a->decided_at?->setTimezone($tz)->format('M j') }}@if ($a->decision_note)<div class="text-slate">{{ $a->decision_note }}</div>@endif</td>
                <td>{{ $a->result }}</td></tr>
        @endforeach</tbody>
    </table></div></div>
@endif
@endsection

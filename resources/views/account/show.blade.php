@extends('layouts.page')
@section('title', 'Your account')
@section('header-right')<span class="small text-slate">{{ $customer->company_name }}</span>@endsection
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $tz = \App\Support\BusinessClock::timezone();
    $statusText = [
        'contract_signed' => ['Agreement signed, deposit not paid yet', 'st-wait'], 'awaiting_go_live' => ['Getting ready to go live', 'st-wait'],
        'balance_failed' => ['Go-live payment needs attention', 'st-fail'], 'live' => ['Live', 'st-live'], 'payment_failed' => ['Payment needs attention', 'st-fail'],
        'suspended' => ['Suspended: payment overdue', 'st-susp'], 'cancelled' => ['Cancelled', 'st-draft'], 'expired' => ['Agreement ended', 'st-draft'],
    ][$customer->status->value] ?? [$customer->status->label(), 'st-draft'];
    $unpaid = $customer->invoices->where('status', \App\Enums\InvoiceStatus::Failed);
@endphp
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h2 mb-1">{{ $customer->company_name }}</h1><div class="text-slate">{{ $customer->fullName() }}, {{ $customer->email }}</div></div>
    <span class="st {{ $statusText[1] }}">{{ $statusText[0] }}</span>
</div>

@foreach ($unpaid as $u)
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><b>{{ $m($u->amount_cents + $u->tax_cents) }} is unpaid</b> ({{ $u->type->label() }}, {{ $u->number }}). {{ $u->failure_reason }}</div>
        @if ($u->hosted_invoice_url)<a class="btn btn-danger btn-sm" href="{{ $u->hosted_invoice_url }}" target="_blank" rel="noopener">Pay now</a>
        @elseif ($u->type === \App\Enums\InvoiceType::Deposit)<a class="btn btn-danger btn-sm" href="{{ route('onboarding.payment', $customer) }}">Pay deposit</a>@endif
    </div>
@endforeach
@if ($customer->status === \App\Enums\CustomerStatus::ContractSigned && $unpaid->isEmpty())
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2"><div>Your agreement is signed. Pay the deposit to reserve your go-live date.</div><a class="btn btn-primary btn-sm" href="{{ route('onboarding.payment', $customer) }}">Pay deposit</a></div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="panel p-3 p-md-4 h-100">
        <div class="small text-slate">Next charge</div>
        @if ($next)
            <div class="fs-3 fw-semibold num">{{ $m($next['amount']) }}</div>
            <div>{{ $next['date']->format('F j, Y') }}</div><div class="small text-slate">{{ $next['label'] }}@if ($customer->agent_count && $contract) · minimum {{ $contract->min_agents }} agents @endif</div>
        @else<div class="text-slate mt-1">No upcoming charges.</div>@endif
    </div></div>
    <div class="col-md-6"><div class="panel p-3 p-md-4 h-100">
        <div class="small text-slate">Payment method</div>
        <div class="fs-5 fw-semibold mt-1">{{ $customer->payment_method_label ?? 'None saved yet' }}</div>
        @if ($customer->card_exp_month)<div class="small text-slate">Expires {{ sprintf('%02d/%d', $customer->card_exp_month, $customer->card_exp_year) }}</div>@endif
        @if ($canUpdateMethod)<a class="btn btn-outline-primary btn-sm mt-3" href="{{ route('account.payment-method', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-card"/></svg>Update payment method</a>@endif
    </div></div>
</div>

<div class="panel mb-3"><div class="panel-h"><h2>Agreements</h2></div>
    @forelse ($customer->contracts as $k)
        <div class="d-flex justify-content-between align-items-center gap-2 px-3 py-2 border-bottom">
            <div><b>{{ $k->number }}</b> <span class="text-slate small">{{ ucfirst($k->type->value) }}, signed {{ $k->signed_at->setTimezone($tz)->format('M j, Y') }}@if ($k->starts_on) · term {{ $k->starts_on->format('M j, Y') }} to {{ $k->ends_on?->format('M j, Y') }}@endif</span></div>
            @if ($k->pdf_path)<a class="btn btn-sm btn-outline-secondary" href="{{ route('account.agreement-pdf', [$customer, $k]) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>PDF</a>@endif
        </div>
    @empty<div class="p-3 text-slate">No signed agreements yet.</div>@endforelse
</div>

<div class="panel"><div class="panel-h"><h2>Invoices and receipts</h2></div>
    @if ($customer->invoices->isEmpty())<div class="p-3 text-slate">No invoices yet.</div>@else
    <div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Date</th><th>Description</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach ($customer->invoices as $i)
            <tr><td class="small text-nowrap">{{ ($i->paid_at ?? $i->failed_at ?? $i->created_at)->setTimezone($tz)->format('M j, Y') }}</td>
                <td class="small">{{ $i->type->label() }}@if ($i->period_start), {{ $i->period_start->format('M Y') }}@endif<div class="text-slate">{{ $i->number }}</div></td>
                <td class="text-end num">{{ $m($i->amount_cents + $i->tax_cents) }}</td>
                <td><span class="st {{ ['paid' => 'st-live', 'failed' => 'st-fail', 'processing' => 'st-wait'][$i->status->value] ?? 'st-draft' }}">{{ ['paid' => 'Paid', 'failed' => 'Unpaid', 'processing' => 'Processing', 'void' => 'Void'][$i->status->value] ?? ucfirst($i->status->value) }}</span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('account.invoice-pdf', [$customer, $i]) }}">PDF</a></td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
<p class="small text-slate mt-4">Need to change your agent count or anything else? Reply to any RightAlly email or write to {{ app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email') }}.</p>
@endsection

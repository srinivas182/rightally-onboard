@extends('layouts.page')
@section('title', __('Your account'))
@section('header-right')
    @if (request()->attributes->get('viewing_as_admin'))
        <span class="badge text-bg-warning">{{ __('Admin view') }}</span>
    @elseif (auth('customer')->check())
        <form method="post" action="{{ route('account.logout') }}" class="d-flex align-items-center gap-2">@csrf<span class="small text-slate d-none d-sm-inline">{{ $customer->email }}</span><button class="btn btn-sm btn-outline-secondary">{{ __('Sign out') }}</button></form>
    @endif
@endsection
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $tz = \App\Support\BusinessClock::timezone();
    $statusText = [
        'contract_signed' => [__('Agreement signed, deposit not paid yet'), 'st-wait'], 'awaiting_go_live' => [__('Getting ready to go live'), 'st-wait'],
        'balance_failed' => [__('Go-live payment needs attention'), 'st-fail'], 'live' => [__('Live'), 'st-live'], 'payment_failed' => [__('Payment needs attention'), 'st-fail'],
        'suspended' => [__('Suspended: payment overdue'), 'st-susp'], 'paused' => [__('Paused'), 'st-draft'], 'cancelled' => [__('Cancelled'), 'st-draft'], 'expired' => [__('Agreement ended'), 'st-draft'],
    ][$customer->status->value] ?? [$customer->status->label(), 'st-draft'];
    $invStatus = ['paid' => [__('Paid'), 'st-live'], 'failed' => [__('Unpaid'), 'st-fail'], 'processing' => [__('Processing'), 'st-wait'], 'void' => [__('Void'), 'st-draft']];
    $unpaid = $customer->invoices->where('status', \App\Enums\InvoiceStatus::Failed);
    $typeLabel = fn ($t) => __($t->label());
@endphp
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
    <div><h1 class="h2 mb-1">{{ $customer->company_name }}</h1><div class="text-slate">{{ $customer->fullName() }}, {{ $customer->email }}</div></div>
    <span class="st {{ $statusText[1] }}">{{ $statusText[0] }}</span>
</div>

@foreach ($unpaid as $u)
    <div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div><b>{{ __(':amount is unpaid', ['amount' => $m($u->amount_cents + $u->tax_cents)]) }}</b> ({{ $typeLabel($u->type) }}, {{ $u->number }}). {{ $u->failure_reason }}</div>
        @if ($u->hosted_invoice_url)<a class="btn btn-danger btn-sm" href="{{ $u->hosted_invoice_url }}" target="_blank" rel="noopener">{{ __('Pay now') }}</a>
        @elseif ($u->type === \App\Enums\InvoiceType::Deposit)<a class="btn btn-danger btn-sm" href="{{ route('onboarding.payment', $customer) }}">{{ __('Pay deposit') }}</a>@endif
    </div>
@endforeach
@if ($customer->status === \App\Enums\CustomerStatus::ContractSigned && $unpaid->isEmpty())
    <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2"><div>{{ __('Your agreement is signed. Pay the deposit to reserve your go-live date.') }}</div><a class="btn btn-primary btn-sm" href="{{ route('onboarding.payment', $customer) }}">{{ __('Pay deposit') }}</a></div>
@endif
@if ($customer->status === \App\Enums\CustomerStatus::Paused && $customer->paused_until)
    <div class="alert alert-secondary">{{ __('Your subscription is paused until :date.', ['date' => $customer->paused_until->translatedFormat('F j, Y')]) }}</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-6"><div class="panel p-3 p-md-4 h-100">
        <h2 class="small text-slate fw-normal mb-1">{{ __('Next charge') }}</h2>
        @if ($next)
            <div class="fs-3 fw-semibold num">{{ $m($next['amount']) }}</div>
            <div>{{ $next['date']->translatedFormat('F j, Y') }}</div><div class="small text-slate">{{ __($next['label']) }}@if ($customer->agent_count && $contract) · {{ __('minimum :n agents', ['n' => $contract->min_agents]) }}@endif</div>
        @else<div class="text-slate mt-1">{{ __('No upcoming charges.') }}</div>@endif
    </div></div>
    <div class="col-md-6"><div class="panel p-3 p-md-4 h-100">
        <h2 class="small text-slate fw-normal mb-1">{{ __('Payment method') }}</h2>
        <div class="fs-5 fw-semibold mt-1">{{ $customer->payment_method_label ?? __('None saved yet') }}</div>
        @if ($customer->card_exp_month)<div class="small text-slate">{{ __('Expires :date', ['date' => sprintf('%02d/%d', $customer->card_exp_month, $customer->card_exp_year)]) }}</div>@endif
        @if ($canUpdateMethod && ! request()->attributes->get('viewing_as_admin'))<a class="btn btn-outline-primary btn-sm mt-3" href="{{ route('account.payment-method', $customer) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-card"/></svg>{{ __('Update payment method') }}</a>@endif
    </div></div>
</div>

<div class="panel mb-3"><div class="panel-h"><h2>{{ __('Agreements') }}</h2></div>
    @forelse ($customer->contracts as $k)
        <div class="d-flex justify-content-between align-items-center gap-2 px-3 py-2 border-bottom">
            <div><b>{{ $k->number }}</b> <span class="text-slate small">{{ __($k->type->value === 'renewal' ? 'Renewal' : 'Initial') }}, {{ __('signed :date', ['date' => $k->signed_at->setTimezone($tz)->translatedFormat('M j, Y')]) }}@if ($k->starts_on) · {{ __('term :start to :end', ['start' => $k->starts_on->translatedFormat('M j, Y'), 'end' => $k->ends_on?->translatedFormat('M j, Y')]) }}@endif</span></div>
            @if ($k->pdf_path)<a class="btn btn-sm btn-outline-secondary" href="{{ route('account.agreement-pdf', [$customer, $k]) }}" aria-label="{{ __('Download agreement :number (PDF)', ['number' => $k->number]) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>PDF</a>@endif
        </div>
    @empty<div class="p-3 text-slate">{{ __('No signed agreements yet.') }}</div>@endforelse
</div>

<div class="panel"><div class="panel-h"><h2>{{ __('Invoices and receipts') }}</h2></div>
    @if ($customer->invoices->isEmpty())<div class="p-3 text-slate">{{ __('No invoices yet.') }}</div>@else
    <div class="table-responsive"><table class="table mb-0">
        <caption class="visually-hidden">{{ __('Invoices and receipts') }}</caption>
        <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="visually-hidden">{{ __('Download') }}</span></th></tr></thead>
        <tbody>@foreach ($customer->invoices as $i)
            @php [$sLabel, $sCls] = $invStatus[$i->status->value] ?? [ucfirst($i->status->value), 'st-draft']; @endphp
            <tr><td class="small text-nowrap">{{ ($i->paid_at ?? $i->failed_at ?? $i->created_at)->setTimezone($tz)->translatedFormat('M j, Y') }}</td>
                <td class="small">{{ $typeLabel($i->type) }}@if ($i->period_start), {{ $i->period_start->translatedFormat('M Y') }}@endif<div class="text-slate">{{ $i->number }}</div></td>
                <td class="text-end num">{{ $m($i->amount_cents + $i->tax_cents) }}</td>
                <td><span class="st {{ $sCls }}">{{ $sLabel }}</span></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('account.invoice-pdf', [$customer, $i]) }}" aria-label="{{ __('Download :number (PDF)', ['number' => $i->number]) }}">PDF</a></td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div>
<p class="small text-slate mt-4">{{ __('Need to change your agent count or anything else? Reply to any RightAlly email or write to :email.', ['email' => app(\App\Services\Settings\SettingsService::class)->get('company', 'support_email')]) }}</p>
@endsection

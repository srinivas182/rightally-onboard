@extends('layouts.admin')
@section('title', 'Custom quotes')
@section('menu', 'quotes')
@section('content')
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $tz = \App\Support\BusinessClock::timezone();
    $pill = ['open' => ['Open', 'st-live'], 'used' => ['Signed', 'st-wait'], 'expired' => ['Expired', 'st-draft'], 'voided' => ['Voided', 'st-draft']];
@endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div><h1 class="h3 mb-1">Custom quotes</h1><div class="text-slate small">Negotiated pricing as a one-time onboarding link. Coupons don’t apply on top.</div></div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quoteNew"><svg class="ic me-1" aria-hidden="true"><use href="#i-plus"/></svg>New quote</button>
</div>
<div class="panel">
    @if ($quotes->isEmpty())
        <div class="p-4 p-md-5 text-center"><h2 class="h5">No quotes yet</h2><p class="text-slate">Create a quote when you’ve agreed different pricing with a brokerage, then send them its link.</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#quoteNew">Create a quote</button></div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Quote</th><th class="text-end">Set-up</th><th class="text-end">Monthly</th><th>Go-live</th><th>Expires</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>@foreach ($quotes as $q)
            @php [$label, $cls] = $pill[$q->status()]; @endphp
            <tr class="{{ session('new_quote') === $q->id ? 'table-info' : '' }}">
                <td><b>{{ $q->label }}</b>@if ($q->is_existing_client) <span class="badge text-bg-info">Existing client</span>@endif<div class="small text-slate">{{ $q->company_name }}{{ $q->email ? ' · '.$q->email : '' }} · by {{ $q->creator?->name ?? '—' }}</div></td>
                <td class="text-end num">{{ $m($q->setup_fee_cents) }}<div class="small text-slate">{{ rtrim(rtrim((string) $q->deposit_percent, '0'), '.') }}% deposit</div></td>
                <td class="text-end num">{{ $m($q->platform_fee_cents) }} + {{ $m($q->per_agent_fee_cents) }}/agent<div class="small text-slate">min {{ $q->min_agents }} agents</div></td>
                <td class="small">@if ($q->is_existing_client)First charge {{ $q->first_charge_on?->format('M j') }}<div class="text-slate">{{ $q->term_months ? $q->term_months.' months' : 'Month to month' }}</div>@else{{ $q->go_live_days }} days @endif</td>
                <td class="small">{{ $q->expires_at->setTimezone($tz)->format('M j, Y') }}</td>
                <td><span class="st {{ $cls }}">{{ $label }}</span>@if ($q->customer)<div class="small"><a href="{{ route('admin.customers.show', $q->customer) }}">{{ $q->customer->company_name }}</a></div>@endif</td>
                <td class="text-end text-nowrap">
                    @if ($q->status() === 'open')
                        <button class="btn btn-sm btn-outline-primary" type="button" data-copy="{{ $q->link() }}">Copy link</button>
                        <form method="post" action="{{ route('admin.quotes.void', $q) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" data-confirm="Void this quote? Its link will stop working.">Void</button></form>
                    @endif
                </td>
            </tr>
        @endforeach</tbody>
    </table></div>
    <div class="p-3 border-top">{{ $quotes->links() }}</div>
    @endif
</div>

<div class="modal fade" id="quoteNew" tabindex="-1" aria-labelledby="quoteNewTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.quotes.store') }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5" id="quoteNewTitle">New custom quote</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="row g-3">
            <div class="col-sm-4"><label class="form-label" for="q_label">Name</label><input class="form-control" id="q_label" name="label" required maxlength="120" value="{{ old('label') }}" placeholder="e.g. Harbor Point, NAR deal"></div>
            <div class="col-sm-4"><label class="form-label" for="q_company">Brokerage <span class="text-slate">(pre-fills)</span></label><input class="form-control" id="q_company" name="company_name" maxlength="160" value="{{ old('company_name') }}"></div>
            <div class="col-sm-4"><label class="form-label" for="q_email">Email <span class="text-slate">(pre-fills)</span></label><input type="email" class="form-control" id="q_email" name="email" maxlength="160" value="{{ old('email') }}"></div>
            <div class="col-sm-4"><label class="form-label" for="q_setup">Set-up fee</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" id="q_setup" name="setup_fee" required value="{{ old('setup_fee', $defaults['setup_fee']) }}"></div></div>
            <div class="col-sm-4"><label class="form-label" for="q_dep">Deposit</label><div class="input-group"><input type="number" step="0.01" min="0" max="100" class="form-control" id="q_dep" name="deposit_percent" required value="{{ old('deposit_percent', $defaults['deposit_percent']) }}"><span class="input-group-text">%</span></div></div>
            <div class="col-sm-4"><label class="form-label" for="q_days">Go-live after</label><div class="input-group"><input type="number" min="1" max="365" class="form-control" id="q_days" name="go_live_days" required value="{{ old('go_live_days', $defaults['go_live_days']) }}"><span class="input-group-text">days</span></div></div>
            <div class="col-sm-4"><label class="form-label" for="q_plat">Platform fee / month</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" id="q_plat" name="platform_fee" required value="{{ old('platform_fee', $defaults['platform_fee']) }}"></div></div>
            <div class="col-sm-4"><label class="form-label" for="q_agent">Per agent / month</label><div class="input-group"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" id="q_agent" name="per_agent_fee" required value="{{ old('per_agent_fee', $defaults['per_agent_fee']) }}"></div></div>
            <div class="col-sm-4"><label class="form-label" for="q_min">Minimum agents</label><input type="number" min="1" class="form-control" id="q_min" name="min_agents" required value="{{ old('min_agents', $defaults['min_agents']) }}"></div>
            <div class="col-sm-4"><label class="form-label" for="q_exp">Link expires</label><input type="date" class="form-control" id="q_exp" name="expires_on" required value="{{ old('expires_on', $defaultExpiry) }}"></div>
            <div class="col-12"><div class="border rounded p-3 bg-body-tertiary">
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_existing_client" value="1" id="q_existing" @checked(old('is_existing_client')) data-bs-toggle="collapse" data-bs-target="#q_existingBox" aria-expanded="{{ old('is_existing_client') ? 'true' : 'false' }}"><label class="form-check-label fw-semibold" for="q_existing">Existing client (already live, already billed in Stripe)</label></div>
                <div class="collapse {{ old('is_existing_client') ? 'show' : '' }}" id="q_existingBox"><div class="row g-3">
                    <div class="col-12 small text-slate">No set-up fee or deposit. They sign a subscription-only agreement and confirm their payment method; nothing is charged that day. Their old Stripe subscription stops renewing, and the new one starts on the first charge date. Set the platform fee to 0 if they only pay per agent.</div>
                    <div class="col-sm-5"><label class="form-label" for="q_oldsub">Current Stripe subscription ID</label><input class="form-control font-monospace @error('old_stripe_subscription_id') is-invalid @enderror" id="q_oldsub" name="old_stripe_subscription_id" value="{{ old('old_stripe_subscription_id') }}" placeholder="sub_…">@error('old_stripe_subscription_id')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">From Stripe > Customers > their subscription. Used to reuse their card and avoid double billing.</div></div>
                    <div class="col-sm-4"><label class="form-label" for="q_first">First charge date</label><input type="date" class="form-control @error('first_charge_on') is-invalid @enderror" id="q_first" name="first_charge_on" value="{{ old('first_charge_on') }}">@error('first_charge_on')<div class="invalid-feedback">{{ $message }}</div>@enderror<div class="form-text">Blank = their current renewal date.</div></div>
                    <div class="col-sm-3"><label class="form-label" for="q_term">Term</label><select class="form-select" id="q_term" name="term_months">@foreach ([0 => 'Month to month', 12 => '12 months', 24 => '24 months', 6 => '6 months'] as $k => $l)<option value="{{ $k }}" @selected((string) old('term_months', '12') === (string) $k)>{{ $l }}</option>@endforeach</select></div>
                </div></div>
            </div></div>
            <div class="col-sm-8"><label class="form-label" for="q_note">Note shown to the client <span class="text-slate">(optional)</span></label><input class="form-control" id="q_note" name="note" maxlength="500" value="{{ old('note') }}" placeholder="e.g. Pricing agreed with Srini on Sep 23"></div>
        </div><div class="form-text mt-3">The term stays 12 months. The link works once: it’s used up when the client signs.</div></div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create quote</button></div>
    </form></div></div>
@if ($errors->any())<div data-open-modal="#quoteNew" hidden></div>@endif
@endsection

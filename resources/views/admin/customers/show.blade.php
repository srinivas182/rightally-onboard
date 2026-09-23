@extends('layouts.admin')
@section('title', $customer->company_name)
@section('menu', 'customers')
@section('content')
@php
    $tz = \App\Support\BusinessClock::timezone();
    $m = fn (int $c) => \App\Support\Money::format($c);
    $invPill = ['paid' => 'st-live', 'processing' => 'st-wait', 'scheduled' => 'st-draft', 'failed' => 'st-fail', 'void' => 'st-draft'];
    $mailPill = ['sent' => 'st-live', 'delivered' => 'st-live', 'opened' => 'st-live', 'queued' => 'st-wait', 'logged' => 'st-susp', 'failed' => 'st-fail', 'bounced' => 'st-fail'];
    $canInvoices = auth('admin')->user()->can('menu.invoices');
    $canContracts = auth('admin')->user()->can('menu.contracts');
    $isActive = in_array($customer->status, [\App\Enums\CustomerStatus::Live, \App\Enums\CustomerStatus::PaymentFailed, \App\Enums\CustomerStatus::AwaitingGoLive, \App\Enums\CustomerStatus::BalanceFailed], true);
@endphp
<a href="{{ route('admin.customers.index') }}" class="small text-decoration-none d-inline-flex align-items-center gap-1 mb-2"><svg class="ic" aria-hidden="true"><use href="#i-arrow"/></svg>Customers</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">{{ $customer->company_name }}</h1>
        <div class="text-slate">{{ $customer->fullName() }}, {{ $customer->title }} · <a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a> · {{ \App\Support\UsPhone::format($customer->phone_e164) }}</div>
        <div class="text-slate small">{{ $customer->street }}, {{ $customer->city }}, {{ $customer->state_code }} {{ $customer->zip }}@if ($customer->source) · Source: {{ $customer->source }}@endif</div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="st {{ $customer->status->pill() }}">{{ $customer->status->label() }}</span>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Actions</button>
            <ul class="dropdown-menu dropdown-menu-end">
                @if ($contract)<li><form method="post" action="{{ route('admin.customers.resend-welcome', $customer) }}">@csrf<button class="dropdown-item">Resend welcome email and agreement</button></form></li>@endif
                <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#sendEmailModal" @disabled($customEmails->isEmpty())>Send a custom email…</button></li>
                <li><hr class="dropdown-divider"></li>
                @if ($customer->status === \App\Enums\CustomerStatus::Suspended)
                    <li><form method="post" action="{{ route('admin.customers.reactivate', $customer) }}">@csrf<button class="dropdown-item">Reactivate account</button></form></li>
                @elseif ($isActive)
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#suspendModal">Suspend account…</button></li>
                @endif
                @if ($customer->stripe_customer_id)<li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#creditModal">Add a credit…</button></li>@endif
                @if ($customer->status === \App\Enums\CustomerStatus::Paused)
                    <li><form method="post" action="{{ route('admin.customers.resume', $customer) }}">@csrf<button class="dropdown-item" data-confirm="Resume now? Monthly charges restart on the next billing date.">Resume subscription now</button></form></li>
                @elseif (! $pauseProblem)
                    <li><button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#pauseModal">Pause subscription…</button></li>
                @endif
                @if ($termination)<li><button class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#terminateModal">Early termination…</button></li>@endif
            </ul>
        </div>
    </div>
</div>

@foreach ($pendingApprovals as $pa)
    <div class="alert alert-warning small d-flex justify-content-between align-items-center flex-wrap gap-2"><div><b>Waiting for approval:</b> {{ $pa->label() }} of {{ \App\Support\Money::format($pa->amount_cents) }}, requested by {{ $pa->requester->name }}.</div><a class="btn btn-sm btn-outline-dark" href="{{ route('admin.approvals.index') }}">Open approvals</a></div>
@endforeach
@if ($customer->status === \App\Enums\CustomerStatus::Paused)
    <div class="alert alert-secondary small">Paused until {{ $customer->paused_until?->format('M j, Y') }}. No monthly charges until then; the minimum term has been extended.</div>
@endif
@if ($customer->email_bounced_at)
    <div class="alert alert-danger small"><b>Emails to {{ $customer->email }} are bouncing</b> ({{ $customer->email_bounce_reason }}, {{ $customer->email_bounced_at->setTimezone($tz)->format('M j') }}). Payment and renewal emails aren’t reaching them: call to get a working address, then update it.</div>
@endif
@if ($newToken)
    <div class="alert alert-warning" role="alert">
        <b>New agent API token</b>, shown once. Give it to whoever configures {{ $customer->company_name }}’s RightAlly site.
        <div class="input-group mt-2" style="max-width:560px"><input class="form-control font-monospace" value="{{ $newToken }}" readonly aria-label="API token"><button class="btn btn-outline-secondary" type="button" data-copy="{{ $newToken }}">Copy</button></div>
    </div>
@endif

<ul class="nav nav-tabs tabs-scroll mb-4" role="tablist" data-remember-tab="customer">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#invoices">Invoices <span class="badge text-bg-light">{{ $customer->invoices->count() }}</span></a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#contracts">Agreements</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#agents">Agent count history</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#emails">Emails</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#activity">Activity</a></li>
</ul>

<div class="tab-content">
<div class="tab-pane fade show active" id="overview" role="tabpanel">
<div class="row g-3">
    <div class="col-xl-7">
        <div class="panel mb-3"><div class="panel-h"><h2>Go-live date</h2></div><div class="p-3 p-md-4">
            @if ($customer->go_live_date)
                <form method="post" action="{{ route('admin.customers.go-live', $customer) }}" class="row g-2 align-items-end">@csrf @method('put')
                    <div class="col-sm-6"><label class="form-label" for="go_live_date">Go-live date</label>
                        <input type="date" class="form-control @error('go_live_date') is-invalid @enderror" id="go_live_date" name="go_live_date" value="{{ old('go_live_date', $customer->go_live_date->toDateString()) }}" @disabled(! $canChangeGoLive)
                               min="{{ \App\Support\BusinessClock::today()->addDays((int) config('rightally.go_live_lock_days'))->toDateString() }}">
                        @error('go_live_date')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <div class="col-sm-6">@if ($canChangeGoLive)<button class="btn btn-primary" data-confirm="Move the go-live date? The client will be emailed, and the balance will be charged on the new date.">Save go-live date</button>@endif</div>
                    <div class="col-12 lock-note mt-2"><svg class="ic mt-1" aria-hidden="true"><use href="#i-lock"/></svg>
                        @if ($canChangeGoLive) Can be changed until {{ $lockDate->format('M j, Y') }}. Moving it emails the client and moves the balance charge.
                        @elseif ($customer->live_at) Live since {{ $customer->live_at->setTimezone($tz)->format('M j, Y') }}.
                        @else Locked: the date can’t change within {{ config('rightally.go_live_lock_days') }} days of go-live.@endif
                    </div>
                </form>
            @else
                <p class="text-slate mb-0">Set when the client signs their agreement.</p>
            @endif
        </div></div>

        <div class="panel mb-3"><div class="panel-h"><h2>Agents billed</h2><span class="small text-slate">@if ($customer->agent_count_synced_at)Last synced {{ $customer->agent_count_synced_at->setTimezone($tz)->format('M j, g:i A') }}@endif</span></div><div class="p-3 p-md-4">
            <form method="post" action="{{ route('admin.customers.agents', $customer) }}" class="row g-2 align-items-end">@csrf @method('put')
                <div class="col-sm-4"><label class="form-label" for="agent_count">Agents</label><input type="number" class="form-control @error('agent_count') is-invalid @enderror" id="agent_count" name="agent_count" min="1" value="{{ old('agent_count', $customer->agent_count) }}" required></div>
                <div class="col-sm-5"><label class="form-label" for="note">Note <span class="text-slate">(optional)</span></label><input class="form-control" id="note" name="note" maxlength="200" placeholder="e.g. Confirmed by phone"></div>
                <div class="col-sm-3"><button class="btn btn-primary w-100">Save</button></div>
                <div class="col-12 form-text">Minimum {{ $contract?->min_agents ?? 5 }}. Changes apply from the next monthly charge, without pro-rating.@if ($contract) Monthly fee now {{ $m($contract->monthlyFeeCents($customer->agent_count)) }}.@endif</div>
            </form>
        </div></div>

        <div class="panel"><div class="panel-h"><h2>Live site and agent API</h2></div><div class="p-3 p-md-4">
            <form method="post" action="{{ route('admin.customers.live', $customer) }}" class="row g-3">@csrf @method('put')
                <div class="col-sm-6"><label class="form-label" for="live_url">Live URL</label><input class="form-control @error('live_url') is-invalid @enderror" id="live_url" name="live_url" value="{{ old('live_url', $customer->live_url) }}" placeholder="https://sunline.rightally.io">@error('live_url')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-sm-6"><label class="form-label" for="live_host">Live host</label><input class="form-control @error('live_host') is-invalid @enderror" id="live_host" name="live_host" value="{{ old('live_host', $customer->live_host) }}" placeholder="sunline.rightally.io">@error('live_host')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="col-12"><button class="btn btn-primary">Save live site</button></div>
            </form>
            <hr>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="small"><b>Agent API token:</b> {{ $customer->agent_api_token_hash ? 'set' : 'not created yet' }}.
                    <span class="text-slate">Used by the customer’s site to report agents (<span class="font-monospace">POST /api/v1/agent-count</span>) and by our daily pull from the live URL.</span></div>
                <form method="post" action="{{ route('admin.customers.token', $customer) }}">@csrf
                    <button class="btn btn-outline-secondary btn-sm" @if ($customer->agent_api_token_hash) data-confirm="Create a new token? The current one stops working immediately." @endif><svg class="ic me-1" aria-hidden="true"><use href="#i-key"/></svg>{{ $customer->agent_api_token_hash ? 'Regenerate token' : 'Create token' }}</button>
                </form>
            </div>
        </div></div>
    </div>

    <div class="col-xl-5">
        <div class="panel mb-3"><div class="panel-h"><h2>Contact</h2></div><div class="p-3 p-md-4">
            <form method="post" action="{{ route('admin.customers.contact', $customer) }}" class="row g-2">@csrf @method('put')
                <div class="col-sm-7"><label class="form-label small" for="c_email">Email (receipts and notices)</label><input type="email" class="form-control form-control-sm" id="c_email" name="email" value="{{ old('email', $customer->email) }}" required></div>
                <div class="col-sm-5"><label class="form-label small" for="c_phone">Phone</label><input class="form-control form-control-sm" id="c_phone" name="phone" value="{{ old('phone', \App\Support\UsPhone::format($customer->phone_e164)) }}" required></div>
                @if ($errors->contact->any())<div class="col-12 small text-danger">{{ $errors->contact->first() }}</div>@endif
                <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save contact</button></div>
            </form>
        </div></div>
        <div class="panel"><div class="panel-h"><h2>Billing</h2></div><div class="p-3 p-md-4">
            @if ($contract)
                <dl class="row small mb-3">
                    <dt class="col-5 fw-normal text-slate">Agreement</dt><dd class="col-7">{{ $contract->number }} ({{ $contract->type->value }})</dd>
                    <dt class="col-5 fw-normal text-slate">Term</dt><dd class="col-7">{{ $contract->starts_on?->format('M j, Y') }} to {{ $contract->ends_on?->format('M j, Y') }}</dd>
                    <dt class="col-5 fw-normal text-slate">Implementation</dt><dd class="col-7">{{ $m($contract->implementation_fee_cents) }}@if ($contract->coupon_code) ({{ $contract->coupon_code }}, {{ rtrim(rtrim((string) $contract->discount_percent, '0'), '.') }}% off)@endif</dd>
                    <dt class="col-5 fw-normal text-slate">Monthly</dt><dd class="col-7">{{ $m($contract->platform_fee_cents) }} + {{ $m($contract->per_agent_fee_cents) }} × {{ $customer->agent_count }} = <b>{{ $m($contract->monthlyFeeCents($customer->agent_count)) }}</b></dd>
                    @if ($contract->isAnnual())<dt class="col-5 fw-normal text-slate">Billing</dt><dd class="col-7"><b>Yearly</b>, {{ $m($contract->annualFeeCents($customer->agent_count)) }} a year ({{ rtrim(rtrim((string) $contract->annual_discount_percent, '0'), '.') }}% off)</dd>@endif
                    <dt class="col-5 fw-normal text-slate">Payment method</dt><dd class="col-7">{{ $customer->payment_method_label ?? '—' }}</dd>
                    @if ($customer->stripe_customer_id)<dt class="col-5 fw-normal text-slate">Stripe</dt><dd class="col-7 font-monospace small">{{ $customer->stripe_customer_id }}</dd>@endif
                </dl>
                <ol class="tl">
                    @foreach ($customer->invoices->sortBy('id')->take(-4) as $inv)
                        <li class="{{ $inv->status->value === 'paid' ? 'now' : '' }}"><span class="n">{{ $loop->iteration }}</span>
                            <div class="d-flex justify-content-between gap-2"><div><b>{{ $inv->type->label() }}</b><div class="small text-slate">{{ ($inv->paid_at ?? $inv->failed_at ?? $inv->created_at)->setTimezone($tz)->format('M j, Y') }}</div></div>
                            <div class="text-end"><div class="num fw-semibold">{{ $m($inv->amount_cents) }}</div><span class="st {{ $invPill[$inv->status->value] ?? 'st-draft' }}">{{ ucfirst($inv->status->value) }}</span></div></div></li>
                    @endforeach
                    @if ($customer->status === \App\Enums\CustomerStatus::AwaitingGoLive && $customer->go_live_date)
                        <li><span class="n">·</span><div class="d-flex justify-content-between gap-2"><div><b>Balance</b><div class="small text-slate">Scheduled {{ $customer->go_live_date->format('M j, Y') }}</div></div><div class="text-end"><div class="num fw-semibold">{{ $m($contract->balance_cents) }}</div><span class="st st-draft">Scheduled</span></div></div></li>
                    @endif
                </ol>
                @if ($canContracts && $contract->pdf_path)<a class="small" href="{{ route('admin.contracts.pdf', $contract) }}">Download signed agreement (PDF)</a>@endif
            @else
                <p class="text-slate mb-0">No signed agreement yet. The client started onboarding {{ $customer->onboarding_started_at?->setTimezone($tz)->format('M j, Y g:i A') }}.</p>
            @endif
        </div></div>
    </div>
</div>
</div>

<div class="tab-pane fade" id="invoices" role="tabpanel"><div class="panel">
    @if ($customer->invoices->isEmpty())<div class="p-4 text-center text-slate">No invoices yet.</div>@else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Invoice</th><th>Type</th><th class="text-end">Amount</th><th>Date</th><th>Status</th><th></th></tr></thead>
        <tbody>@foreach ($customer->invoices as $inv)
            <tr><td class="num">{{ $inv->number }}</td><td>{{ $inv->type->label() }}@if ($inv->period_start)<div class="small text-slate">{{ $inv->period_start->format('M j') }} to {{ $inv->period_end?->format('M j, Y') }}, {{ $inv->agents_billed }} agents</div>@endif</td>
                <td class="text-end num">{{ $m($inv->amount_cents) }}</td><td class="small">{{ ($inv->paid_at ?? $inv->failed_at ?? $inv->created_at)->setTimezone($tz)->format('M j, Y') }}</td>
                <td><span class="st {{ $invPill[$inv->status->value] ?? 'st-draft' }}">{{ ucfirst($inv->status->value) }}</span>@if ($inv->failure_reason && $inv->status->value === 'failed')<div class="small text-slate">{{ $inv->failure_reason }}</div>@endif</td>
                <td class="text-end text-nowrap">
                    @if ($canInvoices)<a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.invoices.pdf', $inv) }}" target="_blank" rel="noopener">PDF</a>@endif
                    @if ($inv->hosted_invoice_url)<a class="btn btn-sm btn-outline-secondary" href="{{ $inv->hosted_invoice_url }}" target="_blank" rel="noopener">Stripe page</a>@endif
                    @if (($refundable[$inv->id] ?? 0) > 0)<button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="modal" data-bs-target="#refund{{ $inv->id }}">Refund</button>@endif
                    @if ($canInvoices && $inv->status->value === 'failed' && $inv->hosted_invoice_url)<form method="post" action="{{ route('admin.invoices.resend', $inv) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-primary">Resend link</button></form>@endif
                </td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div></div>

<div class="tab-pane fade" id="contracts" role="tabpanel"><div class="panel">
    @if ($customer->contracts->isEmpty())<div class="p-4 text-center text-slate">No agreements yet.</div>@else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Agreement</th><th>Type</th><th>Status</th><th>Signed</th><th>Term</th><th></th></tr></thead>
        <tbody>@foreach ($customer->contracts as $k)
            <tr><td class="num">{{ $k->number }}</td><td>{{ ucfirst($k->type->value) }} (template v{{ $k->template->version }})</td><td>{{ ucfirst($k->status->value) }}</td>
                <td class="small">{{ $k->signed_at?->setTimezone($tz)->format('M j, Y g:i A') ?? '—' }}</td>
                <td class="small">{{ $k->starts_on?->format('M j, Y') }} to {{ $k->ends_on?->format('M j, Y') }}</td>
                <td class="text-end">@if ($canContracts && $k->pdf_path)<a class="btn btn-sm btn-outline-primary" href="{{ route('admin.contracts.pdf', $k) }}"><svg class="ic me-1" aria-hidden="true"><use href="#i-down"/></svg>PDF</a>@endif</td></tr>
            @if ($k->signed_at)<tr><td colspan="6" class="small text-slate border-top-0 pt-0">Signed by {{ $k->client_typed_name }} from IP {{ $k->signer_ip }}. PDF SHA-256: <span class="font-monospace">{{ $k->document_sha256 }}</span></td></tr>@endif
        @endforeach</tbody>
    </table></div>@endif
</div></div>

<div class="tab-pane fade" id="agents" role="tabpanel"><div class="panel">
    @if ($customer->agentCountLogs->isEmpty())<div class="p-4 text-center text-slate">No changes yet.</div>@else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th class="text-end">From</th><th class="text-end">To</th><th>Source</th><th>Note</th></tr></thead>
        <tbody>@foreach ($customer->agentCountLogs as $log)
            <tr><td class="small">{{ $log->created_at->setTimezone($tz)->format('M j, Y g:i A') }}</td><td class="text-end num">{{ $log->old_count ?? '—' }}</td><td class="text-end num">{{ $log->new_count }}</td>
                <td class="small">{{ ['onboarding' => 'Onboarding form', 'admin' => 'Admin', 'api_push' => 'Customer site (push)', 'api_pull' => 'Daily sync'][$log->source->value] ?? $log->source->value }}</td><td class="small">{{ $log->note }}</td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div></div>

<div class="tab-pane fade" id="emails" role="tabpanel"><div class="panel">
    <div class="panel-h"><span class="small text-slate">Emails sent to this customer</span>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#sendEmailModal" @disabled($customEmails->isEmpty())><svg class="ic me-1" aria-hidden="true"><use href="#i-send"/></svg>Send custom email</button></div>
    @if ($customer->emailLogs->isEmpty())<div class="p-4 text-center text-slate">No emails yet.</div>@else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Sent</th><th>Subject</th><th>To</th><th>Status</th></tr></thead>
        <tbody>@foreach ($customer->emailLogs as $l)
            <tr><td class="small text-nowrap">{{ $l->created_at->setTimezone($tz)->format('M j, g:i A') }}</td><td class="small">{{ $l->subject }}</td>
                <td class="small">{{ $l->to_email }}@if ($l->cc)<div class="text-slate">CC {{ implode(', ', $l->cc) }}</div>@endif</td>
                <td><span class="st {{ $mailPill[$l->status] ?? 'st-draft' }}">{{ ucfirst($l->status) }}</span>@if ($l->error)<div class="small text-slate">{{ $l->error }}</div>@endif</td></tr>
        @endforeach</tbody>
    </table></div>@endif
</div></div>

<div class="tab-pane fade" id="activity" role="tabpanel"><div class="panel">
    @if ($activity->isEmpty())<div class="p-4 text-center text-slate">No activity yet.</div>@else
    <ul class="list-unstyled mb-0">@foreach ($activity as $a)
        <li class="px-3 py-2 border-bottom"><div>{{ $a->description }}</div>
            <div class="small text-slate">{{ $a->created_at->setTimezone($tz)->format('M j, Y g:i A') }} · {{ $a->admin?->name ?? ucfirst($a->actor_type) }}@if ($a->ip) · {{ $a->ip }}@endif</div></li>
    @endforeach</ul>@endif
</div></div>
</div>

{{-- Refund modals --}}
@foreach ($customer->invoices as $inv)
    @if (($refundable[$inv->id] ?? 0) > 0)
    <div class="modal fade" id="refund{{ $inv->id }}" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="{{ route('admin.customers.refund', [$customer, $inv]) }}">@csrf
            <div class="modal-header"><h2 class="modal-title h5">Refund {{ $inv->number }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label class="form-label">Amount (up to {{ $m($refundable[$inv->id]) }})</label>
                <div class="input-group mb-3"><span class="input-group-text">$</span><input type="number" step="0.01" min="0.01" max="{{ $refundable[$inv->id] / 100 }}" class="form-control" name="amount" value="{{ number_format($refundable[$inv->id] / 100, 2, '.', '') }}" required></div>
                <label class="form-label">Reason (the client sees this)</label><input class="form-control" name="reason" required maxlength="250">
                <div class="form-text">Goes back to {{ $customer->payment_method_label ?? 'the original payment method' }}. Over {{ $m($threshold) }} needs a second admin.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Refund</button></div>
        </form></div></div>
    @endif
@endforeach

{{-- Credit --}}
<div class="modal fade" id="creditModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.customers.credit', $customer) }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5">Add a credit</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <label class="form-label">Amount</label><div class="input-group mb-3"><span class="input-group-text">$</span><input type="number" step="0.01" min="0.01" class="form-control" name="amount" required></div>
            <label class="form-label">Reason (the client sees this)</label><input class="form-control" name="reason" required maxlength="250" placeholder="e.g. Service outage on Oct 3">
            <div class="form-text">Taken off the next charge automatically. Over {{ $m($threshold) }} needs a second admin.</div>
            @if ($credits->isNotEmpty())<div class="small mt-3"><b>Earlier credits:</b> @foreach ($credits as $c){{ $m($c->amount_cents) }} ({{ $c->created_at->setTimezone($tz)->format('M j') }}, {{ $c->reason }})@if (! $loop->last); @endif @endforeach</div>@endif
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add credit</button></div>
    </form></div></div>

{{-- Pause --}}
<div class="modal fade" id="pauseModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.customers.pause', $customer) }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5">Pause subscription</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <p class="small text-slate">Up to {{ \App\Services\Billing\PauseService::MAX_MONTHS }} months, once in any 12 months. No monthly charges during the pause, their RightAlly site is told the account is paused, and the minimum term is extended by the pause. The client is emailed.</p>
            <label class="form-label">Length</label>
            <select class="form-select mb-3" name="months">@for ($i = 1; $i <= \App\Services\Billing\PauseService::MAX_MONTHS; $i++)<option value="{{ $i }}">{{ $i }} {{ Str::plural('month', $i) }}, until {{ \App\Support\BusinessClock::today()->addMonthsNoOverflow($i)->format('M j, Y') }}</option>@endfor</select>
            <label class="form-label">Reason</label><input class="form-control" name="reason" required maxlength="250" placeholder="e.g. Seasonal closure agreed with the client">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Pause</button></div>
    </form></div></div>

{{-- Send custom email --}}
<div class="modal fade" id="sendEmailModal" tabindex="-1" aria-labelledby="sendEmailTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.customers.send-email', $customer) }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5" id="sendEmailTitle">Send a custom email</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <label class="form-label" for="template_id">Email</label>
            <select class="form-select" id="template_id" name="template_id" required>@foreach ($customEmails as $t)<option value="{{ $t->id }}">{{ $t->name }}: {{ $t->subject }}</option>@endforeach</select>
            <div class="form-text">Goes to {{ $customer->email }}. Create and edit custom emails in Email templates.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Send email</button></div>
    </form></div></div>

{{-- Suspend --}}
<div class="modal fade" id="suspendModal" tabindex="-1" aria-labelledby="suspendTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.customers.suspend', $customer) }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5" id="suspendTitle">Suspend {{ $customer->company_name }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p class="text-slate small">Marks the account as suspended. Billing continues under the agreement. Accounts are also suspended automatically after 30 days unpaid.</p>
            <label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" name="reason" maxlength="200" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-warning">Suspend account</button></div>
    </form></div></div>

{{-- Early termination --}}
@if ($termination)
<div class="modal fade" id="terminateModal" tabindex="-1" aria-labelledby="terminateTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="{{ route('admin.customers.terminate', $customer) }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5" id="terminateTitle">Early termination</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <p>Under Section 7 of the agreement, the monthly fees for the rest of the minimum term become due now.</p>
            <table class="table table-sm num mb-3"><tbody>
                <tr><td>Months remaining</td><td class="text-end">{{ $termination['months'] }}</td></tr>
                <tr><td>Monthly fee ({{ $customer->agent_count }} agents)</td><td class="text-end">{{ $m($termination['monthly_cents']) }}</td></tr>
                <tr><td class="fw-semibold">Charged now</td><td class="text-end fw-semibold">{{ $m($termination['amount_cents']) }}</td></tr>
            </tbody></table>
            @if ($termination['unpaid_cents'])<div class="alert alert-warning small py-2">Unpaid invoices of {{ $m($termination['unpaid_cents']) }} stay due on top of this. Their payment links remain active.</div>@endif
            <p class="small text-slate">This goes to a second admin for approval. When approved, the subscription ends immediately, the amount is charged to {{ $customer->payment_method_label ?? 'the saved payment method' }}, and the client is emailed the invoice. This can’t be undone.</p>
            <div class="mb-3"><label class="form-label" for="treason">Reason</label><input class="form-control" id="treason" name="reason" maxlength="250" required placeholder="e.g. Client asked to cancel on Sep 30"></div>
            <label class="form-label" for="confirm">Type <b>{{ $customer->company_name }}</b> to confirm</label><input class="form-control @error('confirm') is-invalid @enderror" id="confirm" name="confirm" required autocomplete="off">
            @error('confirm')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Request approval to charge {{ $m($termination['amount_cents']) }}</button></div>
    </form></div></div>
@endif
@foreach (['refund' => null, 'credit' => '#creditModal', 'pause' => '#pauseModal'] as $bag => $modal)
    @if ($errors->$bag->any())<div class="alert alert-danger small">{{ $errors->$bag->first() }}</div>@if ($modal)<div data-open-modal="{{ $modal }}" hidden></div>@endif @endif
@endforeach
@if ($errors->has('confirm') || $errors->has('reason'))<div data-open-modal="{{ $errors->has('confirm') ? '#terminateModal' : '#suspendModal' }}" hidden></div>@endif
@endsection

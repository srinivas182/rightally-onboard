<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 54px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #041527; line-height: 1.45; }
    table { width: 100%; border-collapse: collapse; }
    .muted { color: #5E6B82; }
    .head td { vertical-align: top; }
    .title { font-size: 20pt; font-weight: bold; margin: 0; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 8pt; font-weight: bold; }
    .paid { background: #E3F5EE; color: #0B7A54; } .due { background: #FDECEE; color: #B32532; } .proc { background: #EAF0FE; color: #1446C0; }
    .parties td { vertical-align: top; width: 50%; padding-top: 22px; }
    .label { font-size: 7.5pt; color: #5E6B82; text-transform: none; margin-bottom: 3px; }
    .lines { margin-top: 26px; }
    .lines th { text-align: left; font-size: 8pt; color: #5E6B82; border-bottom: 1.5px solid #041527; padding: 6px 0; }
    .lines td { padding: 9px 0; border-bottom: 1px solid #DFE5EF; vertical-align: top; }
    .r, .lines th.r { text-align: right; }
    .totals { width: 45%; margin-left: 55%; margin-top: 12px; }
    .totals td { padding: 5px 0; }
    .totals .grand td { font-weight: bold; font-size: 11pt; border-top: 1.5px solid #041527; padding-top: 8px; }
    .note { margin-top: 26px; background: #F6F8FC; border: 1px solid #DFE5EF; padding: 10px 12px; font-size: 8.5pt; }
    .foot { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 7.5pt; color: #5E6B82; text-align: center; }
</style>
</head>
<body>
@php
    $m = fn (int $c) => \App\Support\Money::format($c);
    $tz = \App\Support\BusinessClock::timezone();
    $total = $invoice->amount_cents + $invoice->tax_cents;
    $refunded = (int) ($payment?->refunded_cents ?? 0);
    $status = $invoice->status->value;
    $desc = match ($invoice->type) {
        \App\Enums\InvoiceType::Deposit => 'Implementation deposit ('.rtrim(rtrim((string) $invoice->contract?->deposit_percent, '0'), '.').'% of implementation fee)',
        \App\Enums\InvoiceType::Balance => 'Implementation fee balance, due at go-live',
        \App\Enums\InvoiceType::Monthly => 'RightAlly subscription',
        \App\Enums\InvoiceType::Annual => 'RightAlly subscription, 12 months',
        \App\Enums\InvoiceType::EarlyTermination => 'Early termination: remaining months of the minimum term (Agreement Section 7)',
    };
@endphp
<table class="head"><tr>
    <td>@if ($logo)<img src="{{ $logo }}" style="height:26px" alt="RightAlly">@endif
        <div class="muted" style="margin-top:8px">{{ $company['legal_name'] }}@if ($company['dba']) d/b/a {{ $company['dba'] }}@endif<br>{{ $company['address'] }}<br>{{ $company['support_email'] }}@if ($company['phone']) · {{ $company['phone'] }}@endif</div></td>
    <td class="r">
        <p class="title">{{ $paid ? 'Receipt' : 'Invoice' }}</p>
        <div style="margin:6px 0">{{ $invoice->number }}</div>
        <span class="badge {{ $status === 'paid' ? 'paid' : ($status === 'processing' ? 'proc' : 'due') }}">{{ $status === 'paid' ? ($refunded >= $total ? 'REFUNDED' : 'PAID') : ($status === 'processing' ? 'PROCESSING' : 'AMOUNT DUE') }}</span>
    </td>
</tr></table>

<table class="parties"><tr>
    <td><div class="label">Billed to</div><b>{{ $customer->company_name }}</b><br>{{ $customer->fullName() }}, {{ $customer->title }}<br>{{ $customer->street }}<br>{{ $customer->city }}, {{ $customer->state_code }} {{ $customer->zip }}<br>{{ $customer->email }}</td>
    <td class="r">
        <div class="label">Invoice date</div>{{ ($invoice->due_on ?? $invoice->created_at->copy()->setTimezone($tz))->format('F j, Y') }}<br>
        @if ($paid && $invoice->paid_at)<div class="label" style="margin-top:8px">Paid</div>{{ $invoice->paid_at->copy()->setTimezone($tz)->format('F j, Y') }}@if ($payment?->method_label)<br>{{ $payment->method_label }}@endif
        @else<div class="label" style="margin-top:8px">Due</div>{{ $invoice->due_on?->format('F j, Y') }}@endif
        @if ($invoice->contract)<div class="label" style="margin-top:8px">Agreement</div>{{ $invoice->contract->number }}@endif
    </td>
</tr></table>

<table class="lines">
    <thead><tr><th>Description</th><th class="r">Amount</th></tr></thead>
    <tbody>
    @if ($invoice->type === \App\Enums\InvoiceType::Monthly && $invoice->contract)
        @php $agents = (int) ($invoice->agents_billed ?? $invoice->contract->agent_count); $platform = $invoice->contract->platform_fee_cents; @endphp
        <tr><td>Platform fee<div class="muted">{{ $invoice->period_start?->format('M j') }} to {{ $invoice->period_end?->format('M j, Y') }}</div></td><td class="r">{{ $m($platform) }}</td></tr>
        <tr><td>Agents: {{ $agents }} × {{ $m($invoice->contract->per_agent_fee_cents) }}<div class="muted">Minimum {{ $invoice->contract->min_agents }} agents</div></td><td class="r">{{ $m($invoice->amount_cents - $platform) }}</td></tr>
    @elseif ($invoice->type === \App\Enums\InvoiceType::Annual && $invoice->contract)
        @php $agents = (int) ($invoice->agents_billed ?? $invoice->contract->agent_count); $platform = $invoice->contract->platformYearCents(); @endphp
        <tr><td>Platform fee, 12 months<div class="muted">{{ $invoice->period_start?->format('M j, Y') }} to {{ $invoice->period_end?->format('M j, Y') }}, {{ rtrim(rtrim((string) $invoice->contract->annual_discount_percent, '0'), '.') }}% yearly discount applied</div></td><td class="r">{{ $m($platform) }}</td></tr>
        <tr><td>Agents: {{ $agents }} × {{ $m($invoice->contract->perAgentYearCents()) }} a year<div class="muted">Minimum {{ $invoice->contract->min_agents }} agents</div></td><td class="r">{{ $m($invoice->amount_cents - $platform) }}</td></tr>
    @else
        <tr><td>{{ $desc }}@if ($invoice->contract?->coupon_code && $invoice->type === \App\Enums\InvoiceType::Deposit)<div class="muted">Coupon {{ $invoice->contract->coupon_code }} applied to the implementation fee</div>@endif</td><td class="r">{{ $m($invoice->amount_cents) }}</td></tr>
    @endif
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="r">{{ $m($invoice->amount_cents) }}</td></tr>
    @if ($invoice->tax_cents)<tr><td>Sales tax</td><td class="r">{{ $m($invoice->tax_cents) }}</td></tr>@endif
    <tr class="grand"><td>{{ $paid ? 'Total paid' : 'Total due' }}</td><td class="r">{{ $m($total) }}</td></tr>
    @if ($refunded)<tr><td class="muted">Refunded</td><td class="r muted">-{{ $m($refunded) }}</td></tr>@endif
</table>

@if (! $paid && $invoice->hosted_invoice_url)
    <div class="note">Pay securely online: {{ $invoice->hosted_invoice_url }}</div>
@elseif ($paid)
    <div class="note">Thank you. This receipt confirms payment in full. Amounts in US dollars.</div>
@endif

<div class="foot">{{ $company['legal_name'] }}@if ($company['dba']) d/b/a {{ $company['dba'] }}@endif · {{ $company['address'] }}</div>
</body>
</html>

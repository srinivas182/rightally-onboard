@php $money = fn (int $cents) => \App\Support\Money::format($cents); @endphp
@if ($variant === 'rail')
<div class="ledger" aria-live="polite">
    <h2>Your agreement</h2>
    <div class="row-l"><span>Set-up fee</span><b data-l="setup">{{ $money($ledger['setup']) }}</b></div>
    <div class="row-l disc {{ $ledger['discount'] ? '' : 'd-none' }}" data-l-disc><span>Coupon <span data-l="code">{{ $ledger['code'] }}</span></span><b data-l="discount">-{{ $money($ledger['discount']) }}</b></div>
    <div class="row-l"><span>Implementation fee</span><b data-l="implementation">{{ $money($ledger['implementation']) }}</b></div>
    <div class="row-l"><span>Due at go-live</span><b data-l="balance">{{ $money($ledger['balance']) }}</b></div>
    <div class="row-l"><span>Monthly, <span data-l="agents">{{ $ledger['agents'] }}</span> agents</span><b data-l="monthly">{{ $money($ledger['monthly']) }}</b></div>
    <div class="today"><span>Due today</span><strong data-l="deposit">{{ $money($ledger['deposit']) }}</strong></div>
</div>
@else
<table class="table num mb-0"><tbody>
    <tr><td>Set-up fee</td><td class="text-end" data-l="setup">{{ $money($ledger['setup']) }}</td></tr>
    <tr class="{{ $ledger['discount'] ? '' : 'd-none' }}" data-l-disc><td>Coupon <span data-l="code">{{ $ledger['code'] }}</span></td><td class="text-end text-success" data-l="discount">-{{ $money($ledger['discount']) }}</td></tr>
    <tr><td>Implementation fee</td><td class="text-end" data-l="implementation">{{ $money($ledger['implementation']) }}</td></tr>
    <tr><td class="fw-semibold">Due today</td><td class="text-end fw-semibold" data-l="deposit">{{ $money($ledger['deposit']) }}</td></tr>
    <tr><td>Due at go-live</td><td class="text-end" data-l="balance">{{ $money($ledger['balance']) }}</td></tr>
    <tr><td>Monthly, <span data-l="agents">{{ $ledger['agents'] }}</span> agents</td><td class="text-end" data-l="monthly">{{ $money($ledger['monthly']) }}</td></tr>
</tbody></table>
@endif

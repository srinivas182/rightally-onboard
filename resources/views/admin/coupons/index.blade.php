@extends('layouts.admin')
@section('title', 'Coupons')
@section('menu', 'coupons')
@section('content')
@php
    $onboardUrl = rtrim(config('app.url'), '/');
    $status = function ($c) {
        if (! $c->is_active) return ['Off', 'st-draft'];
        if ($c->isExpired()) return ['Expired', 'st-susp'];
        if (! $c->isUsable()) return ['Limit reached', 'st-susp'];
        return ['Active', 'st-live'];
    };
@endphp
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <h1 class="h3 mb-0">Coupons</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#couponNew"><svg class="ic me-1" aria-hidden="true"><use href="#i-plus"/></svg>New coupon</button>
</div>

<div class="panel">
    @if ($coupons->isEmpty())
        <div class="p-4 p-md-5 text-center">
            <h2 class="h5">No coupons yet</h2>
            <p class="text-slate mb-3">Create a coupon to offer a percentage off the implementation fee, then share its link.</p>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#couponNew">Create your first coupon</button>
        </div>
    @else
    <div class="table-responsive"><table class="table">
        <thead><tr><th>Code</th><th>Name</th><th class="text-end">Discount</th><th>Expires</th><th class="text-end" title="Calls booked with this code (not counting cancelled)">Calls booked</th><th class="text-end" title="Agreements signed with this code">Signed</th><th class="text-end" title="Paid the deposit">Onboarded</th><th>Share link</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
        @foreach ($coupons as $c)
            @php [$label, $pill] = $status($c); $link = $onboardUrl.'/?coupon='.$c->code; @endphp
            <tr>
                <td class="font-monospace fw-semibold">{{ $c->code }}</td>
                <td>{{ $c->name }}</td>
                <td class="text-end num">{{ rtrim(rtrim((string) $c->percent_off, '0'), '.') }}%</td>
                <td class="small">{{ $c->expires_on?->format('M j, Y') ?? 'Never' }}</td>
                <td class="text-end num">@if ($calls[$c->code] ?? 0)<a href="{{ route('admin.calls.index', ['coupon' => $c->code, 'tab' => 'all']) }}">{{ $calls[$c->code] }}</a>@else 0 @endif</td>
                <td class="text-end num">{{ $c->times_used }}{{ $c->max_uses ? ' / '.$c->max_uses : '' }}</td>
                <td class="text-end num">{{ $onboarded[$c->id] ?? 0 }}</td>
                <td class="text-nowrap"><button type="button" class="btn btn-link btn-sm p-0" data-copy="{{ $link }}" title="{{ $link }}">Onboarding link</button><br>
                    <button type="button" class="btn btn-link btn-sm p-0" data-copy="{{ route('book', ['coupon' => $c->code]) }}" title="{{ route('book', ['coupon' => $c->code]) }}">Book-a-call link</button></td>
                <td><span class="st {{ $pill }}">{{ $label }}</span></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#coupon{{ $c->id }}">Edit</button></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    <div class="p-3 small text-slate border-top"><b>Calls booked</b>: calls booked on the calendar with this code (cancelled calls not counted). <b>Signed</b>: agreements signed with it; this is what the usage limit counts. <b>Onboarded</b>: of those, clients who paid the deposit. Used codes can’t be renamed, because signed agreements refer to them. Switch a coupon off instead of deleting it.</div>
    @endif
</div>

{{-- New coupon --}}
<div class="modal fade" id="couponNew" tabindex="-1" aria-labelledby="couponNewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('admin.coupons.store') }}">@csrf
        <div class="modal-header"><h2 class="modal-title h5" id="couponNewTitle">New coupon</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">@include('admin.coupons._form', ['c' => null, 'p' => 'new'])</div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Create coupon</button></div>
    </form></div>
</div>

{{-- Edit coupon --}}
@foreach ($coupons as $c)
<div class="modal fade" id="coupon{{ $c->id }}" tabindex="-1" aria-labelledby="coupon{{ $c->id }}Title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" method="post" action="{{ route('admin.coupons.update', $c) }}">@csrf @method('put')
        <div class="modal-header"><h2 class="modal-title h5" id="coupon{{ $c->id }}Title">Edit {{ $c->code }}</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">@include('admin.coupons._form', ['c' => $c, 'p' => 'c'.$c->id])</div>
        <div class="modal-footer"><button type="button" class="btn btn-link" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save coupon</button></div>
    </form></div>
</div>
@endforeach

@if ($errors->any() && old('_form'))
    <div data-open-modal="{{ old('_form') === 'new' ? '#couponNew' : '#coupon'.substr(old('_form'), 1) }}" hidden></div>
@endif
@endsection

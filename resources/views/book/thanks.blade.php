@extends('layouts.page')
@section('title', __('Your call is booked'))
@section('brand-center', '1')
@section('content')
<div class="text-center mx-auto" style="max-width:560px">
    <div class="d-inline-grid rounded-circle mb-3" style="width:64px;height:64px;place-items:center;background:rgba(14,143,99,.12);color:var(--bs-success)">
        <svg class="ic" style="width:32px;height:32px" aria-hidden="true"><use href="#i-check"/></svg>
    </div>
    <h1 class="h2">{{ __('Your call is booked') }}</h1>
    <p class="text-slate">{{ __('You’ll get a confirmation email with the Google Meet link and a calendar invite. Talk soon.') }}</p>
    <p class="mt-4 mb-1 fw-semibold">{{ __('Ready to get started before the call?') }}</p>
    <a class="btn btn-primary" href="{{ url('/').($coupon ? '?coupon='.urlencode($coupon) : '') }}">{{ __('Set up your brokerage') }}</a>
</div>
@endsection

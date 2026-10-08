@extends('layouts.page')
@section('title', __('By invitation'))
@section('brand-center', '1')
@section('field-errors-only', '1')
@section('content')
<div class="mx-auto" style="max-width:520px">
    <h1 class="h2 text-center mb-2">{{ __('RightAlly is currently by invitation') }}</h1>
    <p class="text-slate text-center mb-4">{{ __('Launch your brokerage’s own revenue share program: your plan, your rules, your brand, alongside the tools your agents already use.') }}<br>
        <b class="text-body">{{ __('Enter the referral code you were given to get started.') }}</b></p>
    @if ($quoteProblem)<div class="alert alert-warning">{{ $quoteProblem }}</div>@endif
    <form method="get" action="{{ url('/') }}" class="panel p-3 p-md-4">
        <label class="form-label fw-semibold" for="coupon">{{ __('Referral code') }}</label>
        <input class="form-control form-control-lg text-uppercase @if ($error) is-invalid @endif" id="coupon" name="coupon" value="{{ $code }}" required maxlength="40" autocomplete="off" autofocus @if ($error) aria-describedby="codeErr" @endif>
        @if ($error)<div class="invalid-feedback" id="codeErr">{{ $error }}</div>@endif
        <button class="btn btn-primary btn-lg w-100 mt-3">{{ __('Continue') }}</button>
    </form>
    <div class="text-center mt-4">
        <p class="mb-2">{{ __('No code yet?') }}</p>
        <a class="btn btn-outline-primary" href="{{ route('book') }}">{{ __('Book a call with us') }}</a>
        <p class="small text-slate mt-3">{{ __('Already started?') }} <a href="{{ route('account.login') }}">{{ __('Get a link to continue where you left off') }}</a></p>
    </div>
</div>
@endsection

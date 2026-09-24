@extends('layouts.page')
@section('title', __('Sign in'))
@section('field-errors-only', '1')
@section('content')
<div style="max-width:440px" class="mx-auto">
    <h1 class="h2 mb-1">{{ __('Sign in to your account') }}</h1>
    <p class="text-slate">{{ __('See your agreement, invoices and receipts, and update your payment method.') }}</p>
    <form method="post" action="{{ route('account.login.email') }}" class="mt-4" novalidate>@csrf
        <label class="form-label" for="email">{{ __('Email') }}</label>
        <input type="email" class="form-control form-control-lg mb-3 @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email" autofocus>
        @error('email')<div class="invalid-feedback d-block mb-3 mt-n2">{{ $message }}</div>@enderror
        <button class="btn btn-primary btn-lg w-100">{{ __('Next') }}</button>
    </form>
    <p class="small text-slate mt-4">{{ __('First time here? Enter your email and we’ll send you a link to create your password. Not finished setting up? We’ll send you a link to continue.') }}</p>
</div>
@endsection

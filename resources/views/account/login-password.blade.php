@extends('layouts.page')
@section('title', __('Sign in'))
@section('field-errors-only', '1')
@section('content')
<div style="max-width:440px" class="mx-auto">
    <h1 class="h2 mb-1">{{ __('Enter your password') }}</h1>
    <p class="text-slate">{{ $email }} · <a href="{{ route('account.login', ['email' => '']) }}">{{ __('Use a different email') }}</a></p>
    <form method="post" action="{{ route('account.login.password') }}" class="mt-4">@csrf
        <label class="form-label" for="password">{{ __('Password') }}</label>
        <input type="password" class="form-control form-control-lg @error('password') is-invalid @enderror" id="password" name="password" required autocomplete="current-password" autofocus>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-check my-3"><input class="form-check-input" type="checkbox" name="remember" value="1" id="remember"><label class="form-check-label" for="remember">{{ __('Keep me signed in on this device') }}</label></div>
        <button class="btn btn-primary btn-lg w-100">{{ __('Sign in') }}</button>
    </form>
    <form method="post" action="{{ route('account.forgot') }}" class="mt-3 text-center">@csrf
        <button class="btn btn-link">{{ __('Forgot password?') }}</button>
    </form>
</div>
@endsection

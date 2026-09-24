@extends('layouts.page')
@section('title', $firstTime ? __('Create your password') : __('Choose a new password'))
@section('field-errors-only', '1')
@section('content')
<div style="max-width:440px" class="mx-auto">
    <h1 class="h2 mb-1">{{ $firstTime ? __('Create your password') : __('Choose a new password') }}</h1>
    <p class="text-slate">{{ $email }}</p>
    <form method="post" action="{{ route('account.password.update') }}" class="mt-4">@csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <input type="hidden" name="email" value="{{ $email }}">
        <label class="form-label" for="password">{{ __('New password') }}</label>
        <input type="password" class="form-control form-control-lg @error('password') is-invalid @enderror" id="password" name="password" required autocomplete="new-password" minlength="10" aria-describedby="pwHelp" autofocus>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text mb-3" id="pwHelp">{{ __('At least 10 characters, with letters and numbers.') }}</div>
        <label class="form-label" for="password_confirmation">{{ __('Confirm password') }}</label>
        <input type="password" class="form-control form-control-lg mb-4" id="password_confirmation" name="password_confirmation" required autocomplete="new-password">
        <button class="btn btn-primary btn-lg w-100">{{ $firstTime ? __('Create password and sign in') : __('Save and sign in') }}</button>
    </form>
</div>
@endsection

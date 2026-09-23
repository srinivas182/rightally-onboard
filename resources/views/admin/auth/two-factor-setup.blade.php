@extends('layouts.auth')
@section('title', 'Set up two-factor authentication')
@section('content')
    <h1 class="h4 mb-1">Set up two-factor authentication</h1>
    <p class="text-slate mb-4">The admin controls billing, so every admin signs in with a code from an authenticator app such as Google Authenticator, Microsoft Authenticator or 1Password.</p>
    <ol class="ps-3 mb-4">
        <li class="mb-3">Scan this QR code with your authenticator app.
            <div class="qr-box mt-2">{!! $qr !!}</div>
            <div class="small text-slate mt-2">Can’t scan? Enter this key: <span class="font-monospace user-select-all">{{ $secret }}</span></div>
        </li>
        <li>Enter the 6-digit code the app shows.</li>
    </ol>
    <form method="post" action="{{ route('admin.two-factor.setup') }}">
        @csrf
        <input class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" aria-label="6-digit code" autofocus>
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <button class="btn btn-primary w-100 mt-3" type="submit">Turn on two-factor</button>
    </form>
    <form method="post" action="{{ route('admin.logout') }}" class="text-center mt-3">@csrf<button class="btn btn-link btn-sm" type="submit">Sign out</button></form>
@endsection

@extends('layouts.auth')
@section('title', 'Sign-in code')
@section('content')
    @if (session('status'))<div class="alert alert-success small">{{ session('status') }}</div>@endif
    @if (session('warning'))<div class="alert alert-warning small">{{ session('warning') }}</div>@endif

    @if ($emailMode)
        <h1 class="h4 mb-1">Check your email</h1>
        <p class="text-slate mb-4">Enter the 6-digit code we sent to {{ $maskedEmail }}. It works for {{ \App\Services\Auth\EmailLoginCode::MINUTES }} minutes.</p>
        <form method="post" action="{{ route('admin.two-factor.challenge') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="email_code">Code from email</label>
                <input class="form-control form-control-lg text-center font-monospace @error('email_code') is-invalid @enderror" id="email_code" name="email_code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus>
                @error('email_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary w-100" type="submit">Verify</button>
        </form>
        <form method="post" action="{{ route('admin.two-factor.email-code') }}" class="mt-3 text-center">@csrf
            <button class="btn btn-link btn-sm" type="submit">Send a new code</button>
        </form>
        @if ($hasApp)
            <p class="text-center small mt-2"><a href="{{ route('admin.two-factor.challenge') }}">Use my authenticator app instead</a></p>
        @endif
    @else
        <h1 class="h4 mb-1">Enter your authentication code</h1>
        <p class="text-slate mb-4">Open your authenticator app and enter the 6-digit code for RightAlly Admin.</p>
        <form method="post" action="{{ route('admin.two-factor.challenge') }}" id="codeForm">
            @csrf
            <div class="mb-3">
                <label class="form-label" for="code">Code</label>
                <input class="form-control form-control-lg text-center font-monospace @error('code') is-invalid @enderror" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" autofocus>
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button class="btn btn-primary w-100" type="submit">Verify</button>
        </form>
        <form method="post" action="{{ route('admin.two-factor.email-code') }}" class="mt-3 text-center">@csrf
            <button class="btn btn-link btn-sm" type="submit">Email me a code instead</button>
        </form>
        <details class="mt-3" @error('recovery_code') open @enderror>
            <summary class="small">Lost your phone? Use a recovery code</summary>
            <form method="post" action="{{ route('admin.two-factor.challenge') }}" class="mt-3">
                @csrf
                <label class="form-label" for="recovery_code">Recovery code</label>
                <input class="form-control font-monospace @error('recovery_code') is-invalid @enderror" id="recovery_code" name="recovery_code" autocomplete="off" placeholder="xxxxx-xxxxx">
                @error('recovery_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <button class="btn btn-outline-primary w-100 mt-3" type="submit">Use recovery code</button>
            </form>
        </details>
    @endif
@endsection

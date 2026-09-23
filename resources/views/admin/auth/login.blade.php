@extends('layouts.auth')
@section('title', 'Sign in')
@section('content')
    <h1 class="h4 mb-1">Sign in to the admin</h1>
    <p class="text-slate mb-4">RightAlly onboarding and billing</p>
    <form method="post" action="{{ route('admin.login') }}" novalidate>
        @csrf
        <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="mb-3">
            <div class="d-flex justify-content-between"><label class="form-label" for="password">Password</label><a class="small" href="{{ route('admin.password.request') }}">Forgot password?</a></div>
            <input class="form-control" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" id="remember" name="remember" value="1">
            <label class="form-check-label" for="remember">Keep me signed in on this device</label>
        </div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
    </form>
@endsection

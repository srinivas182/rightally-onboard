@extends('layouts.auth')
@section('title', 'Reset password')
@section('content')
    <h1 class="h4 mb-1">Reset your password</h1>
    <p class="text-slate mb-4">Enter your admin email and we’ll send a reset link.</p>
    <form method="post" action="{{ route('admin.password.email') }}">
        @csrf
        <label class="form-label" for="email">Email</label>
        <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <button class="btn btn-primary w-100 mt-4" type="submit">Send reset link</button>
    </form>
    <div class="text-center mt-3"><a class="small" href="{{ route('admin.login') }}">Back to sign in</a></div>
@endsection

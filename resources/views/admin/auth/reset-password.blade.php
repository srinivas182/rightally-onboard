@extends('layouts.auth')
@section('title', 'Choose a new password')
@section('content')
    <h1 class="h4 mb-4">Choose a new password</h1>
    <form method="post" action="{{ route('admin.password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div class="mb-3"><label class="form-label" for="email">Email</label>
            <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email" value="{{ old('email', $email) }}" required autocomplete="username">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
        @include('admin.auth._new-password')
        <button class="btn btn-primary w-100 mt-4" type="submit">Save password</button>
    </form>
@endsection

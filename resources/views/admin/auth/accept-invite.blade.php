@extends('layouts.auth')
@section('title', 'Accept invitation')
@section('content')
    <h1 class="h4 mb-1">Welcome, {{ $admin->name }}</h1>
    <p class="text-slate mb-4">You’ve been invited as <b>{{ $admin->role->name }}</b>. Set a password for {{ $admin->email }}.</p>
    <form method="post" action="{{ request()->fullUrl() }}">
        @csrf
        @include('admin.auth._new-password')
        <button class="btn btn-primary w-100 mt-4" type="submit">Set password and continue</button>
    </form>
@endsection

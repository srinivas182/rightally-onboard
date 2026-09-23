@extends('layouts.auth')
@section('title', 'Recovery codes')
@section('content')
    <h1 class="h4 mb-1">Save your recovery codes</h1>
    <p class="text-slate">If you lose your phone, each code lets you sign in once. This is the only time they’re shown. Store them in a password manager.</p>
    <div class="panel p-3 mb-4"><ul class="recovery list-unstyled mb-0">@foreach ($codes as $c)<li>{{ $c }}</li>@endforeach</ul></div>
    <a class="btn btn-primary w-100" href="{{ route('admin.dashboard') }}">I’ve saved them, continue</a>
@endsection

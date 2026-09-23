@extends('layouts.admin')
@section('title', 'Dashboard')
@section('menu', 'dashboard')
@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Dashboard</h1>
        <div class="text-slate">{{ \App\Support\BusinessClock::now()->format('l, M j, Y') }}</div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="kpi"><div class="l">Customers onboarded</div><div class="v">{{ number_format($kpis['customers']) }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi"><div class="l">Revenue this month</div><div class="v">{{ \App\Support\Money::format($kpis['revenue_cents']) }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi"><div class="l">Failed payments</div><div class="v {{ $kpis['failed'] ? 'text-danger' : '' }}">{{ $kpis['failed'] }}</div></div></div>
        <div class="col-6 col-xl-3"><div class="kpi"><div class="l">Suspended</div><div class="v">{{ $kpis['suspended'] }}</div></div></div>
    </div>
    <div class="panel p-4">
        <h2 class="h5">Getting set up</h2>
        <p class="text-slate">Onboarding opens to clients in Sprint 2. Before then:</p>
        <ol class="mb-0">
            @can('menu.settings')
                <li class="mb-1">Check company details, pricing and your signature in <a href="{{ route('admin.settings.index') }}">Settings</a>.</li>
                <li class="mb-1">Enter Stripe <b>test</b> keys and the Brevo API key in Settings.</li>
            @endcan
            @can('menu.admins')
                <li>Invite your team and choose their menu access in <a href="{{ route('admin.admins.index') }}">Admins and roles</a>.</li>
            @endcan
        </ol>
    </div>
@endsection

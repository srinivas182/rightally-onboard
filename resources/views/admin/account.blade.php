@extends('layouts.admin')
@section('title', 'My account')
@section('menu', 'account')
@section('content')
<h1 class="h3 mb-4">My account</h1>
<div class="row g-3" style="max-width:980px">
    <div class="col-lg-6">
        <form class="panel p-3 p-md-4 h-100" method="post" action="{{ route('admin.account.password') }}">@csrf @method('put')
            <h2 class="h5 mb-3">Change password</h2>
            <div class="mb-3"><label class="form-label" for="current_password">Current password</label>
                <input class="form-control @error('current_password') is-invalid @enderror" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
            @include('admin.auth._new-password')
            <button class="btn btn-primary mt-4" type="submit">Change password</button>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="panel p-3 p-md-4 h-100">
            <h2 class="h5 mb-3">Two-factor authentication</h2>
            <p><span class="st st-live">On</span> since {{ $admin->two_factor_confirmed_at->timezone(config('rightally.business_timezone'))->format('M j, Y') }}</p>
            <p class="text-slate small">{{ count($admin->two_factor_recovery_codes ?? []) }} unused recovery codes. Generating new codes replaces the old ones.</p>
            <form method="post" action="{{ route('admin.account.recovery-codes') }}">@csrf
                <label class="form-label" for="rc_pw">Confirm your password</label>
                <input class="form-control" id="rc_pw" name="current_password" type="password" autocomplete="current-password" required>
                <button class="btn btn-outline-primary mt-3" type="submit">Generate new recovery codes</button>
            </form>
            <p class="small text-slate mt-4 mb-0">Lost your phone? Ask another super admin to reset your two-factor.</p>
        </div>
    </div>
</div>
@endsection

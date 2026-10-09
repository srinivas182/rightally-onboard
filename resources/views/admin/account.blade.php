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
            <h2 class="h5 mb-3">Sign-in codes</h2>
            <p class="small text-slate">After your password, every sign-in asks for a 6-digit code: from an authenticator app if you have one set up, otherwise emailed to {{ $admin->email }}.</p>
            @if ($admin->hasTwoFactorEnabled())
                <p><span class="st st-live">Authenticator app on</span> since {{ $admin->two_factor_confirmed_at->timezone(config('rightally.business_timezone'))->format('M j, Y') }}</p>
                <p class="text-slate small">{{ count($admin->two_factor_recovery_codes ?? []) }} unused recovery codes. Generating new codes replaces the old ones.</p>
                <form method="post" action="{{ route('admin.account.recovery-codes') }}">@csrf
                    <label class="form-label" for="rc_pw">Confirm your password</label>
                    <input class="form-control" id="rc_pw" name="current_password" type="password" autocomplete="current-password" required>
                    <button class="btn btn-outline-primary mt-3" type="submit">Generate new recovery codes</button>
                </form>
                <hr class="my-4">
                <h3 class="h6">Turn off the authenticator app</h3>
                <p class="small text-slate">You’ll then get your sign-in code by email instead. Less secure: anyone with access to your email could sign in with your password.</p>
                <form method="post" action="{{ route('admin.account.authenticator-off') }}">@csrf
                    <label class="form-label" for="off_pw">Confirm your password</label>
                    <input class="form-control @error('off_password') is-invalid @enderror" id="off_pw" name="off_password" type="password" autocomplete="current-password" required>
                    @error('off_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <button class="btn btn-outline-danger mt-3" type="submit" data-confirm="Turn off your authenticator app? You’ll get sign-in codes by email instead.">Turn off authenticator app</button>
                </form>
                <p class="small text-slate mt-4 mb-0">Lost your phone? On the sign-in code screen choose <b>Email me a code instead</b>, or use a recovery code. Another super admin can also reset it for you (Admins and roles).</p>
            @else
                <p><span class="st st-wait">Codes by email</span></p>
                <p class="small text-slate">An authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…) is more secure than email codes.</p>
                <a class="btn btn-primary" href="{{ route('admin.two-factor.setup') }}">Set up authenticator app</a>
            @endif
        </div>
    </div>
</div>
@endsection

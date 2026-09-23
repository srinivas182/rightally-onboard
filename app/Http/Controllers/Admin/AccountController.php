<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * "My account": own password and recovery codes.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function show(Request $request): View
    {
        return view('admin.account', ['admin' => $request->user('admin')]);
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $admin->forceFill(['password' => $request->string('password')->toString()])->save();

        // Sign out other devices.
        Auth::guard('admin')->logoutOtherDevices($request->string('password')->toString());
        $this->audit->log('auth.password_changed', 'Changed own password', $admin);

        return back()->with('success', 'Password changed. Other devices have been signed out.');
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:admin']]);

        /** @var Admin $admin */
        $admin = $request->user('admin');
        $codes = $this->twoFactor->makeRecoveryCodes();
        $admin->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();
        $this->audit->log('auth.recovery_codes_regenerated', 'Generated new recovery codes', $admin);

        return redirect()->route('admin.two-factor.recovery')->with('recovery_codes', $codes['plain']);
    }
}

<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function request(): View
    {
        return view('admin.auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::broker('admins')->sendResetLink(['email' => strtolower((string) $request->input('email'))]);

        // Same response whether or not the email exists.
        return back()->with('status', 'If that email belongs to an admin, a reset link is on its way. It expires in 60 minutes.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('admin.auth.reset-password', ['token' => $token, 'email' => $request->string('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker('admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Admin $admin, string $password) {
                $admin->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($admin));
                $this->audit->log('auth.password_reset', 'Reset password by email link', $admin, null, 'system');
            },
        );

        return $status === Password::PasswordReset
            ? redirect()->route('admin.login')->with('status', 'Password updated. Sign in with your new password.')
            : back()->withErrors(['email' => 'This reset link is invalid or has expired. Request a new one.']);
    }
}

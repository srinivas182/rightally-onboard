<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\EmailLoginCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Password step of admin sign-in.
 *
 * Admins with two-factor enabled are NOT signed in here: their id is parked
 * in the session and the two-factor challenge completes the sign-in.
 */
class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(): View
    {
        return view('admin.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $admin = Admin::where('email', strtolower($data['email']))->first();

        // Same message whether the email or password is wrong.
        if (! $admin || ! $admin->password || ! Hash::check($data['password'], $admin->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        if (! $admin->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been deactivated. Contact a super admin.']);
        }

        $request->session()->regenerate();

        // Second step: authenticator code, or a code by email (always available; the only option without an app).
        $request->session()->put('admin.2fa', ['id' => $admin->id, 'remember' => (bool) ($data['remember'] ?? false)]);
        if (! $admin->hasTwoFactorEnabled()) {
            $error = app(EmailLoginCode::class)->send($admin);

            return redirect()->route('admin.two-factor.challenge', ['method' => 'email'])->with($error ? 'warning' : 'status', $error ?? "We’ve emailed a 6-digit code to {$admin->email}.");
        }

        return redirect()->route('admin.two-factor.challenge');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->audit->log('auth.logout', 'Signed out');
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('status', 'You have been signed out.');
    }

    public function recordLogin(Request $request, Admin $admin): void
    {
        $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->audit->log('auth.login', 'Signed in', $admin);
    }
}

<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /** Step 1 of setup: show QR code. The secret lives in the session until confirmed. */
    public function setup(Request $request): View|RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        if ($admin->hasTwoFactorEnabled()) {
            return redirect()->route('admin.dashboard');
        }

        $secret = $request->session()->get('admin.2fa_setup_secret') ?? $this->twoFactor->generateSecret();
        $request->session()->put('admin.2fa_setup_secret', $secret);

        return view('admin.auth.two-factor-setup', [
            'qr' => $this->twoFactor->qrCodeSvg($admin, $secret),
            'secret' => trim(chunk_split($secret, 4, ' ')),
        ]);
    }

    /** Step 2 of setup: confirm a code, then show recovery codes once. */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $secret = (string) $request->session()->get('admin.2fa_setup_secret');

        if (! $secret || ! $this->twoFactor->verify($secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'That code didn’t match. Check the time on your phone and enter the newest code.']);
        }

        $codes = $this->twoFactor->makeRecoveryCodes();
        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes['hashed'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('admin.2fa_setup_secret');
        $request->session()->regenerate();
        $this->audit->log('auth.2fa_enabled', 'Turned on two-factor authentication', $admin);

        return redirect()->route('admin.two-factor.recovery')->with('recovery_codes', $codes['plain']);
    }

    public function recovery(Request $request): View|RedirectResponse
    {
        $codes = $request->session()->get('recovery_codes');
        if (! $codes) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.two-factor-recovery', ['codes' => $codes]);
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('admin.2fa')) {
            return redirect()->route('admin.login');
        }

        $admin = Admin::find($request->session()->get('admin.2fa')['id'] ?? 0);

        return view('admin.auth.two-factor-challenge', [
            'hasApp' => (bool) $admin?->hasTwoFactorEnabled(),
            'emailMode' => $request->query('method') === 'email' || ! $admin?->hasTwoFactorEnabled(),
            'maskedEmail' => $admin ? preg_replace('/(?<=.).(?=[^@]*@)/', '•', $admin->email) : '',
        ]);
    }

    /** Emails a one-time sign-in code to the admin who passed the password step. */
    public function sendEmailCode(Request $request, \App\Services\Auth\EmailLoginCode $codes): RedirectResponse
    {
        $admin = Admin::find($request->session()->get('admin.2fa')['id'] ?? 0);
        if (! $admin) {
            return redirect()->route('admin.login');
        }
        $error = $codes->send($admin);

        return redirect()->route('admin.two-factor.challenge', ['method' => 'email'])->with($error ? 'warning' : 'status', $error ?? "We’ve emailed a 6-digit code to {$admin->email}.");
    }

    public function verify(Request $request, LoginController $login): RedirectResponse
    {
        $pending = $request->session()->get('admin.2fa');
        $admin = $pending ? Admin::find($pending['id']) : null;
        if (! $admin || ! $admin->is_active) {
            $request->session()->forget('admin.2fa');

            return redirect()->route('admin.login');
        }

        $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string'], 'email_code' => ['nullable', 'string']]);

        $ok = match (true) {
            $request->filled('email_code') => app(\App\Services\Auth\EmailLoginCode::class)->verify($admin, (string) $request->input('email_code')),
            $request->filled('recovery_code') => $this->twoFactor->useRecoveryCode($admin, (string) $request->input('recovery_code')),
            default => $admin->hasTwoFactorEnabled() && $this->twoFactor->verify((string) $admin->two_factor_secret, (string) $request->input('code')),
        };

        if (! $ok) {
            $this->audit->log('auth.2fa_failed', 'Failed sign-in code attempt', $admin, null, 'system');
            $field = $request->filled('email_code') ? 'email_code' : ($request->filled('recovery_code') ? 'recovery_code' : 'code');
            throw ValidationException::withMessages([$field => $field === 'email_code'
                ? 'That code didn’t work or has expired. Check the newest email, or send a new code.'
                : 'That code didn’t work. Try the newest code from your authenticator app.']);
        }

        $request->session()->forget('admin.2fa');
        $request->session()->regenerate();
        Auth::guard('admin')->login($admin, (bool) $pending['remember']);
        $login->recordLogin($request, $admin);

        if ($request->filled('recovery_code')) {
            $left = count($admin->fresh()->two_factor_recovery_codes ?? []);

            return redirect()->intended(route('admin.dashboard'))
                ->with('warning', "You used a recovery code. {$left} left. Generate new ones in My account.");
        }

        return redirect()->intended(route('admin.dashboard'));
    }
}

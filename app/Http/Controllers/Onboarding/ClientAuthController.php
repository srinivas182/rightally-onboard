<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Onboarding\ClientAccess;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Client sign-in: email first, then password. First-time clients get an
 * emailed link to create their password; "Forgot password?" sends a reset link.
 */
class ClientAuthController extends Controller
{
    private const EMAIL_RULE = ['required', 'email:rfc,filter', 'regex:/^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/', 'max:160'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function showEmail(Request $request): View|RedirectResponse
    {
        if ($client = auth('customer')->user()) {
            return redirect()->route('account.show', $client);
        }

        return view('account.login-email', ['email' => (string) $request->query('email', $request->session()->get('account.email', ''))]);
    }

    public function submitEmail(Request $request, ClientAccess $access): RedirectResponse
    {
        $data = $request->validate(['email' => self::EMAIL_RULE], ['email.regex' => __('Enter a valid email address, for example name@brokerage.com.')]);
        $email = strtolower($data['email']);
        $customer = Customer::where('email', $email)->first();
        $request->session()->put('account.email', $email);

        if (! $customer) {
            throw ValidationException::withMessages(['email' => __('We couldn’t find a RightAlly account for :email. Check the address, or contact us.', ['email' => $email])]);
        }
        if ($customer->hasPassword()) {
            return redirect()->route('account.password');
        }

        $result = $access->send($customer);

        return back()->with('status', match ($result) {
            'resume' => __('You haven’t finished setting up RightAlly yet. We’ve emailed you a link to continue where you left off.'),
            'throttled' => __('We sent you a link a moment ago. Check your inbox (and spam folder), or try again in a minute.'),
            default => __('We’ve emailed you a link to create your password. It works for 24 hours.'),
        });
    }

    public function showPassword(Request $request): View|RedirectResponse
    {
        $email = (string) $request->session()->get('account.email');

        return $email === '' ? redirect()->route('account.login') : view('account.login-password', ['email' => $email]);
    }

    public function login(Request $request): RedirectResponse
    {
        $email = (string) $request->session()->get('account.email');
        if ($email === '') {
            return redirect()->route('account.login');
        }
        $request->validate(['password' => ['required', 'string']]);

        $key = 'client-login:'.sha1($email.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['password' => __('Too many attempts. Try again in :seconds seconds, or reset your password.', ['seconds' => RateLimiter::availableIn($key)])]);
        }
        if (! Auth::guard('customer')->attempt(['email' => $email, 'password' => (string) $request->input('password')], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['password' => __('That password isn’t right. Try again, or reset it.')]);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $client = auth('customer')->user();
        $client->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('client.login', "{$client->company_name} signed in to their account", $client, null, 'client');

        return redirect()->intended(route('account.show', $client));
    }

    public function forgot(Request $request): RedirectResponse
    {
        $email = (string) $request->session()->get('account.email');
        if ($email === '') {
            return redirect()->route('account.login');
        }
        $status = Password::broker('customers')->sendResetLink(['email' => $email]);

        return back()->with('status', $status === Password::RESET_THROTTLED
            ? __('We sent you a link a moment ago. Check your inbox (and spam folder), or try again in a minute.')
            : __('We’ve emailed you a link to reset your password. It works for 24 hours.'));
    }

    public function showReset(Request $request, string $token): View
    {
        $customer = Customer::where('email', (string) $request->query('email'))->first();

        return view('account.reset-password', ['token' => $token, 'email' => (string) $request->query('email'), 'firstTime' => $customer && ! $customer->hasPassword()]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', app()->isProduction()
                ? PasswordRule::min(10)->letters()->numbers()->uncompromised()
                : PasswordRule::min(10)->letters()->numbers()],
        ]);

        $status = Password::broker('customers')->reset($request->only('email', 'password', 'password_confirmation', 'token'), function (Customer $customer, string $password) {
            $customer->forceFill(['password' => $password, 'password_set_at' => now(), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($customer));
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['password' => __('This link has expired or was already used. Request a new one from the sign-in page.')]);
        }

        $customer = Customer::where('email', strtolower((string) $request->input('email')))->firstOrFail();
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $customer->forceFill(['last_login_at' => now()])->save();
        $this->audit->log('client.password_set', "{$customer->company_name} set their account password", $customer, null, 'client');

        return redirect()->route('account.show', $customer)->with('success', __('Your password is saved. Use your email and this password to sign in next time.'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->forget('account.email');

        return redirect()->route('account.login')->with('status', __('You’re signed out.'));
    }
}

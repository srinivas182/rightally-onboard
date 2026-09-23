<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * An invited admin opens a signed link (valid 72 hours), sets a password,
 * and is then taken straight to two-factor setup.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Admin $admin): View|RedirectResponse
    {
        if ($admin->hasAcceptedInvite()) {
            return redirect()->route('admin.login')->with('status', 'This invitation has already been used. Sign in instead.');
        }

        return view('admin.auth.accept-invite', ['admin' => $admin]);
    }

    public function store(Request $request, Admin $admin): RedirectResponse
    {
        abort_if($admin->hasAcceptedInvite() || ! $admin->is_active, 403);

        $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        $admin->forceFill(['password' => $request->string('password')->toString()])->save();

        $request->session()->regenerate();
        Auth::guard('admin')->login($admin);
        $this->audit->log('admin.invite_accepted', 'Accepted invitation', $admin);

        return redirect()->route('admin.two-factor.setup');
    }
}

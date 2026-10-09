<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteAdminRequest;
use App\Models\Admin;
use App\Models\Role;
use App\Rules\AssignableRole;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admins and roles screen (one menu, two tabs).
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.admins.index', [
            'admins' => Admin::with('role')->orderBy('name')->get(),
            'roles' => Role::withCount('admins')->orderByDesc('is_system')->orderBy('name')->get(),
            'menus' => collect(config('rightally.menus'))->reject(fn ($m) => isset($m['permission']))->all(), // shared-permission menus aren't separate choices
        ]);
    }

    public function store(InviteAdminRequest $request): RedirectResponse
    {
        $admin = Admin::create([
            'name' => $request->string('name')->toString(),
            'email' => strtolower($request->string('email')->toString()),
            'role_id' => $request->integer('role_id'),
            'is_active' => true,
            'invited_at' => now(),
            'invited_by' => $request->user('admin')->id,
        ]);

        $this->sendInvite($admin, $request->user('admin')->name);
        $this->audit->log('admin.invited', "Invited {$admin->email} as {$admin->role->name}", $admin);

        return back()->with('success', "Invitation sent to {$admin->email}.");
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        $this->guardSelfAndSuper($request, $admin);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role_id' => ['required', Rule::exists('roles', 'id'), new AssignableRole],
        ]);

        $admin->fill($data);
        $changes = $admin->getDirty();
        $admin->save();
        $this->audit->log('admin.updated', "Updated admin {$admin->email}", $admin, $changes);

        return back()->with('success', "Saved changes to {$admin->name}.");
    }

    public function toggleActive(Request $request, Admin $admin): RedirectResponse
    {
        $this->guardSelfAndSuper($request, $admin);

        $admin->forceFill(['is_active' => ! $admin->is_active])->save();
        $verb = $admin->is_active ? 'Reactivated' : 'Deactivated';
        $this->audit->log('admin.'.strtolower($verb), "{$verb} {$admin->email}", $admin);

        return back()->with('success', "{$verb} {$admin->name}.");
    }

    public function resendInvite(Request $request, Admin $admin): RedirectResponse
    {
        abort_if($admin->hasAcceptedInvite(), 422, 'This admin has already accepted.');
        $admin->forceFill(['invited_at' => now()])->save();
        $this->sendInvite($admin, $request->user('admin')->name);
        $this->audit->log('admin.invite_resent', "Resent invitation to {$admin->email}", $admin);

        return back()->with('success', "Invitation resent to {$admin->email}.");
    }

    /** Clear 2FA so the admin sets it up again at next sign-in (lost phone). */
    public function resetTwoFactor(Request $request, Admin $admin): RedirectResponse
    {
        $this->guardSelfAndSuper($request, $admin);
        $admin->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('admin.2fa_reset', "Reset two-factor for {$admin->email}", $admin);

        return back()->with('success', "{$admin->name}’s authenticator app is removed. They’ll get sign-in codes by email until they set up a new one under My account.");
    }

    /** You can't lock yourself out, and only super admins manage super admins. */
    private function guardSelfAndSuper(Request $request, Admin $target): void
    {
        $me = $request->user('admin');
        abort_if($me->is($target), 403, 'You can’t change your own access. Ask another super admin.');
        abort_if($target->isSuperAdmin() && ! $me->isSuperAdmin(), 403);
    }

    private function sendInvite(Admin $admin, string $invitedBy): void
    {
        app(EmailSender::class)->toAdmin('admin_invite', $admin, [
            'invited_by' => $invitedBy,
            'invite_link' => URL::temporarySignedRoute('admin.invitation.show', now()->addHours(72), ['admin' => $admin->id]),
        ]);
    }
}

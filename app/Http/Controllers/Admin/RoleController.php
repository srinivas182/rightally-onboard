<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);
        $role = Role::create($data);
        $role->syncPermissions(['dashboard']);
        $this->audit->log('role.created', "Created role {$role->name}", $role);

        return redirect()->to(route('admin.admins.index').'#roles')->with('success', "Role {$role->name} created. Choose its menus below.");
    }

    /**
     * Save the whole menu-access grid in one submit.
     * Input: access[role_id][] = menu keys.
     */
    public function syncAccess(Request $request): RedirectResponse
    {
        $access = (array) $request->input('access', []);

        Role::where('is_system', false)->get()->each(function (Role $role) use ($access) {
            $before = $role->permissions();
            $after = array_values((array) ($access[$role->id] ?? []));
            $role->syncPermissions($after);
            if (array_diff($before, $role->permissions()) || array_diff($role->permissions(), $before)) {
                $this->audit->log('role.access_changed', "Changed menu access for {$role->name}", $role, [
                    'before' => $before, 'after' => $role->permissions(),
                ]);
            }
        });

        return redirect()->to(route('admin.admins.index').'#roles')->with('success', 'Menu access saved.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->is_system, 403, 'System roles can’t be deleted.');
        if ($role->admins()->exists()) {
            return redirect()->to(route('admin.admins.index').'#roles')
                ->withErrors(['role' => "Move the admins in {$role->name} to another role first."]);
        }
        $this->audit->log('role.deleted', "Deleted role {$role->name}", null, ['role' => $role->name]);
        $role->delete();

        return redirect()->to(route('admin.admins.index').'#roles')->with('success', 'Role deleted.');
    }
}

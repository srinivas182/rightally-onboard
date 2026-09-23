<?php

namespace App\Rules;

use App\Models\Role;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Only a super admin can give someone the Super admin role.
 * Prevents an admin with "Admins and roles" access from escalating.
 */
class AssignableRole implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $role = Role::find($value);
        if ($role?->isSuperAdmin() && ! auth('admin')->user()?->isSuperAdmin()) {
            $fail('Only a super admin can assign the Super admin role.');
        }
    }
}

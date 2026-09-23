<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A role grants access to a set of admin menus.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_system
 */
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_system'];

    /** @var array<int, string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** @return HasMany<Admin, $this> */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    /** @return array<int, string> */
    public function permissions(): array
    {
        return $this->permissionCache ??= DB::table('role_permissions')
            ->where('role_id', $this->id)
            ->pluck('permission')
            ->all();
    }

    public function grants(string $menu): bool
    {
        return in_array($menu, $this->permissions(), true);
    }

    /**
     * Replace this role's menu access. Unknown menu keys are ignored.
     *
     * @param  array<int, string>  $menus
     */
    public function syncPermissions(array $menus): void
    {
        $valid = array_values(array_intersect($menus, array_keys(config('rightally.menus'))));

        DB::transaction(function () use ($valid) {
            DB::table('role_permissions')->where('role_id', $this->id)->delete();
            DB::table('role_permissions')->insert(
                array_map(fn ($m) => ['role_id' => $this->id, 'permission' => $m], $valid)
            );
        });

        $this->permissionCache = $valid;
    }

    public function isSuperAdmin(): bool
    {
        return $this->name === config('rightally.super_admin_role');
    }
}

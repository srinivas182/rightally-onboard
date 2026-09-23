<?php

namespace App\Models;

use App\Services\Email\EmailSender;
use Database\Factories\AdminFactory;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * A back-office user. Access is controlled by the admin's role, which
 * grants whole menus (see App\Providers\AuthServiceProvider gates).
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property ?string $password
 * @property ?string $two_factor_secret
 * @property ?Carbon $two_factor_confirmed_at
 * @property bool $is_active
 * @property-read Role $role
 */
class Admin extends Authenticatable implements CanResetPasswordContract
{
    /** @use HasFactory<AdminFactory> */
    use CanResetPassword, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'role_id', 'name', 'email', 'password', 'is_active', 'invited_at', 'invited_by',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'invited_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->role?->name === config('rightally.super_admin_role');
    }

    public function hasMenu(string $menu): bool
    {
        return $this->isSuperAdmin() || $this->role?->grants($menu);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function hasAcceptedInvite(): bool
    {
        return $this->password !== null;
    }

    /** Password reset email, from the editable "Password reset" template. */
    public function sendPasswordResetNotification($token): void
    {
        app(EmailSender::class)->toAdmin('password_reset', $this, [
            'reset_link' => route('admin.password.reset', ['token' => $token, 'email' => $this->email]),
        ]);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->name, 0, 1));
    }
}

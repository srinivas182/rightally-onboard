<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use App\Services\Email\EmailSender;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property CustomerStatus $status
 * @property int $agent_count
 * @property ?Carbon $go_live_date
 */
class Customer extends Authenticatable implements CanResetPasswordContract
{
    use CanResetPassword, HasUuids, SoftDeletes;

    protected $guarded = ['id', 'uuid', 'agent_api_token_hash', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_agent_item_id', 'agent_api_token', 'password', 'remember_token', 'password_set_at'];

    protected $hidden = ['agent_api_token_hash', 'agent_api_token', 'password', 'remember_token'];

    /** HasUuids fills the "uuid" column; the primary key stays an integer. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_set_at' => 'datetime',
            'last_login_at' => 'datetime',
            'status' => CustomerStatus::class,
            'go_live_date' => 'date',
            'balance_reminder_for' => 'date',
            'live_at' => 'datetime',
            'agent_api_token' => 'encrypted',
            'utm' => 'array',
            'onboarding_started_at' => 'datetime',
            'agent_count_synced_at' => 'datetime',
            'agents_notified_at' => 'datetime',
            'suspended_at' => 'datetime',
            'paused_until' => 'date',
            'service_ends_on' => 'date',
            'follow_up_last_at' => 'datetime',
            'follow_up_unsubscribed_at' => 'datetime',
            'end_requested_at' => 'datetime',
            'email_bounced_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<AgentCountLog, $this> */
    public function agentCountLogs(): HasMany
    {
        return $this->hasMany(AgentCountLog::class);
    }

    /** @return HasMany<EmailLog, $this> */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class);
    }

    public function hasPassword(): bool
    {
        return filled($this->password);
    }

    /** Set-password and reset links use our branded email templates. */
    public function sendPasswordResetNotification($token): void
    {
        $link = route('account.password.reset', ['token' => $token, 'email' => $this->email]);
        app(EmailSender::class)->toCustomer($this->hasPassword() ? 'customer_reset_password' : 'customer_set_password', $this, null, [], [
            'password_link' => $link,
        ]);
    }

    protected static function booted(): void
    {
        // Where onboarding stands: past Draft, the stage follows the status.
        static::saving(function (Customer $c) {
            if ($c->isDirty('status') && $c->status !== CustomerStatus::Draft) {
                $c->onboarding_stage = $c->status === CustomerStatus::ContractSigned ? 'payment' : 'done';
            }
        });
    }

    public const STAGES = ['brokerage' => 'Brokerage details', 'agreement' => 'Agreement', 'payment' => 'Payment', 'done' => 'Done'];
}

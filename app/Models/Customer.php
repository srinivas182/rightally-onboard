<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property CustomerStatus $status
 * @property int $agent_count
 * @property ?Carbon $go_live_date
 */
class Customer extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id', 'uuid', 'agent_api_token_hash', 'stripe_customer_id', 'stripe_subscription_id', 'stripe_agent_item_id', 'agent_api_token'];

    protected $hidden = ['agent_api_token_hash', 'agent_api_token'];

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
}

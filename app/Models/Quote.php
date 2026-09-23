<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom quote: negotiated pricing delivered as a one-time onboarding link.
 * Used once (at signing), expires, can be voided.
 */
class Quote extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'token'];

    public function uniqueIds(): array
    {
        return ['token'];
    }

    protected function casts(): array
    {
        return ['deposit_percent' => 'decimal:2', 'expires_at' => 'datetime', 'voided_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return ! $this->voided_at && ! $this->used_at && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            (bool) $this->used_at => 'used',
            (bool) $this->voided_at => 'voided',
            $this->expires_at->isPast() => 'expired',
            default => 'open',
        };
    }

    public function link(): string
    {
        return url('/?quote='.$this->token);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}

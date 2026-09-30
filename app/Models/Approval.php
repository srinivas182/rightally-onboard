<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sensitive action waiting for a second admin (early termination, large refund or credit). */
class Approval extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Admin, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    /** @return BelongsTo<Admin, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'decided_by');
    }

    public function label(): string
    {
        return match ($this->action) {
            'early_termination' => 'Early termination',
            'refund' => 'Refund',
            'credit' => 'Credit on next charge',
            'end_now' => 'End service now (no fee)',
            default => $this->action,
        };
    }
}

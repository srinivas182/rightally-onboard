<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A discovery call booked on the GoHighLevel calendar (received by webhook). */
class CallBooking extends Model
{
    public const STATUSES = ['scheduled' => 'Booked', 'completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled', 'onboarded' => 'Onboarded'];

    public const PILLS = ['scheduled' => 'st-wait', 'completed' => 'st-live', 'no_show' => 'st-fail', 'cancelled' => 'st-draft', 'onboarded' => 'st-live'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'utm' => 'array', 'last_payload' => 'array'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

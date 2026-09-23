<?php

namespace App\Models;

use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Percentage discount on the implementation fee.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $percent_off
 * @property ?\Illuminate\Support\Carbon $expires_on
 * @property ?int $max_uses
 * @property int $times_used
 * @property bool $is_active
 */
class Coupon extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'percent_off', 'expires_on', 'max_uses', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'percent_off' => 'decimal:2',
            'expires_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }

    public function isExpired(): bool
    {
        // Compare calendar dates: a coupon is valid through its whole expiry day
        // in the business time zone, whatever the server's time zone is.
        return $this->expires_on !== null
            && $this->expires_on->toDateString() < BusinessClock::today()->toDateString();
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && ! $this->isExpired()
            && ($this->max_uses === null || $this->times_used < $this->max_uses);
    }

    public function hasBeenUsed(): bool
    {
        return $this->times_used > 0;
    }
}

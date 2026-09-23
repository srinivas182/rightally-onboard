<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An outgoing webhook destination (CRM, Zapier, etc.). */
class WebhookEndpoint extends Model
{
    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'is_active' => 'boolean'];
    }

    public function wants(string $event): bool
    {
        return $this->is_active && in_array($event, (array) $this->events, true);
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}

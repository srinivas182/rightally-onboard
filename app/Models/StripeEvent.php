<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StripeEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'livemode' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}

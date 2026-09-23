<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pause extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'resumed_at' => 'datetime'];
    }
}

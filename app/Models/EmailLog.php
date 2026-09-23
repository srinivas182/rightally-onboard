<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['cc' => 'array', 'sent_at' => 'datetime'];
    }
}

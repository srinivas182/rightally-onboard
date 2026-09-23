<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['key', 'is_system', 'name', 'trigger_description', 'subject', 'body', 'cc_team', 'is_enabled', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'cc_team' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}

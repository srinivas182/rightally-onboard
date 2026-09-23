<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw settings row. Always read and write through
 * App\Services\Settings\SettingsService, which handles encryption and cache.
 *
 * @property string $group
 * @property string $key
 * @property ?string $value
 * @property bool $is_encrypted
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'is_encrypted', 'updated_by'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}

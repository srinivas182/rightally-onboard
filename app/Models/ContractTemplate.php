<?php

namespace App\Models;

use App\Enums\ContractType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property ContractType $type
 * @property ?Carbon $published_at
 */
class ContractTemplate extends Model
{
    protected $fillable = ['type', 'version', 'title', 'body_html', 'is_active', 'published_at', 'created_by'];

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}

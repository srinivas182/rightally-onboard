<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed (or in-progress) agreement with a full price snapshot.
 * Once signed, the record is never edited; see ContractStatus.
 */
class Contract extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'type' => ContractType::class,
            'status' => ContractStatus::class,
            'discount_percent' => 'decimal:2',
            'deposit_percent' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'esign_consent_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    /** Monthly fee in cents at a given agent count, honouring the minimum. */
    public function monthlyFeeCents(?int $agents = null): int
    {
        $billed = max($this->min_agents, $agents ?? $this->agent_count);

        return $this->platform_fee_cents + $billed * $this->per_agent_fee_cents;
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<ContractTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'contract_template_id');
    }

    /** @return BelongsTo<Contract, $this> */
    public function previous(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'previous_contract_id');
    }

    /** Renewal agreements offered for this one. @return HasMany<Contract, $this> */
    public function renewals(): HasMany
    {
        return $this->hasMany(Contract::class, 'previous_contract_id');
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}

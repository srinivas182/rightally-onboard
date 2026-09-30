<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\ContractType;
use App\Support\Money;
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
            'annual_discount_percent' => 'decimal:2',
            'deposit_percent' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'esign_consent_at' => 'datetime',
            'signed_at' => 'datetime',
            'signature_requested_at' => 'datetime',
            'first_charge_on' => 'date',
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

    public function isAnnual(): bool
    {
        return $this->billing_interval === 'year';
    }

    /** Yearly platform fee: 12 months less the annual discount. */
    public function platformYearCents(): int
    {
        return (int) round($this->platform_fee_cents * 12 * (1 - (float) $this->annual_discount_percent / 100));
    }

    /** Yearly fee per agent: 12 months less the annual discount. */
    public function perAgentYearCents(): int
    {
        return (int) round($this->per_agent_fee_cents * 12 * (1 - (float) $this->annual_discount_percent / 100));
    }

    public function annualFeeCents(?int $agents = null): int
    {
        return $this->platformYearCents() + max($this->min_agents, $agents ?? $this->agent_count) * $this->perAgentYearCents();
    }

    /** What each subscription charge is: the monthly fee, or the annual fee when billed yearly. */
    public function recurringFeeCents(?int $agents = null): int
    {
        return $this->isAnnual() ? $this->annualFeeCents($agents) : $this->monthlyFeeCents($agents);
    }

    /** Monthly equivalent, for recurring-revenue reporting. */
    public function monthlyEquivalentCents(?int $agents = null): int
    {
        return $this->isAnnual() ? (int) round($this->annualFeeCents($agents) / 12) : $this->monthlyFeeCents($agents);
    }

    /** "$660.00 a month" or "$7,128.00 a year". */
    public function recurringLabel(?int $agents = null): string
    {
        return Money::format($this->recurringFeeCents($agents)).($this->isAnnual() ? ' a year' : ' a month');
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

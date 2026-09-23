<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Draft = 'draft';                   // details entered, not signed
    case ContractSigned = 'contract_signed'; // signed, deposit not paid
    case AwaitingGoLive = 'awaiting_go_live';
    case BalanceFailed = 'balance_failed';
    case Live = 'live';
    case PaymentFailed = 'payment_failed';
    case Suspended = 'suspended';
    case Paused = 'paused';          // subscription paused by agreement; no charges
    case Cancelled = 'cancelled';
    case Expired = 'expired';               // term ended without renewal

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Started',
            self::ContractSigned => 'Signed, unpaid',
            self::AwaitingGoLive => 'Awaiting go-live',
            self::BalanceFailed => 'Balance failed',
            self::Live => 'Live',
            self::PaymentFailed => 'Payment failed',
            self::Suspended => 'Suspended',
            self::Paused => 'Paused',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    /** CSS class for the status pill. */
    public function pill(): string
    {
        return match ($this) {
            self::Live => 'st-live',
            self::AwaitingGoLive, self::ContractSigned => 'st-wait',
            self::BalanceFailed, self::PaymentFailed => 'st-fail',
            self::Suspended => 'st-susp',
            self::Paused => 'st-draft',
            default => 'st-draft',
        };
    }
}

<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Deposit = 'deposit';
    case Balance = 'balance';
    case Monthly = 'monthly';
    case Annual = 'annual';
    case EarlyTermination = 'early_termination';

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Balance => 'Balance at go-live',
            self::Monthly => 'Monthly',
            self::Annual => 'Annual subscription',
            self::EarlyTermination => 'Early termination',
        };
    }
}

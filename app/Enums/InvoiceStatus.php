<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Scheduled = 'scheduled';
    case Processing = 'processing'; // e.g. ACH debit waiting to settle
    case Paid = 'paid';
    case Failed = 'failed';
    case Void = 'void';
}

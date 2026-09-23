<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Draft = 'draft';
    case Signed = 'signed';
    case Superseded = 'superseded';
    case Expired = 'expired';
    case Terminated = 'terminated';
}

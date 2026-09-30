<?php

namespace App\Enums;

enum ContractType: string
{
    case Initial = 'initial';
    case Renewal = 'renewal';
    case Existing = 'existing'; // client already live before this app: subscription-only agreement
}

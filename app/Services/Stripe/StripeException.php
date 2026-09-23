<?php

namespace App\Services\Stripe;

use RuntimeException;

/** A Stripe API call failed. $userMessage is safe to show to a client. */
class StripeException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $userMessage = null, public readonly ?string $stripeCode = null)
    {
        parent::__construct($message);
    }
}

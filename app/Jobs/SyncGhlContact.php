<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\Integrations\GhlContacts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends a customer to GoHighLevel in the background (retries if GHL is briefly unavailable). */
class SyncGhlContact implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    public array $backoff = [60, 300, 1800];

    /** @param array<int, string> $tags */
    public function __construct(public readonly int $customerId, public readonly array $tags = []) {}

    public function handle(GhlContacts $ghl): void
    {
        $customer = Customer::find($this->customerId);
        if ($customer) {
            $ghl->upsert($customer, $this->tags);
        }
    }
}

<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;

/** Posts a message to a Slack incoming webhook. */
class SendSlackMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public string $webhookUrl, public string $text) {}

    public function handle(): void
    {
        if (! str_starts_with($this->webhookUrl, 'https://hooks.slack.com/')) {
            return; // placeholder or invalid URL
        }
        Http::timeout(10)->post($this->webhookUrl, ['text' => $this->text])->throw();
    }
}

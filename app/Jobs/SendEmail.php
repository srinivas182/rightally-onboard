<?php

namespace App\Jobs;

use App\Models\EmailLog;
use App\Services\Email\BrevoClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Delivers one logged email through Brevo, retrying on temporary failures. */
class SendEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** @param array<int, array{path: string, name: string}> $attachments */
    public function __construct(
        public int $logId,
        public string $toName,
        public string $html,
        public string $text,
        public array $attachments = [],
    ) {}

    public function handle(BrevoClient $brevo): void
    {
        $log = EmailLog::find($this->logId);
        if (! $log || $log->status === 'sent') {
            return;
        }

        if (! $brevo->isConfigured()) {
            // Keep a copy in the application log so nothing is lost before Brevo is set up.
            Log::info("Email not sent (Brevo not configured): {$log->subject} to {$log->to_email}", ['text' => $this->text]);
            $log->update(['status' => 'failed', 'error' => 'Brevo API key is not set in Settings > Email.']);

            return;
        }

        $files = [];
        foreach ($this->attachments as $a) {
            if (Storage::disk('local')->exists($a['path'])) {
                $files[] = ['name' => $a['name'], 'content' => Storage::disk('local')->get($a['path'])];
            }
        }

        try {
            $id = $brevo->send($log->to_email, $this->toName, (array) $log->cc, $log->subject, $this->html, $this->text, $files, array_filter([$log->template_key]));
            $log->update(['status' => 'sent', 'provider_message_id' => $id, 'sent_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 300);
            }
        }
    }
}

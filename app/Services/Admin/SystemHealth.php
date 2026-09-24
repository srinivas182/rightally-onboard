<?php

namespace App\Services\Admin;

use App\Models\StripeEvent;
use App\Services\Settings\SettingsService;
use App\Services\Stripe\StripeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Operational checks shown on the dashboard and at /health: is everything
 * that billing depends on configured and running?
 */
final class SystemHealth
{
    public const HEARTBEAT_KEY = 'health:scheduler_heartbeat';

    public const BILLING_RUN_KEY = 'health:billing_last_run';

    public function __construct(private readonly StripeClient $stripe, private readonly SettingsService $settings) {}

    /** @return array<string, array{ok: bool, label: string, detail: string}> */
    public function checks(): array
    {
        $heartbeat = Cache::get(self::HEARTBEAT_KEY);
        $billingRun = Cache::get(self::BILLING_RUN_KEY);
        $lastEvent = StripeEvent::latest('id')->first();
        $failedJobs = $this->count('failed_jobs');
        $stuckJobs = $this->count('jobs', fn ($q) => $q->where('created_at', '<', now()->subMinutes(15)->getTimestamp()));

        return [
            'database' => $this->check($this->dbOk(), 'Database', 'Connected'),
            'scheduler' => $this->check($heartbeat && now()->diffInMinutes($heartbeat) <= 10, 'Scheduler (cron)',
                $heartbeat ? 'Last heartbeat '.now()->diffForHumans($heartbeat, true).' ago' : 'Never ran. Add the cron entry from DEPLOYMENT.md.'),
            'billing_run' => $this->check($billingRun && now()->diffInHours($billingRun) <= 26, 'Daily billing run',
                $billingRun ? 'Last run '.now()->diffForHumans($billingRun, true).' ago' : 'Hasn’t run yet (runs at 9:00 AM Miami time).'),
            'queue' => $this->check($stuckJobs === 0 && $failedJobs === 0, 'Email queue',
                $stuckJobs ? "{$stuckJobs} jobs waiting over 15 minutes: is the queue worker running?" : ($failedJobs ? "{$failedJobs} failed jobs: run php artisan queue:failed" : 'Worker keeping up')),
            'stripe' => $this->check($this->stripe->isConfigured() && filled($this->stripe->webhookSecret()), 'Stripe ('.$this->stripe->mode().' mode)',
                $this->stripe->isConfigured() ? (filled($this->stripe->webhookSecret()) ? 'Keys and webhook secret set' : 'Webhook signing secret missing') : 'Keys missing in Settings > Stripe'),
            'webhooks' => $this->check((bool) $lastEvent && ! $lastEvent->error, 'Stripe webhooks',
                $lastEvent ? ($lastEvent->error ? 'Last event failed: '.mb_substr($lastEvent->error, 0, 120) : 'Last event '.$lastEvent->created_at->diffForHumans()) : 'No events received yet'),
            'stripe_errors' => $this->stripeErrorCheck(),
            'reconcile' => $this->reconcileCheck(),
            'email' => $this->check(filled($this->settings->get('email', 'brevo_api_key')), 'Email (Brevo)',
                filled($this->settings->get('email', 'brevo_api_key')) ? 'API key set' : 'API key missing: emails are only written to the log'),
        ];
    }

    private function stripeErrorCheck(): array
    {
        $e = Cache::get('health:stripe_last_error');
        $recent = $e && $e['at']->gt(now()->subDay());

        return $this->check(! $recent, 'Stripe requests', $recent
            ? 'Last error '.$e['at']->diffForHumans().' ('.$e['path'].'): '.$e['message']
            : 'No errors in the last 24 hours');
    }

    private function reconcileCheck(): array
    {
        $r = Cache::get('health:reconcile_last');
        if (! $r) {
            return $this->check(true, 'Stripe reconciliation', 'Runs nightly at 5:00 AM Miami time');
        }

        return $this->check(($r['failed'] ?? 0) === 0, 'Stripe reconciliation',
            ($r['replayed'] ? "{$r['replayed']} missed events applied" : 'Nothing missed').', last run '.$r['at']->diffForHumans().(($r['failed'] ?? 0) ? ", {$r['failed']} failed: see Stripe webhooks" : ''));
    }

    public function allOk(): bool
    {
        return collect($this->checks())->every(fn ($c) => $c['ok']);
    }

    private function check(bool $ok, string $label, string $detail): array
    {
        return ['ok' => $ok, 'label' => $label, 'detail' => $detail];
    }

    private function dbOk(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function count(string $table, ?callable $where = null): int
    {
        try {
            $q = DB::table($table);
            if ($where) {
                $where($q);
            }

            return $q->count();
        } catch (Throwable) {
            return 0;
        }
    }
}

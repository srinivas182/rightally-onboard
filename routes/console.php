<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Admin\SystemHealth;
use App\Services\Billing\AgentCountService;
use App\Services\Billing\BalanceService;
use App\Services\Billing\CardExpiryWarnings;
use App\Services\Integrations\TeamAlerts;
use App\Services\Billing\CardExpiryWarnings;
use App\Services\Billing\RenewalService;
use App\Services\Billing\SuspensionService;
use App\Services\Onboarding\DepositReminders;
use App\Services\Stripe\Reconciler;
use App\Services\Stripe\StripeClient;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Daily billing run (9:00 AM Miami time)
|--------------------------------------------------------------------------
| Safe to run more than once a day: every step checks what was already done.
| Monthly charges themselves are made by Stripe's subscription; their
| results arrive by webhook.
*/
Artisan::command('billing:daily', function (BalanceService $balance, SuspensionService $suspension, RenewalService $renewals, CardExpiryWarnings $cards, TeamAlerts $alerts) {
    $this->info('Billing run for '.BusinessClock::today()->toDateString());

    $summary = [];
    foreach ($balance->due() as $customer) {
        try {
            $balance->charge($customer);
            $customer->refresh();
            $amount = (int) $customer->invoices()->where('type', 'balance')->latest('id')->value('amount_cents');
            $summary[] = ['company' => $customer->company_name, 'amount' => $amount, 'ok' => $customer->status === CustomerStatus::Live];
            $this->line("Balance charged: {$customer->company_name}");
        } catch (Throwable $e) {
            report($e);
            $summary[] = ['company' => $customer->company_name, 'amount' => 0, 'ok' => false];
            $this->error("Balance charge failed to run for {$customer->company_name}: {$e->getMessage()}");
        }
    }
    $alerts->goLiveSummary($summary);
    $this->line('Balance reminders sent: '.$balance->sendReminders());
    $this->line('Renewals started: '.$renewals->activate());
    $this->line('Renewal offers sent: '.$renewals->sendOffers());
    $this->line('Renewal reminders sent: '.$renewals->sendReminders());
    $this->line('Agreements expired: '.$renewals->expire());
    $this->line('Accounts suspended: '.$suspension->run());
    $this->line('Card expiry warnings sent: '.$cards->send());
    Cache::forever(SystemHealth::BILLING_RUN_KEY, now());
})->purpose('Go-live charges, reminders, renewals, expiries and suspensions');

Artisan::command('agents:sync', function (AgentCountService $agents) {
    $customers = Customer::whereIn('status', [CustomerStatus::Live, CustomerStatus::PaymentFailed])->whereNotNull('live_url')->whereNotNull('agent_api_token')->get();
    foreach ($customers as $customer) {
        $billed = $agents->pull($customer);
        $this->line($customer->company_name.': '.($billed === null ? 'no answer' : "{$billed} billed"));
    }
})->purpose('Pull agent counts from customers’ RightAlly instances');

Schedule::command('billing:daily')->dailyAt('09:00')->timezone(BusinessClock::timezone())->withoutOverlapping()->onOneServer();
Schedule::command('agents:sync')->dailyAt('06:00')->timezone(BusinessClock::timezone())->withoutOverlapping()->onOneServer();

Artisan::command('onboarding:reminders', function (DepositReminders $reminders) {
    $this->line('Deposit reminders sent: '.$reminders->send());
})->purpose('Remind clients who signed but haven’t paid the deposit (24 hours, 3 days)');

Artisan::command('billing:reconcile {--days=3}', function (Reconciler $reconciler, StripeClient $stripe) {
    if (! $stripe->isConfigured()) {
        $this->warn('Stripe is not configured; nothing to reconcile.');

        return;
    }
    $r = $reconciler->run((int) $this->option('days'));
    $this->line("Stripe events checked: {$r['checked']}, applied now: {$r['replayed']}, failed: {$r['failed']}");
    Cache::forever('health:reconcile_last', ['at' => now(), 'replayed' => $r['replayed'], 'failed' => $r['failed']]);
})->purpose('Apply any Stripe events the webhook missed');

Schedule::command('onboarding:reminders')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('billing:reconcile')->dailyAt('05:00')->timezone(BusinessClock::timezone())->withoutOverlapping()->onOneServer();

// Heartbeat so the dashboard can tell whether cron is running.
Schedule::call(fn () => Cache::forever(SystemHealth::HEARTBEAT_KEY, now()))->everyFiveMinutes()->name('scheduler-heartbeat')->onOneServer();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=720')->weekly();
Schedule::command('auth:clear-resets')->daily();

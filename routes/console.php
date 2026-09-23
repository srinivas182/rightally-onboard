<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\Admin\SystemHealth;
use App\Services\Billing\AgentCountService;
use App\Services\Billing\BalanceService;
use App\Services\Billing\RenewalService;
use App\Services\Billing\SuspensionService;
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
Artisan::command('billing:daily', function (BalanceService $balance, SuspensionService $suspension, RenewalService $renewals) {
    $this->info('Billing run for '.BusinessClock::today()->toDateString());

    foreach ($balance->due() as $customer) {
        try {
            $balance->charge($customer);
            $this->line("Balance charged: {$customer->company_name}");
        } catch (Throwable $e) {
            report($e);
            $this->error("Balance charge failed to run for {$customer->company_name}: {$e->getMessage()}");
        }
    }
    $this->line('Balance reminders sent: '.$balance->sendReminders());
    $this->line('Renewals started: '.$renewals->activate());
    $this->line('Renewal offers sent: '.$renewals->sendOffers());
    $this->line('Renewal reminders sent: '.$renewals->sendReminders());
    $this->line('Agreements expired: '.$renewals->expire());
    $this->line('Accounts suspended: '.$suspension->run());
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

// Heartbeat so the dashboard can tell whether cron is running.
Schedule::call(fn () => Cache::forever(SystemHealth::HEARTBEAT_KEY, now()))->everyFiveMinutes()->name('scheduler-heartbeat')->onOneServer();

// Housekeeping.
Schedule::command('queue:prune-failed --hours=720')->weekly();
Schedule::command('auth:clear-resets')->daily();

<?php

namespace App\Services\Onboarding;

use App\Enums\CustomerStatus;
use App\Http\Controllers\Onboarding\BookCallController;
use App\Models\CallBooking;
use App\Models\Customer;
use App\Services\Audit\AuditLogger;
use App\Services\Email\EmailSender;
use App\Services\Settings\SettingsService;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\URL;

/**
 * Follow-up sequence for people who started but haven't signed (stopped after "About you",
 * "Your brokerage" or on the agreement). Four emails at about 1 hour, 1 day, 3 days and 7 days
 * after they stopped, only between 8am and 8pm Eastern, at least 20 hours apart after the first.
 * Stops as soon as they sign, book a call or click "stop these reminders".
 */
final class LeadFollowUps
{
    /** Hours after the person stopped, for emails 1 to 4. */
    public const AFTER_HOURS = [1, 24, 72, 168];

    public function __construct(private readonly EmailSender $email, private readonly SettingsService $settings, private readonly ResumeLinks $links) {}

    public function run(): int
    {
        if ((string) $this->settings->get('alerts', 'follow_ups') !== '1') {
            return 0;
        }
        $hour = BusinessClock::now()->hour;
        if ($hour < 8 || $hour >= 20) {
            return 0; // quiet hours: due emails go out from 8am
        }

        $sent = 0;
        Customer::with(['coupon'])->where('status', CustomerStatus::Draft)
            ->whereNull('follow_up_unsubscribed_at')
            ->where('follow_up_step', '<', count(self::AFTER_HOURS))
            ->whereNotNull('email')
            ->where('updated_at', '>', now()->subDays(30))
            ->each(function (Customer $c) use (&$sent) {
                if (! $this->isDue($c) || $this->bookedACall($c)) {
                    return;
                }
                $step = $c->follow_up_step + 1;
                $this->email->toCustomer("follow_up_{$step}", $c, null, [], $this->values($c));
                // Not a change the client made: keep "updated_at" (when they last did something) as it is.
                $c->timestamps = false;
                $c->forceFill(['follow_up_step' => $step, 'follow_up_last_at' => now()])->save();
                app(AuditLogger::class)->log('onboarding.follow_up_sent', "Follow-up email {$step} of ".count(self::AFTER_HOURS)." sent to {$c->email}", $c, null, 'system');
                $sent++;
            });

        return $sent;
    }

    public function isDue(Customer $c): bool
    {
        $stoppedAt = $c->updated_at;
        $dueAt = $stoppedAt->copy()->addHours(self::AFTER_HOURS[$c->follow_up_step]);
        if ($c->follow_up_last_at && $c->follow_up_last_at->gt(now()->subHours(20))) {
            return false;
        }

        return now()->gte($dueAt);
    }

    public function stopLink(Customer $c): string
    {
        return URL::signedRoute('followups.stop', ['customer' => $c->uuid]);
    }

    private function bookedACall(Customer $c): bool
    {
        return CallBooking::where('email', $c->email)->whereIn('status', ['scheduled', 'completed'])->exists();
    }

    /** @return array<string, string> */
    private function values(Customer $c): array
    {
        $code = $c->coupon?->code;

        return [
            'resume_link' => $this->links->link($c),
            'unsubscribe_link' => $this->stopLink($c),
            'book_call_link' => route('book', array_filter(['coupon' => $code])),
            'go_live_days' => (string) $this->settings->get('pricing', 'go_live_days'),
            'discount_line' => $c->coupon && $c->coupon->percent_off > 0 ? __('Your referral discount of :p% on the set-up fee is saved.', ['p' => rtrim(rtrim((string) $c->coupon->percent_off, '0'), '.')]) : '',
            'referrer_line' => $code ? __('You were referred by :name.', ['name' => BookCallController::referrer($code)]) : '',
        ];
    }
}

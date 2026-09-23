<?php

namespace App\Services\Integrations;

use App\Jobs\SendSlackMessage;
use App\Models\Customer;
use App\Services\Email\EmailSender;
use App\Services\Settings\SettingsService;
use App\Support\Money;

/**
 * Alerts for the team by email (team CC addresses) and/or Slack (incoming
 * webhook URL in Settings > Alerts). Each kind can be switched on or off.
 */
final class TeamAlerts
{
    public const KINDS = [
        'new_signing' => 'New signing',
        'payment_failed' => 'Payment failures',
        'go_lives' => 'Daily go-live summary',
        'chargebacks' => 'Chargebacks (Slack; email is always sent)',
    ];

    public function __construct(private readonly SettingsService $settings, private readonly EmailSender $email) {}

    public function enabled(string $kind): bool
    {
        return (string) $this->settings->get('alerts', $kind) === '1';
    }

    public function send(string $kind, string $title, string $text, ?string $link = null, bool $email = true): void
    {
        if (! $this->enabled($kind)) {
            return;
        }
        if ($email && (string) $this->settings->get('alerts', 'email') === '1') {
            $this->email->toTeam('team_alert', ['alert_title' => $title, 'alert_text' => $text, 'alert_link' => $link ?? url('/admin')]);
        }
        $slack = $this->settings->get('alerts', 'slack_webhook_url');
        if (filled($slack)) {
            SendSlackMessage::dispatch($slack, "*{$title}*\n{$text}".($link ? "\n<{$link}|Open in RightAlly admin>" : ''))->afterCommit();
        }
    }

    public function forCustomerEvent(string $event, Customer $customer): void
    {
        $link = route('admin.customers.show', $customer);
        match ($event) {
            'customer.signed' => $this->send('new_signing', "New signing: {$customer->company_name}",
                "{$customer->fullName()} ({$customer->city}, {$customer->state_code}) signed for {$customer->agent_count} agents. Source: ".($customer->source ?: 'Direct').'.', $link),
            'payment.failed' => $this->send('payment_failed', "Payment failed: {$customer->company_name}",
                "Status: {$customer->status->label()}. The client has been emailed a link to pay.", $link),
            default => null,
        };
    }

    /** @param array<int, array{company: string, amount: int, ok: bool}> $rows */
    public function goLiveSummary(array $rows): void
    {
        if (! $rows) {
            return;
        }
        $lines = array_map(fn ($r) => ($r['ok'] ? '✓ ' : '✗ ').$r['company'].': '.Money::format($r['amount']).($r['ok'] ? '' : ' (declined)'), $rows);
        $this->send('go_lives', 'Today’s go-lives ('.count($rows).')', implode("\n", $lines), url('/admin/invoices?tab=upcoming'));
    }
}

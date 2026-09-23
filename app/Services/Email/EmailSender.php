<?php

namespace App\Services\Email;

use App\Jobs\SendEmail;
use App\Models\Admin;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\Invoice;
use App\Services\Settings\SettingsService;

/**
 * Sends a template email to a customer: renders it, records it in the email
 * log and queues delivery through Brevo. Disabled templates are skipped.
 */
final class EmailSender
{
    public function __construct(
        private readonly EmailRenderer $renderer,
        private readonly CustomerEmailValues $values,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<int, array{path: string, name: string}>  $attachments  files on the private disk
     */
    public function toCustomer(string $templateKey, Customer $customer, ?Invoice $invoice = null, array $attachments = [], array $extra = []): ?EmailLog
    {
        $template = EmailTemplate::where('key', $templateKey)->first();
        if (! $template || ! $template->is_enabled) {
            return null;
        }

        $mail = $this->renderer->render($template, $this->values->for($customer, $invoice, $extra));
        $cc = $template->cc_team ? $this->teamCc() : [];

        $log = EmailLog::create([
            'customer_id' => $customer->id,
            'template_key' => $templateKey,
            'to_email' => $customer->email,
            'cc' => $cc,
            'subject' => $mail['subject'],
            'status' => 'queued',
        ]);

        SendEmail::dispatch($log->id, $customer->fullName(), $mail['html'], $mail['text'], $attachments);

        return $log;
    }

    /** Admin emails (invitation, password reset) through the same templates and delivery. */
    public function toAdmin(string $templateKey, Admin $admin, array $values): ?EmailLog
    {
        $template = EmailTemplate::where('key', $templateKey)->first();
        if (! $template || ! $template->is_enabled) {
            return null;
        }
        $mail = $this->renderer->render($template, $values + ['admin_name' => $admin->name]);
        $log = EmailLog::create(['template_key' => $templateKey, 'to_email' => $admin->email, 'cc' => [], 'subject' => $mail['subject'], 'status' => 'queued']);
        SendEmail::dispatch($log->id, $admin->name, $mail['html'], $mail['text'], []);

        return $log;
    }

    /** Internal alerts to the team CC addresses (first address is To, the rest CC). */
    public function toTeam(string $templateKey, array $values): ?EmailLog
    {
        $template = EmailTemplate::where('key', $templateKey)->first();
        $team = $this->teamCc();
        if (! $template || ! $template->is_enabled || ! $team) {
            return null;
        }
        $mail = $this->renderer->render($template, $values);
        $log = EmailLog::create(['template_key' => $templateKey, 'to_email' => $team[0], 'cc' => array_slice($team, 1), 'subject' => $mail['subject'], 'status' => 'queued']);
        SendEmail::dispatch($log->id, 'RightAlly team', $mail['html'], $mail['text'], []);

        return $log;
    }

    /** Sends a rendered test of a template to an admin, with sample values. */
    public function test(EmailTemplate $template, string $to, array $sampleValues): EmailLog
    {
        $mail = $this->renderer->render($template, $sampleValues);
        $log = EmailLog::create([
            'template_key' => $template->key,
            'to_email' => $to,
            'cc' => [],
            'subject' => '[Test] '.$mail['subject'],
            'status' => 'queued',
        ]);
        SendEmail::dispatch($log->id, '', $mail['html'], $mail['text'], []);

        return $log;
    }

    /** @return array<int, string> */
    public function teamCc(): array
    {
        return array_values(array_filter(array_map(
            fn ($e) => strtolower(trim($e)),
            explode(',', (string) $this->settings->get('email', 'team_cc'))
        ), fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

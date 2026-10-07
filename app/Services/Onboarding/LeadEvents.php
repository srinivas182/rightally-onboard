<?php

namespace App\Services\Onboarding;

use App\Jobs\SyncGhlContact;
use App\Models\Customer;
use App\Services\Email\EmailSender;
use App\Services\Integrations\GhlContacts;
use App\Services\Integrations\TeamAlerts;
use App\Services\Integrations\Webhooks;
use App\Services\Settings\SettingsService;
use App\Support\UsPhone;

/** Someone finished "About you": tell the team, sync GoHighLevel, notify webhooks. */
final class LeadEvents
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly EmailSender $email,
        private readonly TeamAlerts $alerts,
        private readonly GhlContacts $ghl,
    ) {}

    public function created(Customer $customer): void
    {
        $values = [
            'company_name' => $customer->company_name,
            'lead_name' => $customer->fullName(),
            'lead_title' => (string) $customer->title,
            'lead_email' => $customer->email,
            'lead_phone' => UsPhone::format($customer->phone_e164),
            'coupon_code' => $customer->coupon?->code ?? ($customer->quote_id ? 'Custom quote' : 'None'),
            'lead_source' => $customer->source ?: 'Direct',
            'admin_link' => route('admin.customers.show', $customer),
        ];

        if ($this->alerts->enabled('new_lead')) {
            foreach ($this->recipients() as $to) {
                $this->email->toAddress('lead_alert', $to, 'RightAlly team', $values);
            }
            // Slack too, if a Slack webhook is set (no extra email; the lead email above already went out).
            $this->alerts->send('new_lead', "New lead: {$customer->company_name}", "{$values['lead_name']}, {$values['lead_email']}, {$values['lead_phone']} · coupon {$values['coupon_code']} · {$values['lead_source']}", $values['admin_link'], email: false);
        }
        if ($this->ghl->isConfigured()) {
            SyncGhlContact::dispatch($customer->id, [GhlContacts::STAGE_TAGS['customer.lead']]);
        }
        app(Webhooks::class)->emit('customer.lead', $customer);
    }

    /** @return array<int, string> */
    public function recipients(): array
    {
        return array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) $this->settings->get('alerts', 'lead_recipients'))),
            fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }
}

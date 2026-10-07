<?php

namespace App\Services\Integrations;

use App\Models\Customer;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Keeps GoHighLevel contacts in step with onboarding: a contact is created (or updated) when
 * someone finishes "About you", and tagged as they progress (signed, paid, live, ended).
 * Uses a GHL Private Integration token + Location ID (Settings > Alerts and integrations).
 */
final class GhlContacts
{
    private const API = 'https://services.leadconnectorhq.com';

    public const STAGE_TAGS = [
        'customer.lead' => 'rightally-lead',
        'customer.signed' => 'rightally-signed',
        'customer.deposit_paid' => 'rightally-paid',
        'customer.live' => 'rightally-live',
        'customer.cancelled' => 'rightally-ended',
        'customer.expired' => 'rightally-ended',
    ];

    public function __construct(private readonly SettingsService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get('alerts', 'ghl_token')) && filled($this->settings->get('alerts', 'ghl_location_id'));
    }

    /** Creates or updates the contact (matched by email/phone in GHL) and adds the tags. */
    public function upsert(Customer $customer, array $tags = []): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }
        $code = $customer->coupon?->code;
        $body = array_filter([
            'locationId' => $this->settings->get('alerts', 'ghl_location_id'),
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'name' => trim($customer->first_name.' '.$customer->last_name),
            'email' => $customer->email,
            'phone' => $customer->phone_e164,
            'companyName' => $customer->company_name,
            'address1' => $customer->street,
            'city' => $customer->city,
            'state' => $customer->state_code,
            'postalCode' => $customer->zip,
            'country' => 'US',
            'source' => 'RightAlly onboarding'.($customer->source ? " ({$customer->source})" : ''),
            'tags' => array_values(array_unique(array_filter(array_merge($tags, $code ? ['coupon:'.strtolower($code)] : [])))),
            'customFields' => $code ? [['key' => 'coupon_code', 'field_value' => $code]] : null,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $res = $this->request()->post(self::API.'/contacts/upsert', $body);
        if (! $res->successful()) {
            $this->fail('upsert', $res->status().' '.($res->json('message') ? json_encode($res->json('message')) : $res->body()));
        }
        $id = (string) ($res->json('contact.id') ?? $res->json('id') ?? '');
        if ($id !== '' && $customer->ghl_contact_id !== $id) {
            $customer->forceFill(['ghl_contact_id' => $id])->saveQuietly();
        }

        return $id ?: null;
    }

    private function request()
    {
        return Http::withToken((string) $this->settings->get('alerts', 'ghl_token'))
            ->withHeaders(['Version' => '2021-07-28'])->acceptJson()->timeout(15);
    }

    private function fail(string $what, string $detail): never
    {
        Cache::put('health:ghl_last_error', ['message' => mb_substr("GoHighLevel {$what}: {$detail}", 0, 400), 'at' => now()->getTimestamp()], now()->addDays(3));

        throw new RuntimeException("GoHighLevel {$what} failed: {$detail}");
    }
}

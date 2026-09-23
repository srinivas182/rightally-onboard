<?php

namespace App\Services\Settings;

/**
 * Every admin-editable setting: its tab (group), default value, whether it
 * is secret, and its validation rules. Adding a setting means adding it here.
 */
final class SettingsSchema
{
    /**
     * @return array<string, array{label: string, fields: array<string, array{default: mixed, rules: array<int, mixed>, secret?: bool, type?: string}>}>
     */
    public static function groups(): array
    {
        return [
            'company' => [
                'label' => 'Company',
                'fields' => [
                    'legal_name' => ['default' => 'Mayura Consultancy Services LLC', 'rules' => ['required', 'string', 'max:160']],
                    'dba' => ['default' => 'RightAlly', 'rules' => ['nullable', 'string', 'max:120']],
                    'address' => ['default' => '66 West Flagler Street, Suite 900, Miami, FL 33130', 'rules' => ['required', 'string', 'max:255']],
                    'phone' => ['default' => null, 'rules' => ['nullable', 'string', 'max:30']],
                    'support_email' => ['default' => 'support@rightally.io', 'rules' => ['required', 'email', 'max:160']],
                ],
            ],
            'pricing' => [
                'label' => 'Pricing',
                'fields' => [
                    'setup_fee' => ['default' => '3000.00', 'rules' => ['required', 'numeric', 'min:0', 'max:1000000'], 'type' => 'money'],
                    'deposit_percent' => ['default' => '10', 'rules' => ['required', 'numeric', 'min:0', 'max:100']],
                    'go_live_days' => ['default' => '30', 'rules' => ['required', 'integer', 'min:1', 'max:365']],
                    'platform_fee' => ['default' => '500.00', 'rules' => ['required', 'numeric', 'min:0', 'max:1000000'], 'type' => 'money'],
                    'per_agent_fee' => ['default' => '20.00', 'rules' => ['required', 'numeric', 'min:0', 'max:100000'], 'type' => 'money'],
                    'min_agents' => ['default' => '5', 'rules' => ['required', 'integer', 'min:1', 'max:10000']],
                ],
            ],
            'renewal' => [
                'label' => 'Renewal pricing',
                'fields' => [
                    // Blank = renew at the customer's original contract rates.
                    'platform_fee' => ['default' => null, 'rules' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'type' => 'money'],
                    'per_agent_fee' => ['default' => null, 'rules' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'type' => 'money'],
                ],
            ],
            'signature' => [
                'label' => 'Signature',
                'fields' => [
                    'signatory_name' => ['default' => 'Srini', 'rules' => ['required', 'string', 'max:120']],
                    'signatory_title' => ['default' => 'Co-Founder', 'rules' => ['required', 'string', 'max:120']],
                    'style' => ['default' => 'font', 'rules' => ['required', 'in:font,image']],
                    'image_path' => ['default' => null, 'rules' => []], // set by upload only
                ],
            ],
            'stripe' => [
                'label' => 'Stripe',
                'fields' => [
                    'mode' => ['default' => 'test', 'rules' => ['required', 'in:test,live']],
                    'test_publishable_key' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:pk_test_', 'max:255']],
                    'test_secret_key' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:sk_test_,rk_test_', 'max:255'], 'secret' => true],
                    'test_webhook_secret' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:whsec_', 'max:255'], 'secret' => true],
                    'live_publishable_key' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:pk_live_', 'max:255']],
                    'live_secret_key' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:sk_live_,rk_live_', 'max:255'], 'secret' => true],
                    'live_webhook_secret' => ['default' => null, 'rules' => ['nullable', 'string', 'starts_with:whsec_', 'max:255'], 'secret' => true],
                ],
            ],
            'email' => [
                'label' => 'Email',
                'fields' => [
                    'brevo_api_key' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255'], 'secret' => true],
                    'from_name' => ['default' => 'RightAlly', 'rules' => ['required', 'string', 'max:80']],
                    'from_email' => ['default' => 'no-reply@rightally.io', 'rules' => ['required', 'email', 'max:160']],
                    'team_cc' => ['default' => 'm.sunil@rightally.io, srini@rightally.io', 'rules' => ['nullable', 'string', 'max:500']],
                ],
            ],
            'security' => [
                'label' => 'Security',
                'fields' => [
                    // Cloudflare Turnstile on the public onboarding form. Off until both keys are set.
                    'turnstile_site_key' => ['default' => null, 'rules' => ['nullable', 'string', 'max:120']],
                    'turnstile_secret_key' => ['default' => null, 'rules' => ['nullable', 'string', 'max:255'], 'secret' => true],
                ],
            ],
            'alerts' => [
                'label' => 'Alerts',
                'fields' => [
                    'email' => ['default' => '1', 'rules' => ['boolean']],
                    'slack_webhook_url' => ['default' => null, 'rules' => ['nullable', 'url:https', 'starts_with:https://hooks.slack.com/', 'max:255'], 'secret' => true],
                    'new_signing' => ['default' => '1', 'rules' => ['boolean']],
                    'payment_failed' => ['default' => '1', 'rules' => ['boolean']],
                    'go_lives' => ['default' => '1', 'rules' => ['boolean']],
                    'chargebacks' => ['default' => '1', 'rules' => ['boolean']],
                ],
            ],
            'tax' => [
                'label' => 'Tax',
                'fields' => [
                    'enabled' => ['default' => '0', 'rules' => ['boolean']],
                ],
            ],
        ];
    }

    public static function isSecret(string $group, string $key): bool
    {
        return (bool) (self::groups()[$group]['fields'][$key]['secret'] ?? false);
    }

    public static function exists(string $group, string $key): bool
    {
        return isset(self::groups()[$group]['fields'][$key]);
    }
}

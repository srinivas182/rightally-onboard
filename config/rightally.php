<?php

/*
|--------------------------------------------------------------------------
| RightAlly application configuration
|--------------------------------------------------------------------------
| Values here are code-level constants. Anything an admin can change
| (fees, company details, keys) lives in the settings table and is read
| through App\Services\Settings\SettingsService.
*/

return [

    // Billing dates (go-live, monthly charges, renewals) are calculated in
    // this time zone. Timestamps are stored in UTC.
    'business_timezone' => env('RIGHTALLY_TIMEZONE', 'America/New_York'),

    // Days before the go-live date after which the date is locked.
    'go_live_lock_days' => 2,

    // Renewal agreement is sent this many days before the term ends,
    // with a reminder at the second value.
    'renewal_notice_days' => [45, 15],

    // Refunds and credits above this amount (cents) need a second admin's approval. Early terminations always do.
    'approval_threshold_cents' => 50000,

    // Days a payment can stay unpaid before the account is suspended.
    'suspend_after_days' => 30,

    /*
    | Admin menus. Each key is also a permission that roles can grant.
    | Order here is the order in the sidebar.
    */
    'menus' => [
        'dashboard' => ['label' => 'Dashboard', 'icon' => 'home', 'route' => 'admin.dashboard', 'group' => 'main'],
        'customers' => ['label' => 'Customers', 'icon' => 'users', 'route' => 'admin.customers.index', 'group' => 'main'],
        'contracts' => ['label' => 'Contracts', 'icon' => 'file', 'route' => 'admin.contracts.index', 'group' => 'main'],
        'invoices' => ['label' => 'Invoices', 'icon' => 'receipt', 'route' => 'admin.invoices.index', 'group' => 'main'],
        'coupons' => ['label' => 'Coupons', 'icon' => 'tag', 'route' => 'admin.coupons.index', 'group' => 'main'],
        'former' => ['label' => 'Former customers', 'icon' => 'users', 'route' => 'admin.former.index', 'group' => 'main', 'permission' => 'customers'],
        'calls' => ['label' => 'Calls', 'icon' => 'cal', 'route' => 'admin.calls.index', 'group' => 'main'],
        'quotes' => ['label' => 'Custom quotes', 'icon' => 'quote', 'route' => 'admin.quotes.index', 'group' => 'main'],
        'reports' => ['label' => 'Reports', 'icon' => 'chart', 'route' => 'admin.reports.index', 'group' => 'main'],
        'email_templates' => ['label' => 'Email templates', 'icon' => 'mail', 'route' => 'admin.email-templates.index', 'group' => 'admin'],
        'admins' => ['label' => 'Admins and roles', 'icon' => 'shield', 'route' => 'admin.admins.index', 'group' => 'admin'],
        'settings' => ['label' => 'Settings', 'icon' => 'gear', 'route' => 'admin.settings.index', 'group' => 'admin'],
    ],

    // Name of the built-in role that always has every permission.
    'super_admin_role' => 'Super admin',

    // First super admin, created by the seeder (read through config so it
    // still works when the config is cached).
    'first_super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super admin'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];

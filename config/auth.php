<?php

use App\Models\Admin;
use App\Models\Customer;

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| Only back-office admins sign in to this application. Clients never have
| accounts: they reach their onboarding steps through signed, unguessable
| links, so there is a single "admin" guard.
*/

return [

    'defaults' => [
        'guard' => 'admin',
        'passwords' => 'admins',
    ],

    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
        // Clients signing in to their account page.
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],
    ],

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        // Client set-password and reset links: valid 24 hours.
        'customers' => [
            'provider' => 'customers',
            'table' => 'customer_password_reset_tokens',
            'expire' => 1440,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];

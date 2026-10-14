<?php

use App\Models\Admin;
use App\Models\ClientUser;

return [

    /*
    | Two separate login areas, each with its own guard and user table:
    |   admin  → App\Models\Admin      (staff, /api/admin/*)
    |   client → App\Models\ClientUser (customers, /api/client/*; model is built in a later step)
    | There is no default `users` table.
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'admin'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'admins'),
    ],

    'guards' => [
        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
        'client' => [
            'driver' => 'session',
            'provider' => 'client_users',
        ],
    ],

    'providers' => [
        'admins' => [
            'driver' => 'eloquent',
            'model' => Admin::class,
        ],
        'client_users' => [
            'driver' => 'eloquent',
            'model' => ClientUser::class,
        ],
    ],

    'passwords' => [
        'admins' => [
            'provider' => 'admins',
            'table' => 'admin_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
        // "Set your password" link in a new staff member's invite email: valid for 48 hours.
        'admin_invites' => [
            'provider' => 'admins',
            'table' => 'admin_invitation_tokens',
            'expire' => 2880,
            'throttle' => 60,
        ],
        'client_users' => [
            'provider' => 'client_users',
            'table' => 'client_user_password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];

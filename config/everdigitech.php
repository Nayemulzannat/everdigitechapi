<?php

return [

    // Timezone of the business: date-only values shown to staff (e.g. a client's "joined on") use it.
    'timezone' => 'Asia/Dhaka',

    // Where the admin panel lives. Password-reset emails link to it.
    'admin_url' => rtrim((string) env('ADMIN_APP_URL', 'http://localhost:3003'), '/'),

    // Where the client panel lives. Emails to customers (verify your email, new sign-in) link to it.
    'client_url' => rtrim((string) env('CLIENT_APP_URL', 'http://localhost:3002'), '/'),

    // How long an email-verification link works, in minutes.
    'email_verification_minutes' => 60,

    // Money. Prices are in this currency, as whole paisa (100 paisa = 1 taka).
    'currency' => 'BDT',

    // Tax (VAT) on what clients buy. `rate` is a percent. With `inclusive` false the prices you enter in the catalog are
    // WITHOUT tax and tax is added on top; with true they already contain it. Change them in .env, no code change.
    'tax' => [
        'label' => env('TAX_LABEL', 'VAT'),
        'rate' => (float) env('TAX_RATE', 15),
        'inclusive' => (bool) env('PRICES_INCLUDE_TAX', false),
    ],

    // The logo at the top of every email: a picture needs an address the reader's mail program can open, so it is used
    // only when this site is on a public address (APP_URL is not localhost), or when MAIL_LOGO_URL is set. Otherwise the
    // emails show the name as text. The file is public/images/everdigitech-email.png.
    'mail_logo_url' => env('MAIL_LOGO_URL') ?: (in_array(parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST), [null, 'localhost', '127.0.0.1'], true)
        ? null
        : rtrim((string) env('APP_URL'), '/').'/images/everdigitech-email.png'),

    // The support mailbox. Clients get ticket replies from it (they can reply to it), and every new ticket and every
    // client reply is emailed to it. Forward it to the staff in the mailbox settings (cPanel > Forwarders).
    'support' => [
        'email' => env('SUPPORT_EMAIL', 'support@everdigitech.com'),
        'name' => env('SUPPORT_NAME', 'EverDigitech Support'),
    ],

    // First Super Admin, created by `php artisan db:seed --class=SuperAdminSeeder`.
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME'),
        'email' => env('SUPER_ADMIN_EMAIL'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];

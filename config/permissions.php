<?php

/*
| The permission catalog: the single source of truth for what an admin role can be granted.
| Mirrors MOCK_MODULES in the admin panel (admin/lib/mock/roles.ts): same keys, labels and order.
|
| Every permission is named `module.action` (e.g. "clients.view", "invoices.refund"), stored with guard `admin`.
| A module's permissions are its `actions`, then its optional `special` one. `view` comes first and every other
| permission of a module requires it.
*/

return [

    'modules' => [
        [
            'key' => 'clients',
            'label' => 'Clients',
            'description' => 'Profiles, contacts and notes',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'login_as', 'label' => 'Log in as client'],
        ],
        [
            'key' => 'orders',
            'label' => 'Orders',
            'description' => 'New orders and fraud checks',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'approve', 'label' => 'Approve or cancel'],
        ],
        [
            'key' => 'invoices',
            'label' => 'Invoices',
            'description' => 'Billing, credit notes, quotes',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'refund', 'label' => 'Issue refunds'],
        ],
        [
            'key' => 'payments',
            'label' => 'Payments',
            'description' => 'Transactions and gateways',
            'actions' => ['view', 'create', 'edit'],
            'special' => ['action' => 'approve', 'label' => 'Approve manual payments'],
        ],
        [
            'key' => 'services',
            'label' => 'Hosting services',
            'description' => 'Accounts on cPanel and other panels',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'server_login', 'label' => 'Server sign-in'],
        ],
        [
            'key' => 'domains',
            'label' => 'Domains',
            'description' => 'Registrations, renewals, transfers',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'dns', 'label' => 'DNS and EPP codes'],
        ],
        [
            'key' => 'servers',
            'label' => 'Servers',
            'description' => 'Servers, groups and API keys',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => null,
        ],
        [
            'key' => 'products',
            'label' => 'Products & pricing',
            'description' => 'Packages, TLD prices, coupons',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => null,
        ],
        [
            'key' => 'tickets',
            'label' => 'Support tickets',
            'description' => 'Replies, departments, canned replies',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => ['action' => 'assign', 'label' => 'Assign to staff'],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'description' => 'Income, growth and staff reports',
            'actions' => ['view'],
            'special' => ['action' => 'export', 'label' => 'Export data'],
        ],
        [
            'key' => 'staff',
            'label' => 'Staff',
            'description' => 'Staff accounts and sign-in history',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => null,
        ],
        [
            'key' => 'roles',
            'label' => 'Roles & permissions',
            'description' => 'This page',
            'actions' => ['view', 'create', 'edit', 'delete'],
            'special' => null,
        ],
        [
            'key' => 'settings',
            'label' => 'Settings',
            'description' => 'Company, email, automation',
            'actions' => ['view', 'edit'],
            'special' => null,
        ],
    ],

    // Only a Super Admin can grant permissions of these modules.
    'super_admin_only_modules' => ['staff', 'roles'],

];

<?php

namespace App\Support;

/**
 * What an account owner can give to a team member. The owner always has everything. Managing the team, billing details
 * and closing the account are owner-only and are not in this list.
 * Routes check these with the `account.permission:<key>` middleware.
 */
class AccountPermissionCatalog
{
    /** @return array<string, array{label: string, permissions: array<string, string>}> group => label and permission => label */
    public static function groups(): array
    {
        return [
            'orders' => ['label' => 'Orders', 'permissions' => ['orders.view' => 'See orders', 'orders.create' => 'Place new orders']],
            'invoices' => ['label' => 'Invoices', 'permissions' => ['invoices.view' => 'See invoices', 'invoices.pay' => 'Pay invoices']],
            'hosting' => ['label' => 'Hosting and servers', 'permissions' => ['hosting.view' => 'See hosting and servers', 'hosting.manage' => 'Manage hosting and servers']],
            'domains' => ['label' => 'Domains', 'permissions' => ['domains.view' => 'See domains', 'domains.manage' => 'Manage domains and DNS']],
            'tickets' => ['label' => 'Support', 'permissions' => ['tickets.view' => 'See support tickets', 'tickets.create' => 'Open and answer tickets']],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_map(fn (array $group) => array_keys($group['permissions']), array_values(self::groups())));
    }
}

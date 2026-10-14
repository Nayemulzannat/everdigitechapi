<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `account.permission:invoices.view`: the account's owner passes everything; a member needs the permission(s) in their
 * membership. Runs after `account.context`.
 */
class RequireAccountPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $membership = $request->currentMembership();

        if (! $membership) {
            throw new ApiException(403, 'account_access_denied', "You don't have access to this account.");
        }

        if (! $membership->isOwner()) {
            $granted = (array) ($membership->permissions ?? []);
            foreach ($permissions as $permission) {
                if (! in_array($permission, $granted, true)) {
                    throw new ApiException(403, 'account_permission_denied', "You don't have permission to do that in this account.");
                }
            }
        }

        return $next($request);
    }
}

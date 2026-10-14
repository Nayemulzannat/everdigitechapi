<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `account.owner`: only the owner of the current account (billing details, inviting members, closing the account). Runs after `account.context`. */
class RequireAccountOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->currentMembership()?->isOwner()) {
            throw new ApiException(403, 'account_owner_only', 'Only the owner of this account can do that.');
        }

        return $next($request);
    }
}

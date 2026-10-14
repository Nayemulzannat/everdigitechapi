<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AccountContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `account.context`: decides which account this request is about and checks the signed-in client may work in it.
 * The client panel sends the X-Account-Id header; without it the last-used account, then the one they own.
 * Afterwards use $request->currentAccount() and $request->currentMembership(). Runs after `auth:client`.
 */
class ResolveAccountContext
{
    public function __construct(private readonly AccountContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('client');
        $membership = $user ? $this->context->resolve($user, $request->header('X-Account-Id')) : null;

        if (! $membership) {
            throw new ApiException(403, 'account_access_denied', "You don't have access to this account.");
        }

        $request->attributes->set('current_membership', $membership);
        $request->attributes->set('current_account', $membership->account);

        return $next($request);
    }
}

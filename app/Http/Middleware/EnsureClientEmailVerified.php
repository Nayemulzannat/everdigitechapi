<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `client.verified`: 403 `email_not_verified` until the signed-in client has confirmed their email. For things that
 * need a real, reachable person: ordering, payments and tickets. Runs after `auth:client`.
 */
class EnsureClientEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('client')?->hasVerifiedEmail()) {
            throw new ApiException(403, 'email_not_verified', 'Verify your email address first. We sent you a link when you signed up.');
        }

        return $next($request);
    }
}

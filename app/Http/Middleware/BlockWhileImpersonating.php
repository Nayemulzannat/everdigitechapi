<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `client.not_impersonating`: for things staff must never do as a client (password, email, 2FA, payment methods,
 * deleting sessions, closing the account, inviting or removing team members). Changing the avatar is fine.
 */
class BlockWhileImpersonating
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->isActive($request)) {
            throw new ApiException(403, 'not_allowed_while_impersonating', 'This is not allowed while you are signed in as a client.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `client.active`: signs out a client who was suspended while already signed in, and ends a "log in as client" session
 * after 30 minutes. Runs after `auth:client`.
 */
class EnsureClientIsActive
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonation->expired($request)) {
            $this->impersonation->end($request, 'expired');

            throw new ApiException(401, 'impersonation_expired', 'The "log in as client" session has ended. Start it again from the admin panel.');
        }

        if ($request->user('client')?->isSuspended()) {
            Auth::guard('client')->logout();

            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact support.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\ClientSessionTracker;
use App\Services\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `client.session`: notes on every authenticated client request which browser this is (the "Your devices" list). Runs after `auth:client`. */
class TrackClientSession
{
    public function __construct(private readonly ClientSessionTracker $sessions, private readonly Impersonation $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('client');

        // Staff signed in as the client are not one of the client devices, so they are not listed.
        if ($user && $request->hasSession() && ! $this->impersonation->isActive($request)) {
            $this->sessions->touch($user, $request);
        }

        return $next($request);
    }
}

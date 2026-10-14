<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signs out an admin who was suspended while already signed in. Runs after `auth:admin`. */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('admin')?->isSuspended()) {
            Auth::guard('admin')->logout();

            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact a Super Admin.');
        }

        return $next($request);
    }
}

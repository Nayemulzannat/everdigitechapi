<?php

namespace App\Http\Controllers\Client\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientMeResource;
use App\Services\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ImpersonationController extends Controller
{
    /** POST /api/client/impersonate/consume {token} */
    public function consume(Request $request, Impersonation $impersonation): ClientMeResource
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);

        return new ClientMeResource($impersonation->consume($request, $data['token']));
    }

    /** POST /api/client/impersonate/exit: signs out the client guard only. Staff stay signed in to the admin panel. */
    public function exit(Request $request, Impersonation $impersonation): Response
    {
        if (! $impersonation->isActive($request)) {
            throw new ApiException(403, 'not_impersonating', 'You are not signed in as a client.');
        }

        $impersonation->end($request, 'exit');

        return response()->noContent();
    }
}

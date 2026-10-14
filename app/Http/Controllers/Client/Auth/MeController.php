<?php

namespace App\Http\Controllers\Client\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\SwitchAccountRequest;
use App\Http\Resources\ClientMeResource;
use App\Services\AccountContext;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** GET /api/client/me */
    public function show(Request $request): ClientMeResource
    {
        return new ClientMeResource($request->user('client'));
    }

    /** PUT /api/client/current-account {account_id}: remembers which account the panel opens on. */
    public function switchAccount(SwitchAccountRequest $request, AccountContext $context): ClientMeResource
    {
        $user = $request->user('client');

        if (! $context->membershipIn($user, (int) $request->validated('account_id'))) {
            throw new ApiException(403, 'account_access_denied', "You don't have access to this account.");
        }

        $user->forceFill(['last_account_id' => $request->validated('account_id')])->save();

        return new ClientMeResource($user->refresh());
    }
}

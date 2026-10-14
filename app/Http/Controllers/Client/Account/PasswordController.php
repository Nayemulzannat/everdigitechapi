<?php

namespace App\Http\Controllers\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ChangePasswordRequest;
use App\Http\Resources\MessageResource;
use App\Notifications\ClientPasswordChangedNotification;
use App\Services\ClientSessionTracker;

class PasswordController extends Controller
{
    /** PUT /api/client/password {current_password, password, password_confirmation} */
    public function update(ChangePasswordRequest $request, ClientSessionTracker $sessions): MessageResource
    {
        $user = $request->user('client');

        $user->forceFill(['password' => $request->validated('password')])->save();

        // Every other browser is signed out; this one stays.
        $signedOut = $sessions->revokeOthers($user, $request->session());

        $user->notify(new ClientPasswordChangedNotification(now(), $request->ip()));

        return new MessageResource('Your password was changed.'.($signedOut > 0 ? " You were signed out of {$signedOut} other ".($signedOut === 1 ? 'device' : 'devices').'.' : ''), ['signed_out' => $signedOut]);
    }
}

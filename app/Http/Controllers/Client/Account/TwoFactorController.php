<?php

namespace App\Http\Controllers\Client\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Notifications\ClientTwoFactorChangedNotification;
use App\Services\ActivityLogger;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Two-factor authentication of the signed-in client. Turning it on is two steps: setup (a secret to put in an authenticator
 * app) and confirm (a code from the app proves it works; only then it is on and recovery codes are shown, once).
 * Staff signed in as the client cannot change it (client.not_impersonating).
 */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly ActivityLogger $activity) {}

    /** GET /api/client/two-factor */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user('client');

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'recovery_codes_left' => $user->hasTwoFactorEnabled() ? $this->twoFactor->recoveryCodesLeft($user) : 0,
        ]]);
    }

    /** POST /api/client/two-factor/setup {password}: a new secret (not on yet). Calling it again replaces an unconfirmed secret. */
    public function setup(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:client']], ['password.current_password' => 'Your password is incorrect.']);
        $user = $request->user('client');

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(422, 'two_factor_already_on', 'Two-factor authentication is already on. Turn it off first to set it up again.');
        }

        $secret = $this->twoFactor->newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null, 'two_factor_recovery_codes' => null])->save();

        return response()->json(['data' => ['secret' => $secret, 'otpauth_url' => $this->twoFactor->uri($user->email, $secret)]]);
    }

    /** POST /api/client/two-factor/confirm {code}: turns it on and returns the recovery codes (shown once). */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user('client');

        if ($user->hasTwoFactorEnabled()) {
            throw new ApiException(422, 'two_factor_already_on', 'Two-factor authentication is already on.');
        }
        if (! $user->two_factor_secret) {
            throw new ApiException(422, 'two_factor_not_started', 'Start the setup first.');
        }
        if (! $this->twoFactor->verify($user, $data['code'])) {
            throw new ApiException(422, 'invalid_two_factor_code', 'That code is not right. Check the code in your app and try again.');
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $this->twoFactor->newRecoveryCodes($user);

        $user->notify(new ClientTwoFactorChangedNotification(true, now(), $request->ip()));
        $this->activity->log('client.two_factor_enabled', $user);

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    /** POST /api/client/two-factor/recovery-codes {password}: new codes; the old ones stop working. */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password:client']], ['password.current_password' => 'Your password is incorrect.']);
        $user = $this->enabled($request);

        $this->activity->log('client.two_factor_recovery_codes_renewed', $user);

        return response()->json(['data' => ['recovery_codes' => $this->twoFactor->newRecoveryCodes($user)]]);
    }

    /** DELETE /api/client/two-factor {password, code}: turns it off. The code is one from the app or a recovery code. */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'current_password:client'], 'code' => ['required', 'string', 'max:30']], ['password.current_password' => 'Your password is incorrect.']);
        $user = $this->enabled($request);

        if (! $this->twoFactor->verify($user, $data['code']) && ! $this->twoFactor->useRecoveryCode($user, $data['code'])) {
            throw new ApiException(422, 'invalid_two_factor_code', 'That code is not right. Use a code from your app, or a recovery code.');
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null, 'two_factor_last_step' => null])->save();

        $user->notify(new ClientTwoFactorChangedNotification(false, now(), $request->ip()));
        $this->activity->log('client.two_factor_disabled', $user);

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    private function enabled(Request $request): ClientUser
    {
        $user = $request->user('client');
        if (! $user->hasTwoFactorEnabled()) {
            throw new ApiException(422, 'two_factor_not_on', 'Two-factor authentication is not on.');
        }

        return $user;
    }
}

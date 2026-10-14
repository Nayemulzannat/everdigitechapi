<?php

namespace App\Http\Controllers\Client\Auth;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ClientMeResource;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Notifications\ClientNewSignInNotification;
use App\Services\AccountContext;
use App\Services\ClientSessionTracker;
use App\Services\GeoIp\GeoIpLookup;
use App\Services\GoogleIdTokenVerifier;
use App\Support\UserAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GoogleLoginController extends Controller
{
    /**
     * POST /api/client/google {credential}: signs in, or signs up, with a Google account.
     *
     * - Known Google id: signs in.
     * - Known email: the Google account is linked to it. If that email was never confirmed here, the password is
     *   replaced first, so someone who registered with another person's address cannot keep access after the real
     *   owner signs in with Google.
     * - Unknown: a new client and account are created, with the email already verified (Google did that).
     */
    public function __invoke(
        Request $request,
        GoogleIdTokenVerifier $google,
        ClientSessionTracker $sessions,
        AccountContext $accounts,
        GeoIpLookup $geoIp,
    ): JsonResponse {
        $credential = $request->validate(['credential' => ['required', 'string', 'max:4096']])['credential'];
        $profile = $google->verify($credential);

        $user = ClientUser::withTrashed()->where('google_id', $profile['sub'])->first()
            ?? ClientUser::withTrashed()->where('email', $profile['email'])->first();

        if ($user?->trashed()) {
            throw new ApiException(403, 'account_closed', 'This account is closed. Contact support.');
        }

        $created = $user === null;
        $knownDevice = true;
        $agent = UserAgent::parse($request->userAgent());

        if ($created) {
            $user = $this->createClient($profile);
        } else {
            if ($user->isSuspended()) {
                $this->record($request, $user, 'suspended');

                throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact support.');
            }

            if ($user->google_id !== null && $user->google_id !== $profile['sub']) {
                // The email belongs to a client linked to a different Google account.
                throw new ApiException(409, 'google_account_mismatch', 'This email is linked to a different Google account.');
            }

            $knownDevice = $user->loginHistories()->where('successful', true)
                ->where('browser', $agent['browser'])->where('platform', $agent['platform'])->exists();

            $this->link($user, $profile['sub'], $sessions);
        }

        Auth::guard('client')->login($user);
        $request->session()->regenerate();

        $sessions->start($user, $request);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_account_id' => $user->last_account_id ?? $accounts->resolve($user)?->account_id,
        ])->save();
        $this->record($request, $user, null);

        if (! $created && ! $knownDevice) {
            $device = implode(' on ', array_filter($agent)) ?: 'Unknown device';
            $user->notify(new ClientNewSignInNotification(now(), $geoIp->lookup((string) $request->ip()), $device, (string) $request->ip()));
        }

        return (new ClientMeResource($user))->response()->setStatusCode($created ? 201 : 200);
    }

    /** @param  array{sub: string, email: string, name: string}  $profile */
    private function createClient(array $profile): ClientUser
    {
        return DB::transaction(function () use ($profile) {
            $user = ClientUser::create([
                'name' => $profile['name'],
                'email' => $profile['email'],
                // Nobody knows this password. "Forgot password" lets the person choose one later.
                'password' => Str::random(40),
                'email_verified_at' => now(),
            ]);
            $user->forceFill(['google_id' => $profile['sub']])->save();

            $account = ClientAccount::create([
                'name' => $user->name,
                'owner_user_id' => $user->getKey(),
                'client_group' => ClientGroup::Regular,
                'status' => ClientStatus::Active,
            ]);

            AccountMember::create([
                'account_id' => $account->getKey(),
                'user_id' => $user->getKey(),
                'role' => MemberRole::Owner,
                'status' => MemberStatus::Active,
                'joined_at' => now(),
            ]);

            $user->forceFill(['last_account_id' => $account->getKey()])->save();

            return $user;
        });
    }

    private function link(ClientUser $user, string $googleId, ClientSessionTracker $sessions): void
    {
        if ($user->google_id === $googleId) {
            return;
        }

        if ($user->email_verified_at === null) {
            // The password may belong to whoever typed this address in the sign-up form, not to its owner.
            $user->forceFill(['password' => Str::random(40)]);
            $sessions->revokeAll($user);
        }

        $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
    }

    private function record(Request $request, ClientUser $user, ?string $failureReason): void
    {
        ClientLoginHistory::create([
            'client_user_id' => $user->getKey(),
            'email_attempted' => $user->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            ...UserAgent::parse($request->userAgent()),
            'successful' => $failureReason === null,
            'failure_reason' => $failureReason,
        ]);
    }
}

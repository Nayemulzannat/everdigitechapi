<?php

namespace App\Http\Controllers\Client\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\LoginRequest;
use App\Http\Resources\ClientMeResource;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Notifications\ClientNewSignInNotification;
use App\Services\AccountContext;
use App\Services\ClientLoginThrottle;
use App\Services\ClientSessionTracker;
use App\Services\GeoIp\GeoIpLookup;
use App\Services\Impersonation;
use App\Services\TwoFactor;
use App\Support\UserAgent;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    /** Session key of a sign-in that has passed the password and waits for its two-factor code. */
    private const PENDING = 'client_two_factor_pending';

    /** Checked when the email is unknown, so a miss costs the same time as a wrong password. */
    private const DUMMY_HASH = '$2y$12$LPJ6lLKMX1HelXL34gDlVusuzn5m7LwclNeFuAJPv9IGNWkjC2Bdq';

    public function __construct(
        private readonly ClientLoginThrottle $throttle,
        private readonly AccountContext $accounts,
        private readonly GeoIpLookup $geoIp,
        private readonly ClientSessionTracker $sessions,
    ) {}

    /** POST /api/client/login */
    public function login(LoginRequest $request): ClientMeResource|JsonResponse
    {
        $email = strtolower(trim($request->validated('email')));
        $ip = (string) $request->ip();
        $user = ClientUser::where('email', $email)->first();

        if ($until = $this->throttle->lockedUntil($email, $ip, $user)) {
            $this->record($request, $email, $user, 'locked');
            throw $this->locked($until);
        }

        $passwordOk = Hash::check($request->validated('password'), $user?->password ?? self::DUMMY_HASH);

        if (! $user || ! $passwordOk) {
            $this->record($request, $email, $user, 'invalid_credentials');

            // The attempt that reaches the limit already gets the lock answer.
            if ($until = $this->throttle->hit($email, $ip, $user)) {
                throw $this->locked($until);
            }

            throw new ApiException(422, 'invalid_credentials', 'These credentials do not match our records.');
        }

        if ($user->isSuspended()) {
            $this->record($request, $email, $user, 'suspended');

            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact support.');
        }

        // Two-factor is on: the password was right, but nobody is signed in until the code is too.
        if ($user->hasTwoFactorEnabled() && $request->hasSession()) {
            $request->session()->put(self::PENDING, ['user_id' => $user->getKey(), 'email' => $email, 'remember' => $request->boolean('remember'), 'expires' => now()->addMinutes(5)->getTimestamp(), 'attempts' => 0]);

            return response()->json(['two_factor_required' => true, 'message' => 'Enter the code from your authenticator app.'], 202);
        }

        return $this->completeSignIn($request, $user, $email, $request->boolean('remember'));
    }

    /**
     * POST /api/client/two-factor-challenge {code} or {recovery_code}: the second step of a sign-in with two-factor on.
     * The password step must have happened in this session in the last 5 minutes; 5 wrong codes end it.
     */
    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor): ClientMeResource|JsonResponse
    {
        $data = $request->validate(['code' => ['required_without:recovery_code', 'nullable', 'string', 'max:20'], 'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:30']]);
        $pending = $request->hasSession() ? $request->session()->get(self::PENDING) : null;
        $user = $pending && $pending['expires'] > now()->getTimestamp() ? ClientUser::find($pending['user_id']) : null;

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            if ($request->hasSession()) {
                $request->session()->forget(self::PENDING);
            }

            throw new ApiException(422, 'two_factor_expired', 'Your sign-in took too long. Enter your email and password again.');
        }

        $ok = ! empty($data['code']) ? $twoFactor->verify($user, $data['code']) : $twoFactor->useRecoveryCode($user, (string) $data['recovery_code']);

        if (! $ok) {
            $pending['attempts']++;
            $this->record($request, $pending['email'], $user, 'two_factor_failed');

            if ($pending['attempts'] >= 5) {
                $request->session()->forget(self::PENDING);

                throw new ApiException(422, 'two_factor_expired', 'Too many wrong codes. Enter your email and password again.');
            }
            $request->session()->put(self::PENDING, $pending);

            throw new ApiException(422, 'invalid_two_factor_code', ! empty($data['code']) ? 'That code is not right. Check the code in your app and try again.' : 'That recovery code is not right.');
        }

        $request->session()->forget(self::PENDING);

        return $this->completeSignIn($request, $user, $pending['email'], (bool) $pending['remember']);
    }

    /** Signs the person in: session, device alert, history. Used after the password, or after the two-factor code. */
    private function completeSignIn(Request $request, ClientUser $user, string $email, bool $remember): ClientMeResource
    {
        $ip = (string) $request->ip();
        // Asked before this sign-in is written down, or every browser would "already be known".
        $agent = UserAgent::parse($request->userAgent());
        $knownDevice = $user->loginHistories()
            ->where('successful', true)
            ->where('browser', $agent['browser'])
            ->where('platform', $agent['platform'])
            ->exists();

        Auth::guard('client')->login($user, $remember);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->sessions->start($user, $request);
        $this->throttle->clear($email, $ip);
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
            // A person with no remembered account opens on the one they own.
            'last_account_id' => $user->last_account_id ?? $this->accounts->resolve($user)?->account_id,
        ])->save();
        $this->record($request, $email, $user, null);

        if (! $knownDevice) {
            $device = implode(' on ', array_filter($agent)) ?: 'Unknown device';
            $user->notify(new ClientNewSignInNotification(now(), $this->geoIp->lookup($ip), $device, $ip));
        }

        return new ClientMeResource($user);
    }

    /**
     * POST /api/client/logout. Signs out the client guard only: the session is not invalidated, because staff can be
     * signed in to the admin panel in the same browser. Safe to call when already signed out.
     */
    public function logout(Request $request, Impersonation $impersonation): Response
    {
        $impersonation->end($request, 'logout');

        $this->sessions->forget($request);
        Auth::guard('client')->logout();

        if ($request->hasSession()) {
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function locked(CarbonInterface $until): ApiException
    {
        $minutes = ClientLoginThrottle::minutesLeft($until);

        return new ApiException(
            429,
            'account_locked',
            'Too many failed sign-in attempts. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.',
            ['Retry-After' => max(1, (int) now()->diffInSeconds($until, true))],
            ['retry_after_minutes' => $minutes],
        );
    }

    private function record(Request $request, string $email, ?ClientUser $user, ?string $failureReason): void
    {
        $ua = $request->userAgent();

        ClientLoginHistory::create([
            'client_user_id' => $user?->getKey(),
            'email_attempted' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $ua,
            ...UserAgent::parse($ua),
            'successful' => $failureReason === null,
            'failure_reason' => $failureReason,
        ]);
    }
}

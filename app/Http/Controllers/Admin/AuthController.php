<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LoginRequest;
use App\Http\Resources\AdminMeResource;
use App\Models\Admin;
use App\Models\AdminLoginHistory;
use App\Services\AdminLoginThrottle;
use App\Support\UserAgent;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /** Checked when the email is unknown, so a miss costs the same time as a wrong password. */
    private const DUMMY_HASH = '$2y$12$LPJ6lLKMX1HelXL34gDlVusuzn5m7LwclNeFuAJPv9IGNWkjC2Bdq';

    public function __construct(private readonly AdminLoginThrottle $throttle) {}

    /** POST /api/admin/login */
    public function login(LoginRequest $request): AdminMeResource
    {
        $email = strtolower(trim($request->validated('email')));
        $ip = (string) $request->ip();
        $admin = Admin::where('email', $email)->first();

        if ($until = $this->throttle->lockedUntil($email, $ip, $admin)) {
            $this->recordAttempt($request, $email, $admin, 'locked');
            throw $this->locked($until);
        }

        $passwordOk = Hash::check($request->validated('password'), $admin?->password ?? self::DUMMY_HASH);

        if (! $admin || ! $passwordOk) {
            $this->recordAttempt($request, $email, $admin, 'invalid_credentials');

            // The attempt that reaches the limit already gets the lock response.
            if ($until = $this->throttle->hit($email, $ip, $admin)) {
                throw $this->locked($until);
            }

            throw new ApiException(422, 'invalid_credentials', 'These credentials do not match our records.');
        }

        if ($admin->isSuspended()) {
            $this->recordAttempt($request, $email, $admin, 'suspended');

            throw new ApiException(403, 'account_suspended', 'This account is suspended. Contact a Super Admin.');
        }

        Auth::guard('admin')->login($admin, $request->boolean('remember'));
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $this->throttle->clear($email, $ip);
        $admin->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();
        $this->recordAttempt($request, $email, $admin, null);

        return new AdminMeResource($admin);
    }

    /**
     * POST /api/admin/logout. Signs out the admin guard only: the session is not invalidated, because a client
     * can be signed in on the same browser with the `client` guard. Safe to call when already signed out.
     */
    public function logout(Request $request): Response
    {
        Auth::guard('admin')->logout();

        if ($request->hasSession()) {
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function locked(CarbonInterface $until): ApiException
    {
        $minutes = AdminLoginThrottle::minutesLeft($until);

        return new ApiException(
            429,
            'account_locked',
            'Too many failed sign-in attempts. Try again in '.$minutes.' '.($minutes === 1 ? 'minute' : 'minutes').'.',
            ['Retry-After' => max(1, (int) now()->diffInSeconds($until, true))],
            ['retry_after_minutes' => $minutes],
        );
    }

    private function recordAttempt(Request $request, string $email, ?Admin $admin, ?string $failureReason): void
    {
        $ua = $request->userAgent();

        ($admin?->loginHistories() ?? AdminLoginHistory::query())->create([
            'admin_id' => $admin?->getKey(),
            'email_attempted' => $email,
            'ip_address' => $request->ip(),
            'user_agent' => $ua,
            ...UserAgent::parse($ua),
            'successful' => $failureReason === null,
            'failure_reason' => $failureReason,
        ]);
    }
}

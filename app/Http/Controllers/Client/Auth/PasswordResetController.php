<?php

namespace App\Http\Controllers\Client\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ForgotPasswordRequest;
use App\Http\Requests\Client\ResetPasswordRequest;
use App\Http\Resources\MessageResource;
use App\Models\ClientUser;
use App\Notifications\ClientPasswordChangedNotification;
use App\Services\ClientLoginThrottle;
use App\Services\ClientSessionTracker;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    /** Reset requests per email address per hour. Unknown addresses count too, so the limit tells nothing about who has an account. */
    private const FORGOT_PER_HOUR = 3;

    public function __construct(
        private readonly ClientSessionTracker $sessions,
        private readonly ClientLoginThrottle $loginThrottle,
    ) {}

    /** POST /api/client/forgot-password. The same answer whether or not the email belongs to a client. */
    public function forgot(ForgotPasswordRequest $request): MessageResource
    {
        $email = strtolower(trim($request->validated('email')));
        $key = 'client-forgot:'.sha1($email);

        if (RateLimiter::tooManyAttempts($key, self::FORGOT_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            throw new ApiException(
                429,
                'too_many_requests',
                "Too many reset requests for this email. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.',
                ['Retry-After' => RateLimiter::availableIn($key)],
            );
        }
        RateLimiter::hit($key, 3600);

        try {
            Password::broker('client_users')->sendResetLink(['email' => $email]);
        } catch (Throwable $e) {
            // Never let a mail or queue failure show up only for real accounts: that would reveal which emails exist.
            report($e);
        }

        return new MessageResource('If that email belongs to an account, we have sent a password reset link.');
    }

    /** POST /api/client/reset-password */
    public function reset(ResetPasswordRequest $request): MessageResource
    {
        $email = strtolower(trim($request->validated('email')));

        $status = Password::broker('client_users')->reset(
            ['email' => $email, 'token' => $request->validated('token'), 'password' => $request->validated('password'), 'password_confirmation' => $request->input('password_confirmation')],
            function (ClientUser $user, string $password) use ($request, $email) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                ])->save();
                $this->loginThrottle->clear($email, (string) $request->ip());

                // Whoever knew the old password (or had a stolen session) is signed out everywhere.
                $this->sessions->revokeAll($user);

                event(new PasswordReset($user));
                $user->notify(new ClientPasswordChangedNotification(now(), $request->ip()));
            },
        );

        // Unknown email, wrong token and expired token all look the same.
        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException(422, 'invalid_reset_token', 'This password reset link is invalid or has expired.');
        }

        return new MessageResource('Your password has been changed. You can sign in now.');
    }
}

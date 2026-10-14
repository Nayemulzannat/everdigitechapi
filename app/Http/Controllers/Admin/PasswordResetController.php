<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ForgotPasswordRequest;
use App\Http\Requests\Admin\ResetPasswordRequest;
use App\Http\Resources\MessageResource;
use App\Models\Admin;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    /** Reset links (60 minutes) and staff invites (48 hours) both end here, each with its own token store. */
    private const BROKERS = ['admins', 'admin_invites'];

    /** POST /api/admin/forgot-password. Same answer whether or not the email belongs to an admin. */
    public function forgot(ForgotPasswordRequest $request): MessageResource
    {
        try {
            Password::broker('admins')->sendResetLink(['email' => strtolower($request->validated('email'))]);
        } catch (Throwable $e) {
            // A mail failure must not turn into an error that only happens for real accounts: that would tell an
            // attacker which emails exist. Log it for us and answer exactly as if the email had been sent.
            report($e);
        }

        return new MessageResource('If that email belongs to an admin account, we have sent a password reset link.');
    }

    /** POST /api/admin/reset-password */
    public function reset(ResetPasswordRequest $request): MessageResource
    {
        $credentials = [
            ...$request->safe()->only(['token', 'password', 'password_confirmation']),
            'email' => strtolower($request->validated('email')),
        ];

        $callback = function (Admin $admin, string $password) {
            $admin->forceFill([
                'password' => $password,
                'remember_token' => Str::random(60),
                'failed_login_attempts' => 0,
                'locked_until' => null,
                'invited_at' => null,
            ])->save();

            event(new PasswordReset($admin));
        };

        foreach (self::BROKERS as $broker) {
            if (Password::broker($broker)->reset($credentials, $callback) === Password::PASSWORD_RESET) {
                return new MessageResource('Your password has been set. You can sign in now.');
            }
        }

        // Unknown email, wrong token and expired token all look the same.
        throw new ApiException(422, 'invalid_reset_token', 'This password reset link is invalid or has expired.');
    }
}

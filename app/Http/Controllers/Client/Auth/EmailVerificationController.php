<?php

namespace App\Http\Controllers\Client\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\VerifyEmailRequest;
use App\Http\Resources\MessageResource;
use App\Models\ClientUser;
use App\Services\EmailVerificationLink;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationLink $links) {}

    /**
     * POST /api/client/email/verify {id, hash, expires, signature}: the values from the emailed link. Needs no sign-in:
     * people often open the link in another browser. Verifying twice is fine.
     */
    public function verify(VerifyEmailRequest $request): MessageResource
    {
        $user = ClientUser::find($request->validated('id'));
        $state = $user
            ? $this->links->check($user, $request->validated('hash'), $request->validated('expires'), $request->validated('signature'))
            : EmailVerificationLink::INVALID;

        if ($state === EmailVerificationLink::INVALID) {
            throw new ApiException(422, 'invalid_verification_link', 'This verification link is not valid. Use the link from your latest email.');
        }

        if ($user->hasVerifiedEmail()) {
            return new MessageResource('Your email address is already verified.');
        }

        if ($state === EmailVerificationLink::EXPIRED) {
            throw new ApiException(422, 'verification_link_expired', 'This verification link has expired. Sign in and ask for a new one.');
        }

        $user->markEmailAsVerified();
        event(new Verified($user));

        return new MessageResource('Your email address is verified.');
    }

    /** POST /api/client/email/verification-notification: sends the link again (at most 1 a minute and 6 an hour). */
    public function resend(Request $request): MessageResource
    {
        $user = $request->user('client');

        if ($user->hasVerifiedEmail()) {
            return new MessageResource('Your email address is already verified.');
        }

        $user->sendEmailVerificationNotification();

        return new MessageResource('We sent you a new verification link.');
    }
}

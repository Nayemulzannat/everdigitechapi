<?php

namespace App\Http\Controllers\Client\Account;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ChangeEmailRequest;
use App\Http\Requests\Client\ConfirmEmailChangeRequest;
use App\Http\Resources\MessageResource;
use App\Models\ClientUser;
use App\Models\PendingEmailChange;
use App\Notifications\ClientConfirmEmailChangeNotification;
use App\Notifications\ClientEmailChangedNotification;
use App\Services\EmailChangeLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class EmailChangeController extends Controller
{
    public function __construct(private readonly EmailChangeLink $links) {}

    /**
     * POST /api/client/email/change {new_email, password}. Saves the request and mails a link to the NEW address.
     * The account keeps its current email until that link is used.
     */
    public function request(ChangeEmailRequest $request): MessageResource
    {
        $user = $request->user('client');
        $newEmail = $request->validated('new_email');

        // One request at a time: asking again cancels the earlier link.
        $change = DB::transaction(function () use ($user, $newEmail) {
            PendingEmailChange::query()->where('client_user_id', $user->getKey())->delete();

            return PendingEmailChange::create([
                'client_user_id' => $user->getKey(),
                'new_email' => $newEmail,
                'expires_at' => now()->addMinutes(EmailChangeLink::MINUTES),
            ]);
        });

        Notification::route('mail', $newEmail)->notify(new ClientConfirmEmailChangeNotification($this->links->make($change), $user->name));

        return new MessageResource("We sent a confirmation link to {$newEmail}. Your current email address stays active until you confirm.");
    }

    /** POST /api/client/email/change/confirm {id, hash, expires, signature}. Needs no sign-in: the link is opened from another mailbox. */
    public function confirm(ConfirmEmailChangeRequest $request): MessageResource
    {
        $change = PendingEmailChange::query()->with('user')->find($request->validated('id'));
        $state = $change?->user
            ? $this->links->check($change, $request->validated('hash'), $request->validated('expires'), $request->validated('signature'))
            : EmailChangeLink::INVALID;

        if ($state === EmailChangeLink::INVALID) {
            throw new ApiException(422, 'invalid_email_change_link', 'This confirmation link is not valid. Use the link from your latest email.');
        }

        if ($state === EmailChangeLink::EXPIRED || $change->isExpired()) {
            $change->delete();

            throw new ApiException(422, 'email_change_link_expired', 'This confirmation link has expired. Ask for the change again from your account page.');
        }

        $user = $change->user;
        $newEmail = $change->new_email;

        // Someone else may have registered this address while the link was waiting.
        if (ClientUser::withTrashed()->where('email', $newEmail)->whereKeyNot($user->getKey())->exists()) {
            $change->delete();

            throw new ApiException(422, 'email_already_taken', 'That email address is now used by another account.');
        }

        $oldEmail = $user->email;
        DB::transaction(function () use ($user, $newEmail, $change) {
            // The link reached the new mailbox, so the new address is proven.
            $user->forceFill(['email' => $newEmail, 'email_verified_at' => now()])->save();
            $change->delete();
        });

        Notification::route('mail', $oldEmail)->notify(new ClientEmailChangedNotification($user->name, $newEmail));

        return new MessageResource('Your email address was changed.', ['email' => $newEmail]);
    }
}

<?php

namespace App\Http\Controllers\Client\Account;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientMeResource;
use App\Services\TeamInvitations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The invited person's side: look at the invitation, then accept it. */
class TeamInvitationController extends Controller
{
    public function __construct(private readonly TeamInvitations $invitations) {}

    /**
     * GET /api/client/team/invitation?token= (public): what the invitation is for, so the page can say "Join X".
     * The invited address is shown masked, so the link alone does not reveal it.
     */
    public function show(Request $request): JsonResponse
    {
        $token = (string) $request->query('token');
        $invitation = strlen($token) === 64 ? $this->invitations->find($token) : null;

        if (! $invitation) {
            return response()->json(['message' => 'This invitation is invalid, already used, or has expired. Ask for a new one.', 'code' => 'invalid_invitation'], 422);
        }

        return response()->json(['data' => [
            'account_name' => $invitation->account->name,
            'invited_by' => $invitation->inviter?->name,
            'email_hint' => TeamInvitations::mask($invitation->email),
            'expires_at' => $invitation->expires_at->toIso8601ZuluString(),
        ]]);
    }

    /** POST /api/client/team/invitations/accept {token}: signed in with the invited email address. Answers the /me shape. */
    public function accept(Request $request): ClientMeResource
    {
        $token = $request->validate(['token' => ['required', 'string', 'size:64']])['token'];
        $user = $request->user('client');

        $member = $this->invitations->accept($user, $token);

        // Open the panel on the account that was just joined.
        $user->forceFill(['last_account_id' => $member->account_id])->save();

        return new ClientMeResource($user->refresh());
    }
}

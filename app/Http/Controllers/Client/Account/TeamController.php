<?php

namespace App\Http\Controllers\Client\Account;

use App\Enums\MemberStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\InviteMemberRequest;
use App\Http\Requests\Client\UpdateMemberRequest;
use App\Http\Resources\ClientMeResource;
use App\Http\Resources\MessageResource;
use App\Http\Resources\TeamInvitationResource;
use App\Http\Resources\TeamMemberResource;
use App\Models\AccountInvitation;
use App\Models\AccountMember;
use App\Services\ActivityLogger;
use App\Services\TeamInvitations;
use App\Support\AccountPermissionCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The people of the current account (X-Account-Id). Everything here is for the owner, except leaving. Mutations are
 * refused while staff are signed in as the client (`client.not_impersonating`, see routes/api/client/team.php).
 */
class TeamController extends Controller
{
    public function __construct(private readonly TeamInvitations $invitations, private readonly ActivityLogger $activity) {}

    /** GET /api/client/team */
    public function index(Request $request): JsonResponse
    {
        $account = $request->currentAccount();

        $members = AccountMember::with('user')->where('account_id', $account->getKey())
            ->whereIn('status', [MemberStatus::Active->value, MemberStatus::Suspended->value])
            ->orderByRaw("role = 'owner' desc")->orderBy('id')->get();
        $invitations = AccountInvitation::with('inviter')->where('account_id', $account->getKey())->pending()->latest('id')->get();

        return response()->json(['data' => [
            'members' => TeamMemberResource::collection($members)->resolve($request),
            'invitations' => TeamInvitationResource::collection($invitations)->resolve($request),
            'permissions' => collect(AccountPermissionCatalog::groups())->map(fn ($group, $key) => [
                'key' => $key,
                'label' => $group['label'],
                'permissions' => collect($group['permissions'])->map(fn ($label, $permission) => ['key' => $permission, 'label' => $label])->values(),
            ])->values(),
            'limits' => ['max_people' => TeamInvitations::MAX_MEMBERS, 'invitation_days' => TeamInvitations::DAYS],
        ]]);
    }

    /** POST /api/client/team/invitations {email, permissions[]} */
    public function invite(InviteMemberRequest $request): JsonResponse
    {
        $invitation = $this->invitations->invite($request->currentAccount(), $request->user('client'), $request->validated('email'), $request->validated('permissions'));

        return (new TeamInvitationResource($invitation->load('inviter')))->response()->setStatusCode(201);
    }

    /** POST /api/client/team/invitations/{id}/resend */
    public function resend(Request $request, int $id): MessageResource
    {
        $invitation = $this->pendingInvitation($request, $id);
        $this->invitations->resend($invitation, $request->user('client'));

        return new MessageResource("We sent the invitation to {$invitation->email} again.");
    }

    /** DELETE /api/client/team/invitations/{id} */
    public function cancel(Request $request, int $id): MessageResource
    {
        $invitation = $this->pendingInvitation($request, $id);
        $invitation->delete();
        $this->activity->log('team.invitation_cancelled', $request->currentAccount(), ['email' => $invitation->email]);

        return new MessageResource('The invitation was cancelled.');
    }

    /** PUT /api/client/team/members/{id} {permissions?, status?} */
    public function update(UpdateMemberRequest $request, int $id): TeamMemberResource
    {
        $member = $this->member($request, $id);

        $member->fill($request->safe()->only(['permissions', 'status']))->save();
        $this->activity->log('team.member_updated', $request->currentAccount(), ['member_id' => $member->getKey(), 'changes' => $request->validated()]);

        return new TeamMemberResource($member->load('user'));
    }

    /** DELETE /api/client/team/members/{id}: they lose access at once. */
    public function remove(Request $request, int $id): MessageResource
    {
        $member = $this->member($request, $id);
        $member->delete(); // their per-service access rows go with it (cascade)
        $this->activity->log('team.member_removed', $request->currentAccount(), ['member_id' => $member->getKey(), 'email' => $member->user->email]);

        return new MessageResource($member->user->name.' was removed from the account.');
    }

    /** POST /api/client/team/leave: a member leaves the account. The owner cannot. */
    public function leave(Request $request): ClientMeResource
    {
        $membership = $request->currentMembership();
        if ($membership->isOwner()) {
            throw new ApiException(403, 'owner_cannot_leave', 'The owner cannot leave the account.');
        }

        $user = $request->user('client');
        $membership->delete();
        $this->activity->log('team.member_left', $request->currentAccount(), ['member_id' => $membership->getKey(), 'email' => $user->email]);

        // Back to an account the person can still use.
        $user->forceFill(['last_account_id' => null])->save();

        return new ClientMeResource($user->refresh());
    }

    private function member(Request $request, int $id): AccountMember
    {
        $member = AccountMember::with('user')->where('account_id', $request->currentAccount()->getKey())->find($id);
        if (! $member) {
            throw new ApiException(404, 'not_found', 'That person is not in this account.');
        }
        if ($member->isOwner()) {
            throw new ApiException(403, 'owner_cannot_be_changed', 'The owner of the account cannot be changed or removed.');
        }

        return $member;
    }

    private function pendingInvitation(Request $request, int $id): AccountInvitation
    {
        $invitation = AccountInvitation::with('account')->where('account_id', $request->currentAccount()->getKey())->pending()->find($id);
        if (! $invitation) {
            throw new ApiException(404, 'not_found', 'That invitation does not exist or is no longer open.');
        }

        return $invitation;
    }
}

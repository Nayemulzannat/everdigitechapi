<?php

use App\Enums\MemberStatus;
use App\Models\AccountInvitation;
use App\Models\AccountMember;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Notifications\ClientTeamInviteNotification;
use App\Services\TeamInvitations;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/** @return array{0: ClientUser, 1: ClientAccount} an owner (default account is theirs) and that account */
function teamOwner(array $attrs = []): array
{
    $owner = clUser($attrs);

    return [$owner, ClientAccount::where('owner_user_id', $owner->id)->sole()];
}

function teamMember(ClientAccount $account, array $permissions = ['invoices.view'], array $attrs = []): ClientUser
{
    $user = ClientUser::factory()->create($attrs);
    $account->members()->create(['user_id' => $user->id, 'role' => 'member', 'permissions' => $permissions, 'status' => 'active', 'joined_at' => now()]);

    return $user;
}

/** The signed-in client, working in `$account`. */
function teamAs(ClientUser $user, ?ClientAccount $account = null)
{
    $request = clSpa()->actingAs($user, 'client');

    return $account ? $request->withHeader('X-Account-Id', (string) $account->id) : $request;
}

/** Invites by email as the owner; returns the plain token from the email link. */
function teamInvite(ClientUser $owner, string $email = 'new.member@uddintraders.com', array $permissions = ['invoices.view', 'hosting.view']): string
{
    Notification::fake();
    teamAs($owner)->postJson('/api/client/team/invitations', ['email' => $email, 'permissions' => $permissions])->assertCreated();

    $url = null;
    Notification::assertSentOnDemand(ClientTeamInviteNotification::class, function ($notification) use (&$url) {
        $url = $notification->url;

        return true;
    });

    return clLinkValues($url)['token'];
}

beforeEach(fn () => Notification::fake());

describe('GET /team', function () {
    it('shows the owner, the members, the open invitations and the permissions to choose from', function () {
        [$owner, $account] = teamOwner(['name' => 'Rahim Uddin']);
        $member = teamMember($account, ['invoices.view'], ['name' => 'Nusrat Jahan']);
        teamInvite($owner, 'later@uddintraders.com');

        $data = teamAs($owner)->getJson('/api/client/team')->assertOk()->json('data');

        expect(collect($data['members'])->pluck('name')->all())->toBe(['Rahim Uddin', 'Nusrat Jahan'])
            ->and($data['members'][0])->toMatchArray(['role' => 'owner', 'permissions' => ['*'], 'is_you' => true])
            ->and($data['members'][1])->toMatchArray(['role' => 'member', 'status' => 'active', 'permissions' => ['invoices.view'], 'is_you' => false, 'email' => $member->email])
            ->and($data['invitations'])->toHaveCount(1)->and($data['invitations'][0])->toMatchArray(['email' => 'later@uddintraders.com', 'invited_by' => 'Rahim Uddin'])
            ->and(array_keys($data['invitations'][0]))->toBe(['id', 'email', 'permissions', 'invited_by', 'created_at', 'expires_at'])
            ->and(collect($data['permissions'])->pluck('key')->all())->toBe(['orders', 'invoices', 'hosting', 'domains', 'tickets'])
            ->and($data['limits'])->toBe(['max_people' => 10, 'invitation_days' => 7]);
    });

    it('does not list used or expired invitations, nor the secret token', function () {
        [$owner, $account] = teamOwner();
        $token = teamInvite($owner, 'used@uddintraders.com');
        AccountInvitation::query()->update(['accepted_at' => now()]);
        teamInvite($owner, 'old@uddintraders.com');
        AccountInvitation::where('email', 'old@uddintraders.com')->update(['expires_at' => now()->subMinute()]);

        $response = teamAs($owner)->getJson('/api/client/team')->assertOk();

        expect($response->json('data.invitations'))->toBe([]);
        expect($response->getContent())->not->toContain($token)->not->toContain('token_hash');
    });

    it('is for the owner only', function () {
        [, $account] = teamOwner();
        $member = teamMember($account, ['invoices.view', 'hosting.manage']);

        teamAs($member, $account)->getJson('/api/client/team')->assertStatus(403)->assertJsonPath('code', 'account_owner_only');
    });

    it('needs a sign-in, and shows only the account that is asked for', function () {
        teamOwner();
        clSpa()->getJson('/api/client/team')->assertStatus(401);

        [$owner] = teamOwner();
        [, $other] = teamOwner();

        teamAs($owner, $other)->getJson('/api/client/team')->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    });
});

describe('POST /team/invitations', function () {
    it('invites by email: a pending invitation, and an email with a link to the client panel', function () {
        [$owner, $account] = teamOwner(['name' => 'Rahim Uddin']);
        $account->forceFill(['name' => 'Uddin Traders'])->save();

        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'New.Member@UddinTraders.com', 'permissions' => ['invoices.view', 'domains.manage']])
            ->assertCreated()->assertJsonPath('email', 'new.member@uddintraders.com')->assertJsonPath('permissions', ['invoices.view', 'domains.manage']);

        $invitation = AccountInvitation::sole();
        expect($invitation->account_id)->toBe($account->id)->and($invitation->invited_by)->toBe($owner->id)
            ->and($invitation->expires_at->isBetween(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue()
            ->and($invitation->accepted_at)->toBeNull();

        Notification::assertSentOnDemand(ClientTeamInviteNotification::class, function ($notification, $channels, $notifiable) use ($invitation) {
            $mail = $notification->toMail($notifiable);
            $token = clLinkValues($notification->url)['token'];

            expect($notifiable->routes['mail'])->toBe('new.member@uddintraders.com')
                ->and($notification->url)->toStartWith('http://localhost:3002/accept-invite?token=')->and(strlen($token))->toBe(64)
                ->and($invitation->fresh()->token_hash)->toBe(hash('sha256', $token))->and($invitation->token_hash)->not->toBe($token)
                ->and($mail->subject)->toBe('Rahim Uddin invited you to Uddin Traders on EverDigitech')
                ->and(implode(' ', $mail->outroLines))->toContain('7 days');

            return true;
        });
        expect(new ClientTeamInviteNotification('u', 'a', 'b'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('works in the account that is asked for, not only the default one', function () {
        [$owner] = teamOwner();
        $second = ClientAccount::factory()->create(['owner_user_id' => $owner->id, 'name' => 'Second shop']);

        teamAs($owner, $second)->postJson('/api/client/team/invitations', ['email' => 'a@uddintraders.com', 'permissions' => ['orders.view']])->assertCreated();

        expect(AccountInvitation::sole()->account_id)->toBe($second->id);
    });

    it('refuses things that are not allowed', function (array $body, string $field) {
        [$owner] = teamOwner(['email' => 'me@uddintraders.com']);

        teamAs($owner)->postJson('/api/client/team/invitations', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(AccountInvitation::count())->toBe(0);
    })->with([
        'no email' => [['permissions' => ['invoices.view']], 'email'],
        'not an email' => [['email' => 'nope', 'permissions' => ['invoices.view']], 'email'],
        'yourself' => [['email' => 'ME@uddintraders.com', 'permissions' => ['invoices.view']], 'email'],
        'a throwaway address' => [['email' => 'x@mailinator.com', 'permissions' => ['invoices.view']], 'email'],
        'no permissions' => [['email' => 'a@uddintraders.com'], 'permissions'],
        'empty permissions' => [['email' => 'a@uddintraders.com', 'permissions' => []], 'permissions'],
        'a permission that does not exist' => [['email' => 'a@uddintraders.com', 'permissions' => ['invoices.view', 'admin.everything']], 'permissions.1'],
    ]);

    it('refuses someone who is already a member', function () {
        [$owner, $account] = teamOwner();
        teamMember($account, ['invoices.view'], ['email' => 'already@uddintraders.com']);

        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'Already@uddintraders.com', 'permissions' => ['orders.view']])
            ->assertStatus(422)->assertJsonPath('code', 'already_member');
    });

    it('a second invitation to the same address replaces the first: only the newest link works', function () {
        [$owner] = teamOwner();
        $first = teamInvite($owner, 'same@uddintraders.com');
        $second = teamInvite($owner, 'same@uddintraders.com', ['orders.view']);

        expect(AccountInvitation::count())->toBe(1)->and(AccountInvitation::sole()->permissions)->toBe(['orders.view']);
        clSpa()->getJson('/api/client/team/invitation?token='.$first)->assertStatus(422);
        clSpa()->getJson('/api/client/team/invitation?token='.$second)->assertOk();
    });

    it('stops at the limit of people, counting open invitations', function () {
        [$owner, $account] = teamOwner();
        foreach (range(1, 8) as $i) {
            teamMember($account);
        }
        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'ninth@uddintraders.com', 'permissions' => ['orders.view']])->assertCreated();

        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'tenth@uddintraders.com', 'permissions' => ['orders.view']])
            ->assertStatus(422)->assertJsonPath('code', 'team_full');
        expect(AccountInvitation::count())->toBe(1);
    });

    it('needs a confirmed email address of the owner', function () {
        [$owner] = teamOwner(['email_verified_at' => null]);

        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'a@uddintraders.com', 'permissions' => ['orders.view']])
            ->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
    });

    it('is not for members', function () {
        [, $account] = teamOwner();
        $member = teamMember($account, ['invoices.view', 'hosting.manage']);

        teamAs($member, $account)->postJson('/api/client/team/invitations', ['email' => 'a@uddintraders.com', 'permissions' => ['orders.view']])
            ->assertStatus(403)->assertJsonPath('code', 'account_owner_only');
    });

    it('is not allowed while staff are signed in as the client', function () {
        [$owner] = teamOwner();
        test()->withSession(['impersonated_by_admin_id' => Admin::factory()->create()->id, 'impersonation_started_at' => now()->getTimestamp()]);

        teamAs($owner)->postJson('/api/client/team/invitations', ['email' => 'a@uddintraders.com', 'permissions' => ['orders.view']])
            ->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');

        expect(AccountInvitation::count())->toBe(0);
    });
});

describe('resend and cancel', function () {
    it('resend sends a new link and the old one stops working', function () {
        [$owner] = teamOwner();
        $old = teamInvite($owner);
        $id = AccountInvitation::sole()->id;
        Notification::fake();

        teamAs($owner)->postJson("/api/client/team/invitations/{$id}/resend")->assertOk();

        $new = null;
        Notification::assertSentOnDemand(ClientTeamInviteNotification::class, function ($n) use (&$new) {
            $new = clLinkValues($n->url)['token'];

            return true;
        });
        expect($new)->not->toBe($old);
        clSpa()->getJson('/api/client/team/invitation?token='.$old)->assertStatus(422);
        clSpa()->getJson('/api/client/team/invitation?token='.$new)->assertOk();
    });

    it('cancel removes the invitation and its link stops working', function () {
        [$owner] = teamOwner();
        $token = teamInvite($owner);

        teamAs($owner)->deleteJson('/api/client/team/invitations/'.AccountInvitation::sole()->id)->assertOk();

        expect(AccountInvitation::count())->toBe(0);
        clSpa()->getJson('/api/client/team/invitation?token='.$token)->assertStatus(422);
    });

    it('cannot touch the invitation of another account, or one that is already used', function () {
        [$owner] = teamOwner();
        [$otherOwner] = teamOwner();
        teamInvite($otherOwner, 'theirs@uddintraders.com');
        $theirs = AccountInvitation::sole();

        teamAs($owner)->deleteJson("/api/client/team/invitations/{$theirs->id}")->assertStatus(404);
        teamAs($owner)->postJson("/api/client/team/invitations/{$theirs->id}/resend")->assertStatus(404);

        $theirs->update(['accepted_at' => now()]);
        teamAs($otherOwner)->deleteJson("/api/client/team/invitations/{$theirs->id}")->assertStatus(404);
    });
});

describe('GET /team/invitation (the invited person looks at it)', function () {
    it('shows the account and who invited, with the address masked, and needs no sign-in', function () {
        [$owner, $account] = teamOwner(['name' => 'Rahim Uddin']);
        $account->forceFill(['name' => 'Uddin Traders'])->save();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');

        clSpa()->getJson('/api/client/team/invitation?token='.$token)->assertOk()
            ->assertJsonPath('data.account_name', 'Uddin Traders')->assertJsonPath('data.invited_by', 'Rahim Uddin')
            ->assertJsonPath('data.email_hint', 'nu***@uddintraders.com')->assertJsonMissingPath('data.email');
    });

    it('refuses an unknown, malformed, used or expired token', function () {
        [$owner] = teamOwner();
        $token = teamInvite($owner);

        clSpa()->getJson('/api/client/team/invitation?token='.str_repeat('a', 64))->assertStatus(422)->assertJsonPath('code', 'invalid_invitation');
        clSpa()->getJson('/api/client/team/invitation?token=short')->assertStatus(422);
        clSpa()->getJson('/api/client/team/invitation')->assertStatus(422);

        AccountInvitation::query()->update(['expires_at' => now()->subSecond()]);
        clSpa()->getJson('/api/client/team/invitation?token='.$token)->assertStatus(422);
    });
});

describe('POST /team/invitations/accept', function () {
    it('makes the signed-in person a member with the chosen permissions, and opens the panel on that account', function () {
        [$owner, $account] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com', ['invoices.view', 'hosting.view']);
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com']);

        $me = teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertOk();

        $member = AccountMember::where('account_id', $account->id)->where('user_id', $nusrat->id)->sole();
        expect($member->role->value)->toBe('member')->and($member->status)->toBe(MemberStatus::Active)->and($member->permissions)->toBe(['invoices.view', 'hosting.view'])
            ->and($member->invited_by)->toBe($owner->id)->and($member->joined_at)->not->toBeNull()
            ->and($nusrat->fresh()->last_account_id)->toBe($account->id)->and(AccountInvitation::sole()->accepted_at)->not->toBeNull();
        $joined = collect($me->json('data.accounts'))->firstWhere('id', (string) $account->id);
        expect($joined)->toMatchArray(['role' => 'member', 'is_owner' => false, 'permissions' => ['invoices.view', 'hosting.view']])
            ->and($me->json('data.current_account_id'))->toBe((string) $account->id);
    });

    it('lets the member work in the account, with only what was allowed', function () {
        [$owner, $account] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com', ['invoices.view']);
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com']);
        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertOk();

        teamAs($nusrat)->putJson('/api/client/current-account', ['account_id' => $account->id])->assertOk();
        teamAs($nusrat, $account)->getJson('/api/client/team')->assertStatus(403)->assertJsonPath('code', 'account_owner_only');
    });

    it('works once only', function () {
        [$owner] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com']);
        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertOk();

        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertStatus(422)->assertJsonPath('code', 'invalid_invitation');

        expect(AccountMember::count())->toBe(3); // owner, nusrat, and the owner row of nusrat's own account
    });

    it('is for the invited email address only', function () {
        [$owner, $account] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');
        $someoneElse = clUser(['email' => 'someone.else@uddintraders.com']);

        teamAs($someoneElse)->postJson('/api/client/team/invitations/accept', ['token' => $token])
            ->assertStatus(403)->assertJsonPath('code', 'invitation_email_mismatch')->assertJsonPath('message', fn ($m) => str_contains($m, 'nu***@uddintraders.com') && ! str_contains($m, 'nusrat@'));

        expect(AccountMember::where('account_id', $account->id)->where('user_id', $someoneElse->id)->exists())->toBeFalse()->and(AccountInvitation::sole()->accepted_at)->toBeNull();
    });

    it('needs the email address of the person to be confirmed', function () {
        [$owner] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com', 'email_verified_at' => null]);

        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertStatus(403)->assertJsonPath('code', 'email_not_verified');

        expect(AccountInvitation::sole()->accepted_at)->toBeNull();
    });

    it('needs a sign-in', function () {
        clSpa()->postJson('/api/client/team/invitations/accept', ['token' => str_repeat('a', 64)])->assertStatus(401);
    });

    it('refuses an expired or unknown token and a missing token', function () {
        [$owner] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com']);

        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', [])->assertStatus(422)->assertJsonValidationErrors('token');
        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => str_repeat('b', 64)])->assertStatus(422)->assertJsonPath('code', 'invalid_invitation');

        AccountInvitation::query()->update(['expires_at' => now()->subSecond()]);
        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertStatus(422)->assertJsonPath('code', 'invalid_invitation');
    });

    it('brings back a member who had been suspended, with the new permissions', function () {
        [$owner, $account] = teamOwner();
        $nusrat = teamMember($account, ['invoices.view'], ['email' => 'nusrat@uddintraders.com']);
        AccountMember::where('user_id', $nusrat->id)->update(['status' => 'suspended']);
        // The suspended member is still a "member", so the owner cannot invite them; the invitation exists from before.
        AccountInvitation::create([
            'account_id' => $account->id, 'email' => 'nusrat@uddintraders.com', 'permissions' => ['orders.view'], 'invited_by' => $owner->id,
            'token_hash' => hash('sha256', str_repeat('c', 64)), 'expires_at' => now()->addDay(),
        ]);

        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => str_repeat('c', 64)])->assertOk();

        $member = AccountMember::where('user_id', $nusrat->id)->sole();
        expect($member->status)->toBe(MemberStatus::Active)->and($member->permissions)->toBe(['orders.view']);
    });

    it('writes the invitation and the acceptance to the activity log', function () {
        [$owner, $account] = teamOwner();
        $token = teamInvite($owner, 'nusrat@uddintraders.com');
        $nusrat = clUser(['email' => 'nusrat@uddintraders.com']);

        teamAs($nusrat)->postJson('/api/client/team/invitations/accept', ['token' => $token])->assertOk();

        $actions = ActivityLog::pluck('action')->all();
        expect($actions)->toContain('team.invitation_sent')->toContain('team.invitation_accepted');
        expect(ActivityLog::where('action', 'team.invitation_accepted')->sole()->actor_id)->toBe($nusrat->id);
    });
});

describe('PUT /team/members/{id}', function () {
    it('changes what a member may do', function () {
        [$owner, $account] = teamOwner();
        $member = teamMember($account, ['invoices.view']);
        $row = AccountMember::where('user_id', $member->id)->sole();

        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['permissions' => ['orders.view', 'domains.manage']])
            ->assertOk()->assertJsonPath('permissions', ['orders.view', 'domains.manage'])->assertJsonPath('email', $member->email);

        expect($row->fresh()->permissions)->toBe(['orders.view', 'domains.manage']);
    });

    it('suspends a member, who loses the account at once, and brings them back', function () {
        [$owner, $account] = teamOwner();
        $member = teamMember($account);
        $row = AccountMember::where('user_id', $member->id)->sole();

        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('status', 'suspended');

        teamAs($member, $account)->getJson('/api/client/me')->assertOk();
        expect(collect(teamAs($member)->getJson('/api/client/me')->json('data.accounts'))->pluck('id')->all())->not->toContain((string) $account->id);
        teamAs($member)->putJson('/api/client/current-account', ['account_id' => $account->id])->assertStatus(403);

        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['status' => 'active'])->assertOk();
        teamAs($member)->putJson('/api/client/current-account', ['account_id' => $account->id])->assertOk();
    });

    it('cannot change the owner, a person of another account, or use bad values', function () {
        [$owner, $account] = teamOwner();
        $ownerRow = AccountMember::where('user_id', $owner->id)->where('account_id', $account->id)->sole();
        [, $otherAccount] = teamOwner();
        $stranger = teamMember($otherAccount);
        $strangerRow = AccountMember::where('user_id', $stranger->id)->sole();
        $member = teamMember($account);
        $row = AccountMember::where('user_id', $member->id)->sole();

        teamAs($owner)->putJson("/api/client/team/members/{$ownerRow->id}", ['permissions' => ['orders.view']])->assertStatus(403)->assertJsonPath('code', 'owner_cannot_be_changed');
        teamAs($owner)->putJson("/api/client/team/members/{$strangerRow->id}", ['permissions' => ['orders.view']])->assertStatus(404);
        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['permissions' => []])->assertStatus(422)->assertJsonValidationErrors('permissions');
        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['permissions' => ['nope.nope']])->assertStatus(422);
        teamAs($owner)->putJson("/api/client/team/members/{$row->id}", ['status' => 'invited'])->assertStatus(422)->assertJsonValidationErrors('status');
        expect($strangerRow->fresh()->permissions)->toBe(['invoices.view']);
    });

    it('is not for members', function () {
        [, $account] = teamOwner();
        $a = teamMember($account);
        $b = teamMember($account);

        teamAs($a, $account)->putJson('/api/client/team/members/'.AccountMember::where('user_id', $b->id)->value('id'), ['status' => 'suspended'])->assertStatus(403);
    });
});

describe('DELETE /team/members/{id}', function () {
    it('removes a member, who loses access at once', function () {
        [$owner, $account] = teamOwner();
        $member = teamMember($account, ['invoices.view'], ['name' => 'Nusrat Jahan']);
        $row = AccountMember::where('user_id', $member->id)->sole();
        ClientUser::whereKey($member->id)->update(['last_account_id' => $account->id]);

        teamAs($owner)->deleteJson("/api/client/team/members/{$row->id}")->assertOk()->assertJsonPath('message', 'Nusrat Jahan was removed from the account.');

        expect(AccountMember::whereKey($row->id)->exists())->toBeFalse()->and(ClientUser::whereKey($member->id)->exists())->toBeTrue();
        teamAs($member, $account)->getJson('/api/client/me')->assertOk()->assertJsonPath('data.accounts', []);
        teamAs($member, $account)->postJson('/api/client/team/leave')->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
        expect(ActivityLog::where('action', 'team.member_removed')->count())->toBe(1);
    });

    it('cannot remove the owner or a person of another account', function () {
        [$owner, $account] = teamOwner();
        $ownerRow = AccountMember::where('user_id', $owner->id)->where('account_id', $account->id)->sole();
        [, $other] = teamOwner();
        $stranger = AccountMember::where('account_id', $other->id)->sole();

        teamAs($owner)->deleteJson("/api/client/team/members/{$ownerRow->id}")->assertStatus(403)->assertJsonPath('code', 'owner_cannot_be_changed');
        teamAs($owner)->deleteJson("/api/client/team/members/{$stranger->id}")->assertStatus(404);
        expect(AccountMember::count())->toBe(2);
    });

    it('is not allowed while staff are signed in as the client', function () {
        [$owner, $account] = teamOwner();
        $row = AccountMember::where('user_id', teamMember($account)->id)->sole();
        test()->withSession(['impersonated_by_admin_id' => Admin::factory()->create()->id, 'impersonation_started_at' => now()->getTimestamp()]);

        teamAs($owner)->deleteJson("/api/client/team/members/{$row->id}")->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');

        expect(AccountMember::whereKey($row->id)->exists())->toBeTrue();
    });
});

describe('POST /team/leave', function () {
    it('lets a member leave the account', function () {
        [, $account] = teamOwner();
        $member = teamMember($account);
        ClientUser::whereKey($member->id)->update(['last_account_id' => $account->id]);

        $me = teamAs($member, $account)->postJson('/api/client/team/leave')->assertOk();

        expect(AccountMember::where('user_id', $member->id)->exists())->toBeFalse()->and($member->fresh()->last_account_id)->toBeNull()
            ->and($me->json('data.accounts'))->toBe([]);
    });

    it('does not let the owner leave', function () {
        [$owner, $account] = teamOwner();

        teamAs($owner, $account)->postJson('/api/client/team/leave')->assertStatus(403)->assertJsonPath('code', 'owner_cannot_leave');

        expect(AccountMember::where('account_id', $account->id)->count())->toBe(1);
    });

    it('needs a sign-in', function () {
        clSpa()->postJson('/api/client/team/leave')->assertStatus(401);
    });
});

it('masks an address for the invitation page', function (string $email, string $masked) {
    expect(TeamInvitations::mask($email))->toBe($masked);
})->with([['nusrat@uddintraders.com', 'nu***@uddintraders.com'], ['a@b.co', 'a***@b.co']]);

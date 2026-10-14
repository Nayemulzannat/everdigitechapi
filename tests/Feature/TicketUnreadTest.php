<?php

use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Models\Ticket;
use App\Models\TicketClientRead;
use App\Models\TicketDepartment;
use App\Services\Tickets;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->department = TicketDepartment::create(['name' => 'Technical Support', 'sort_order' => 1]);
});

/** @return array{0: ClientUser, 1: ClientAccount} */
function unreadClient(): array
{
    $user = clUser();

    return [$user, ClientAccount::where('owner_user_id', $user->id)->sole()];
}

function unreadAs(ClientUser $user, ?ClientAccount $account = null)
{
    $request = clSpa()->actingAs($user, 'client');

    return $account ? $request->withHeader('X-Account-Id', (string) $account->id) : $request;
}

function unreadTicket(ClientUser $user, ClientAccount $account, string $subject = 'Help'): Ticket
{
    $ticket = Ticket::create(['account_id' => $account->id, 'opened_by' => $user->id, 'department_id' => test()->department->id, 'subject' => $subject, 'last_reply_at' => now()]);
    $ticket->replies()->create(['author_type' => 'client', 'client_user_id' => $user->id, 'body' => 'First message.']);

    return $ticket;
}

function staffAnswers(Ticket $ticket, string $text = 'We are on it.', bool $internal = false, string $name = 'Nusrat Jahan')
{
    return app(Tickets::class)->staffReply($ticket, Admin::factory()->create(['name' => $name]), $text, $internal);
}

function unreadList(ClientUser $user, ?ClientAccount $account = null): array
{
    return collect(unreadAs($user, $account)->getJson('/api/client/tickets')->assertOk()->json('data'))->pluck('unread', 'subject')->all();
}

describe('"new reply" on the ticket list', function () {
    it('marks a ticket when support answered and the client has not opened it since', function () {
        [$user, $account] = unreadClient();
        $answered = unreadTicket($user, $account, 'Answered');
        unreadTicket($user, $account, 'Quiet');
        staffAnswers($answered);

        $response = unreadAs($user)->getJson('/api/client/tickets')->assertOk();

        expect(unreadList($user))->toBe(['Quiet' => false, 'Answered' => true])
            ->and($response->json('meta.counts.unread'))->toBe(1);
    });

    it('stops being new when the client opens the ticket', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);
        staffAnswers($ticket);

        unreadAs($user)->getJson("/api/client/tickets/{$ticket->id}")->assertOk();

        expect(unreadList($user))->toBe(['Help' => false]);
        expect(unreadAs($user)->getJson('/api/client/tickets')->json('meta.counts.unread'))->toBe(0);
        expect(TicketClientRead::sole()->read_reply_id)->toBe((int) $ticket->replies()->max('id'));
    });

    it('is new again when support answers a second time', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);
        staffAnswers($ticket, 'First answer.');
        unreadAs($user)->getJson("/api/client/tickets/{$ticket->id}")->assertOk();

        staffAnswers($ticket, 'Second answer.');

        expect(unreadList($user))->toBe(['Help' => true]);
    });

    it('does not count notes between staff, or what the client wrote themselves', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);

        staffAnswers($ticket, 'A note.', internal: true);
        unreadAs($user)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'More details.'])->assertOk();

        expect(unreadList($user))->toBe(['Help' => false]);
    });

    it('is separate for each person of the account', function () {
        [$owner, $account] = unreadClient();
        $helper = ClientUser::factory()->create();
        $account->members()->create(['user_id' => $helper->id, 'role' => 'member', 'permissions' => ['tickets.view', 'tickets.create'], 'status' => 'active', 'joined_at' => now()]);
        $ticket = unreadTicket($owner, $account);
        staffAnswers($ticket);

        unreadAs($owner)->getJson("/api/client/tickets/{$ticket->id}")->assertOk();

        expect(unreadList($owner))->toBe(['Help' => false])->and(unreadList($helper, $account))->toBe(['Help' => true]);
    });

    it('does not mark the ticket as read while staff are signed in as the client', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);
        staffAnswers($ticket);
        test()->withSession(['impersonated_by_admin_id' => Admin::factory()->create()->id, 'impersonation_started_at' => now()->getTimestamp()]);

        unreadAs($user)->getJson("/api/client/tickets/{$ticket->id}")->assertOk();

        expect(TicketClientRead::count())->toBe(0);
    });

    it('does not mark it as read for a request that fails', function () {
        [$user, $account] = unreadClient();
        [$other, $otherAccount] = unreadClient();
        $theirs = unreadTicket($other, $otherAccount);
        staffAnswers($theirs);

        unreadAs($user)->getJson("/api/client/tickets/{$theirs->id}")->assertStatus(404);

        expect(TicketClientRead::count())->toBe(0);
    });
});

describe('GET /api/client/tickets/feed', function () {
    it('gives the starting cursor and no events when it is first asked', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);
        $reply = staffAnswers($ticket);

        $data = unreadAs($user)->getJson('/api/client/tickets/feed')->assertOk()->json('data');

        expect($data)->toBe(['cursor' => $reply->id, 'unread' => 1, 'events' => []]);
    });

    it('reports the support replies since the cursor, with the first name of the staff member', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account, 'Website down');
        $cursor = unreadAs($user)->getJson('/api/client/tickets/feed')->json('data.cursor');

        $reply = staffAnswers($ticket, 'Please clear the cache.', name: 'Nusrat Jahan');

        $data = unreadAs($user)->getJson("/api/client/tickets/feed?after={$cursor}")->assertOk()->json('data');

        expect($data['events'])->toHaveCount(1)->and($data['events'][0])->toMatchArray([
            'replyId' => (string) $reply->id, 'ticketId' => (string) $ticket->id, 'number' => $ticket->number, 'subject' => 'Website down', 'staffName' => 'Nusrat',
        ])->and(array_keys($data['events'][0]))->toBe(['replyId', 'ticketId', 'number', 'subject', 'staffName', 'createdAt'])
            ->and($data['cursor'])->toBe($reply->id)->and($data['unread'])->toBe(1);
        expect(json_encode($data))->not->toContain('Jahan')->not->toContain('clear the cache'); // no full name, and no message text
        expect(unreadAs($user)->getJson("/api/client/tickets/feed?after={$data['cursor']}")->json('data.events'))->toBe([]);
    });

    it('leaves out notes between staff and the replies of other accounts', function () {
        [$user, $account] = unreadClient();
        [$other, $otherAccount] = unreadClient();
        $mine = unreadTicket($user, $account);
        $theirs = unreadTicket($other, $otherAccount);
        $cursor = unreadAs($user)->getJson('/api/client/tickets/feed')->json('data.cursor');

        staffAnswers($mine, 'A note.', internal: true);
        staffAnswers($theirs, 'For somebody else.');

        $data = unreadAs($user)->getJson("/api/client/tickets/feed?after={$cursor}")->json('data');

        expect($data['events'])->toBe([])->and($data['unread'])->toBe(0);
    });

    it('shows 20 at a time and continues from the last one', function () {
        [$user, $account] = unreadClient();
        $ticket = unreadTicket($user, $account);
        foreach (range(1, 25) as $i) {
            staffAnswers($ticket, "Answer {$i}");
        }

        $page1 = unreadAs($user)->getJson('/api/client/tickets/feed?after=0')->json('data');
        $page2 = unreadAs($user)->getJson("/api/client/tickets/feed?after={$page1['cursor']}")->json('data');

        expect($page1['events'])->toHaveCount(20)->and($page2['events'])->toHaveCount(5)
            ->and(collect([...$page1['events'], ...$page2['events']])->pluck('replyId')->unique()->count())->toBe(25);
    });

    it('needs a sign-in and tickets.view, and refuses a bad cursor', function () {
        [$user, $account] = unreadClient();
        $noAccess = ClientUser::factory()->create();
        $account->members()->create(['user_id' => $noAccess->id, 'role' => 'member', 'permissions' => ['invoices.view'], 'status' => 'active', 'joined_at' => now()]);

        unreadAs($noAccess, $account)->getJson('/api/client/tickets/feed')->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
        unreadAs($user)->getJson('/api/client/tickets/feed?after=abc')->assertStatus(422)->assertJsonValidationErrors('after');
        unreadAs($user)->getJson('/api/client/tickets/feed?after=-1')->assertStatus(422);
    });

    it('needs a sign-in', function () {
        test()->getJson('/api/client/tickets/feed')->assertStatus(401);
    });
});

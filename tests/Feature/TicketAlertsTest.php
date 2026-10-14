<?php

use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Notifications\ClientTicketReplyNotification;
use App\Notifications\StaffTicketAlertNotification;
use App\Services\Tickets;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed(RoleSeeder::class);
    $this->department = TicketDepartment::create(['name' => 'Technical Support', 'sort_order' => 1]);
});

function alertClient(array $attrs = []): array
{
    $user = clUser($attrs);

    return [$user, ClientAccount::where('owner_user_id', $user->id)->sole()];
}

function alertOpen(ClientUser $user, array $over = []): Ticket
{
    $response = clSpa()->actingAs($user, 'client')->postJson('/api/client/tickets', [
        'subject' => 'Website down', 'department_id' => test()->department->id, 'priority' => 'high', 'message' => "Blank page.\nPlease help.", ...$over,
    ])->assertCreated();

    return Ticket::findOrFail($response->json('id'));
}

describe('the support mailbox', function () {
    it('gets an email when a client opens a ticket', function () {
        [$client, $account] = alertClient(['name' => 'Rahim Uddin']);
        $account->forceFill(['name' => 'Uddin Traders'])->save();

        $ticket = alertOpen($client);

        Notification::assertSentOnDemand(StaffTicketAlertNotification::class, function ($notification, $channels, $notifiable) use ($ticket) {
            $mail = $notification->toMail($notifiable);
            $text = implode(' ', array_merge($mail->introLines, $mail->outroLines));

            expect($notifiable->routes['mail'])->toBe(['support@everdigitech.com' => 'EverDigitech Support'])
                ->and($notification->kind)->toBe('new_ticket')
                ->and($mail->subject)->toBe("[{$ticket->number}] New ticket: Website down")
                ->and($mail->actionUrl)->toBe('http://localhost:3003/tickets/'.$ticket->id)
                ->and($text)->toContain('Rahim Uddin')->toContain('Uddin Traders')->toContain('Technical Support')->toContain('High')->toContain('Blank page.')->toContain('Please help.');

            return true;
        });
        expect(new StaffTicketAlertNotification('new_ticket', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('gets an email when a client writes in a ticket, and it says so', function () {
        [$client] = alertClient();
        $ticket = alertOpen($client);
        Notification::fake();

        clSpa()->actingAs($client, 'client')->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'Still not working.'])->assertOk();

        Notification::assertSentOnDemandTimes(StaffTicketAlertNotification::class, 1);
        Notification::assertSentOnDemand(StaffTicketAlertNotification::class, function ($notification, $channels, $notifiable) use ($ticket) {
            expect($notification->kind)->toBe('client_reply')->and($notification->toMail($notifiable)->subject)->toBe("[{$ticket->number}] Client replied: Website down");

            return true;
        });
    });

    it('is not emailed about what staff do', function () {
        [$client] = alertClient();
        $ticket = alertOpen($client);
        Notification::fake();
        $staff = Admin::factory()->create();

        app(Tickets::class)->staffReply($ticket, $staff, 'We are looking into it.');
        app(Tickets::class)->staffReply($ticket, $staff, 'A note.', internal: true);

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertSentOnDemandTimes(StaffTicketAlertNotification::class, 0);
    });

    it('uses the address from SUPPORT_EMAIL', function () {
        config(['everdigitech.support.email' => 'help@example.org', 'everdigitech.support.name' => 'Help Desk']);
        [$client] = alertClient();

        alertOpen($client);

        Notification::assertSentOnDemand(StaffTicketAlertNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === ['help@example.org' => 'Help Desk']);
    });

    it('does not break opening a ticket if the mail cannot be queued', function () {
        // The alert is a queued notification: a failure in the queue worker never reaches the client's request.
        [$client] = alertClient();

        expect(alertOpen($client)->exists)->toBeTrue();
    });
});

describe('the email a client gets when support replies', function () {
    it('comes from the support mailbox, and a reply goes to it', function () {
        [$client] = alertClient(['name' => 'Rahim Uddin']);
        $ticket = alertOpen($client);

        $mail = (new ClientTicketReplyNotification($ticket->number, 'Website down', 'Please clear your cache.', 'http://localhost:3002/dashboard/support/1', 'Nusrat Jahan'))->toMail($client);

        expect($mail->from)->toBe(['support@everdigitech.com', 'EverDigitech Support'])->and($mail->replyTo)->toBe([['support@everdigitech.com', 'EverDigitech Support']])
            ->and($mail->subject)->toBe("[{$ticket->number}] Website down");
    });

    it('is sent with the login of the support mailbox when one is set, otherwise with the normal mailer', function () {
        [$client] = alertClient();
        $make = fn () => (new ClientTicketReplyNotification('TKT-000001', 'Website down', 'Hi', 'http://localhost:3002/x', 'Nusrat'))->toMail($client);

        config(['mail.mailers.support.username' => null]);
        expect($make()->mailer)->toBeNull();

        config(['mail.mailers.support.username' => 'support@everdigitech.com']);
        expect($make()->mailer)->toBe('support');
    });
});

describe('GET /api/admin/tickets/feed', function () {
    function feedStaff(): Admin
    {
        return staffWith(['tickets.view']);
    }

    it('gives the starting cursor and no events when it is first asked', function () {
        [$client] = alertClient();
        $ticket = alertOpen($client);

        $data = api(feedStaff())->getJson('/api/admin/tickets/feed')->assertOk()->json('data');

        expect($data['events'])->toBe([])->and($data['cursor'])->toBe((int) $ticket->replies()->max('id'))->and($data['waiting'])->toBe(1);
    });

    it('gives a cursor of 0 when there are no tickets', function () {
        expect(api(feedStaff())->getJson('/api/admin/tickets/feed')->json('data'))->toBe(['cursor' => 0, 'waiting' => 0, 'events' => []]);
    });

    it('reports new tickets and client replies since the cursor, oldest first', function () {
        [$alice, $aliceAccount] = alertClient(['name' => 'Alice Rahman']);
        $aliceAccount->forceFill(['name' => 'Alice Shop'])->save();
        [$bob] = alertClient(['name' => 'Bob Karim']);
        $staff = feedStaff();
        $first = alertOpen($alice, ['subject' => 'Before']);
        $cursor = api($staff)->getJson('/api/admin/tickets/feed')->json('data.cursor');

        $new = alertOpen($bob, ['subject' => 'Email problem', 'priority' => 'low']);
        clSpa()->actingAs($alice, 'client')->postJson("/api/client/tickets/{$first->id}/replies", ['message' => 'Any news?'])->assertOk();

        $data = api($staff)->getJson("/api/admin/tickets/feed?after={$cursor}")->assertOk()->json('data');

        expect(collect($data['events'])->pluck('kind')->all())->toBe(['new_ticket', 'client_reply'])
            ->and(collect($data['events'])->pluck('number')->all())->toBe([$new->number, $first->number])
            ->and($data['events'][0])->toMatchArray(['subject' => 'Email problem', 'priority' => 'low', 'authorName' => 'Bob Karim', 'ticketId' => (string) $new->id])
            ->and($data['events'][1])->toMatchArray(['accountName' => 'Alice Shop', 'authorName' => 'Alice Rahman'])
            ->and(array_keys($data['events'][0]))->toBe(['replyId', 'ticketId', 'number', 'subject', 'kind', 'priority', 'accountName', 'authorName', 'createdAt'])
            ->and($data['cursor'])->toBe((int) TicketReply::max('id'))->and($data['waiting'])->toBe(2);

        // Asking again from the new cursor gives nothing new.
        expect(api($staff)->getJson("/api/admin/tickets/feed?after={$data['cursor']}")->json('data.events'))->toBe([]);
    });

    it('leaves out what staff write, and notes between staff', function () {
        [$client] = alertClient();
        $ticket = alertOpen($client);
        $staff = feedStaff();
        $cursor = api($staff)->getJson('/api/admin/tickets/feed')->json('data.cursor');
        $other = Admin::factory()->create();

        app(Tickets::class)->staffReply($ticket, $other, 'Reply.');
        app(Tickets::class)->staffReply($ticket, $other, 'Note.', internal: true);

        $data = api($staff)->getJson("/api/admin/tickets/feed?after={$cursor}")->json('data');

        expect($data['events'])->toBe([])->and($data['cursor'])->toBe($cursor)->and($data['waiting'])->toBe(0); // answered, nobody waiting
    });

    it('shows 20 at a time and continues from the last one, so none is missed', function () {
        [$client, $account] = alertClient();
        $staff = feedStaff();
        foreach (range(1, 25) as $i) {
            $t = Ticket::create(['account_id' => $account->id, 'opened_by' => $client->id, 'department_id' => $this->department->id, 'subject' => "T{$i}", 'last_reply_at' => now()]);
            $t->replies()->create(['author_type' => 'client', 'client_user_id' => $client->id, 'body' => "m{$i}"]);
        }

        $page1 = api($staff)->getJson('/api/admin/tickets/feed?after=0')->json('data');
        $page2 = api($staff)->getJson("/api/admin/tickets/feed?after={$page1['cursor']}")->json('data');

        expect($page1['events'])->toHaveCount(20)->and($page2['events'])->toHaveCount(5)
            ->and(collect([...$page1['events'], ...$page2['events']])->pluck('subject')->unique()->count())->toBe(25)
            ->and($page2['cursor'])->toBe((int) TicketReply::max('id'));
    });

    it('does not count closed or answered tickets as waiting', function () {
        [$client, $account] = alertClient();
        foreach (['open', 'answered', 'closed', 'open'] as $status) {
            Ticket::create(['account_id' => $account->id, 'opened_by' => $client->id, 'department_id' => $this->department->id, 'subject' => $status, 'status' => $status, 'last_reply_at' => now()]);
        }

        expect(api(feedStaff())->getJson('/api/admin/tickets/feed')->json('data.waiting'))->toBe(2);
    });

    it('refuses a cursor that is not a number', function () {
        api(feedStaff())->getJson('/api/admin/tickets/feed?after=abc')->assertStatus(422)->assertJsonValidationErrors('after');
        api(feedStaff())->getJson('/api/admin/tickets/feed?after=-1')->assertStatus(422);
    });

    it('needs a sign-in and tickets.view', function () {
        test()->getJson('/api/admin/tickets/feed')->assertStatus(401);
        api(staffWith(['clients.view']))->getJson('/api/admin/tickets/feed')->assertStatus(403);
    });
});

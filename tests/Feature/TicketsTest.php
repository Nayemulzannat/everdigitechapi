<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Notifications\ClientTicketReplyNotification;
use App\Notifications\StaffTicketAlertNotification;
use App\Services\Tickets;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    $this->seed(RoleSeeder::class);
    $this->department = TicketDepartment::create(['name' => 'Technical Support', 'sort_order' => 1]);
});

/** A signed-in client working in its own account. @return array{0: ClientUser, 1: ClientAccount} */
function tkOwner(array $attrs = []): array
{
    $owner = clUser($attrs);

    return [$owner, ClientAccount::where('owner_user_id', $owner->id)->sole()];
}

function tkAs(ClientUser $user, ?ClientAccount $account = null)
{
    $request = clSpa()->actingAs($user, 'client');

    return $account ? $request->withHeader('X-Account-Id', (string) $account->id) : $request;
}

function tkMember(ClientAccount $account, array $permissions): ClientUser
{
    $user = ClientUser::factory()->create();
    $account->members()->create(['user_id' => $user->id, 'role' => 'member', 'permissions' => $permissions, 'status' => 'active', 'joined_at' => now()]);

    return $user;
}

/** Opens a ticket as the client; returns it. */
function tkOpen(ClientUser $user, array $over = [], ?ClientAccount $account = null): Ticket
{
    $response = tkAs($user, $account)->postJson('/api/client/tickets', [
        'subject' => 'My website is down', 'department_id' => test()->department->id, 'message' => 'It shows a blank page since morning.', ...$over,
    ])->assertCreated();

    return Ticket::findOrFail($response->json('id'));
}

function staffTk(array $permissions = ['tickets.view', 'tickets.edit', 'tickets.assign']): Admin
{
    return staffWith($permissions);
}

describe('POST /api/client/tickets', function () {
    it('opens a ticket: the subject and the first message, waiting for support', function () {
        [$owner, $account] = tkOwner(['name' => 'Rahim Uddin']);

        $response = tkAs($owner)->postJson('/api/client/tickets', [
            'subject' => '  My website is down ', 'department_id' => $this->department->id, 'priority' => 'high', 'message' => "It shows a blank page.\nPlease help.",
        ])->assertCreated();

        $ticket = Ticket::sole();
        expect($ticket->account_id)->toBe($account->id)->and($ticket->opened_by)->toBe($owner->id)->and($ticket->subject)->toBe('My website is down')
            ->and($ticket->status->value)->toBe('open')->and($ticket->priority->value)->toBe('high')->and($ticket->last_reply_by)->toBe('client')
            ->and($ticket->number)->toBe('TKT-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT))
            ->and($ticket->replies)->toHaveCount(1)->and($ticket->replies[0]->body)->toBe("It shows a blank page.\nPlease help.")->and($ticket->replies[0]->author_type)->toBe('client');
        expect($response->json())->toMatchArray(['number' => $ticket->number, 'subject' => 'My website is down', 'status' => 'open', 'priority' => 'high', 'opened_by' => 'Rahim Uddin'])
            ->and($response->json('department'))->toBe(['id' => (string) $this->department->id, 'name' => 'Technical Support'])
            ->and($response->json('replies'))->toHaveCount(1)->and($response->json('replies.0'))->toMatchArray(['author_type' => 'client', 'author_name' => 'Rahim Uddin']);
    });

    it('uses medium priority when none is given', function () {
        [$owner] = tkOwner();

        expect(tkOpen($owner)->priority->value)->toBe('medium');
    });

    it('refuses bad input', function (array $over, string $field) {
        [$owner] = tkOwner();
        $body = ['subject' => 'Help', 'department_id' => $this->department->id, 'message' => 'Hello', ...$over];

        tkAs($owner)->postJson('/api/client/tickets', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(Ticket::count())->toBe(0);
    })->with([
        'no subject' => [['subject' => ''], 'subject'],
        'subject too long' => [['subject' => str_repeat('a', 151)], 'subject'],
        'no message' => [['message' => '   '], 'message'],
        'message too long' => [['message' => str_repeat('a', 10001)], 'message'],
        'no department' => [['department_id' => null], 'department_id'],
        'unknown department' => [['department_id' => 99999], 'department_id'],
        'bad priority' => [['priority' => 'urgent'], 'priority'],
    ]);

    it('refuses a department that is switched off', function () {
        [$owner] = tkOwner();
        $off = TicketDepartment::create(['name' => 'Old', 'is_active' => false]);

        tkAs($owner)->postJson('/api/client/tickets', ['subject' => 'x', 'department_id' => $off->id, 'message' => 'y'])->assertStatus(422)->assertJsonValidationErrors('department_id');
    });

    it('needs a sign-in, a confirmed email, and the tickets.create permission', function () {
        [$owner, $account] = tkOwner(['email_verified_at' => null]);
        $body = ['subject' => 'x', 'department_id' => $this->department->id, 'message' => 'y'];

        clSpa()->postJson('/api/client/tickets', $body)->assertStatus(401);
        tkAs($owner)->postJson('/api/client/tickets', $body)->assertStatus(403)->assertJsonPath('code', 'email_not_verified');

        $readOnly = tkMember($account, ['tickets.view']);
        tkAs($readOnly, $account)->postJson('/api/client/tickets', $body)->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
        expect(Ticket::count())->toBe(0);
    });

    it('is not allowed while staff are signed in as the client', function () {
        [$owner] = tkOwner();
        test()->withSession(['impersonated_by_admin_id' => Admin::factory()->create()->id, 'impersonation_started_at' => now()->getTimestamp()]);

        tkAs($owner)->postJson('/api/client/tickets', ['subject' => 'x', 'department_id' => $this->department->id, 'message' => 'y'])
            ->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');
    });

    it('allows 10 new tickets an hour', function () {
        [$owner] = tkOwner();

        foreach (range(1, 10) as $i) {
            tkOpen($owner, ['subject' => "Ticket {$i}"]);
        }

        tkAs($owner)->postJson('/api/client/tickets', ['subject' => 'Eleventh', 'department_id' => $this->department->id, 'message' => 'y'])->assertStatus(429);
    });

    describe('attachments', function () {
        it('stores files privately, with a random name, and lists them in the thread', function () {
            [$owner] = tkOwner();

            $response = tkAs($owner)->post('/api/client/tickets', [
                'subject' => 'Error log', 'department_id' => $this->department->id, 'message' => 'See the file.',
                'attachments' => [UploadedFile::fake()->create('error.log', 4, 'text/plain'), UploadedFile::fake()->image('screen shot.png', 200, 100)],
            ], ['Accept' => 'application/json'])->assertCreated();

            $files = TicketAttachment::orderBy('id')->get();
            expect($files)->toHaveCount(2)->and($files[0]->original_name)->toBe('error.log')->and($files[1]->original_name)->toBe('screen shot.png')
                ->and($files[0]->path)->toMatch('#^tickets/\d+/[A-Za-z0-9]{40}\.log$#');
            Storage::disk('local')->assertExists($files[0]->path);
            Storage::disk('public')->assertMissing($files[0]->path);
            expect($response->json('replies.0.attachments'))->toHaveCount(2)->and(array_keys($response->json('replies.0.attachments.0')))->toBe(['id', 'name', 'size', 'mime'])
                ->and($response->getContent())->not->toContain('tickets/')->not->toContain($files[0]->path);
        });

        it('refuses too many files, big files and other types', function (array $files) {
            [$owner] = tkOwner();

            tkAs($owner)->post('/api/client/tickets', ['subject' => 'x', 'department_id' => $this->department->id, 'message' => 'y', 'attachments' => $files], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('attachments.0');
        })->with([
            'a script' => fn () => [[UploadedFile::fake()->create('shell.php', 1, 'application/x-php')]],
            'an html page' => fn () => [[UploadedFile::fake()->create('page.html', 1, 'text/html')]],
            'an svg' => fn () => [[UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')]],
            'over 5 MB' => fn () => [[UploadedFile::fake()->create('big.zip', 5121, 'application/zip')]],
        ]);

        it('refuses more than 3 files', function () {
            [$owner] = tkOwner();
            $files = array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.txt", 1, 'text/plain'), range(1, 4));

            tkAs($owner)->post('/api/client/tickets', ['subject' => 'x', 'department_id' => $this->department->id, 'message' => 'y', 'attachments' => $files], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('attachments');
            expect(Ticket::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);
        });
    });
});

describe('GET /api/client/tickets', function () {
    it('lists the tickets of the account, latest activity first, with counts per status', function () {
        [$owner, $account] = tkOwner();
        $first = tkOpen($owner, ['subject' => 'First']);
        $second = tkOpen($owner, ['subject' => 'Second']);
        $second->forceFill(['status' => 'answered'])->save();
        $third = tkOpen($owner, ['subject' => 'Third']);
        Ticket::whereKey($third->id)->update(['status' => 'closed', 'last_reply_at' => now()->subDay()]);
        [$other, $otherAccount] = tkOwner();
        tkOpen($other, ['subject' => 'Not mine']);

        $response = tkAs($owner)->getJson('/api/client/tickets')->assertOk();

        expect(collect($response->json('data'))->pluck('subject')->all())->toBe(['Second', 'First', 'Third'])
            ->and($response->json('meta.counts'))->toBe(['all' => 3, 'open' => 1, 'answered' => 1, 'closed' => 1, 'unread' => 0])
            ->and(array_keys($response->json('data.0')))->toBe(['id', 'number', 'subject', 'status', 'priority', 'department', 'opened_by', 'last_reply_by', 'unread', 'last_reply_at', 'created_at', 'closed_at'])
            ->and($response->getContent())->not->toContain('Not mine');
        expect(collect(tkAs($owner)->getJson('/api/client/tickets?status=open')->json('data'))->pluck('subject')->all())->toBe(['First']);
    });

    it('paginates 15 to a page and rejects a bad status', function () {
        [$owner, $account] = tkOwner();
        foreach (range(1, 17) as $i) {
            Ticket::create(['account_id' => $account->id, 'opened_by' => $owner->id, 'department_id' => $this->department->id, 'subject' => "T{$i}", 'last_reply_at' => now()]);
        }

        tkAs($owner)->getJson('/api/client/tickets')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.total', 17);
        tkAs($owner)->getJson('/api/client/tickets?page=2')->assertJsonCount(2, 'data');
        tkAs($owner)->getJson('/api/client/tickets?status=nope')->assertStatus(422);
    });

    it('needs tickets.view, and shows only the account that is asked for', function () {
        [$owner, $account] = tkOwner();
        tkOpen($owner);
        $noAccess = tkMember($account, ['invoices.view']);
        $reader = tkMember($account, ['tickets.view']);
        [$stranger] = tkOwner();

        tkAs($noAccess, $account)->getJson('/api/client/tickets')->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
        tkAs($reader, $account)->getJson('/api/client/tickets')->assertOk()->assertJsonCount(1, 'data');
        tkAs($stranger, $account)->getJson('/api/client/tickets')->assertStatus(403)->assertJsonPath('code', 'account_access_denied');
    });

    it('lists the departments that are switched on', function () {
        [$owner] = tkOwner();
        TicketDepartment::create(['name' => 'Off', 'is_active' => false]);
        TicketDepartment::create(['name' => 'Billing', 'sort_order' => 2]);

        $names = collect(tkAs($owner)->getJson('/api/client/ticket-departments')->assertOk()->json('data'))->pluck('name')->all();

        expect($names)->toBe(['Technical Support', 'Billing']);
    });
});

describe('the thread', function () {
    it('shows the replies of the ticket, and hides the notes between staff', function () {
        [$owner] = tkOwner(['name' => 'Rahim Uddin']);
        $ticket = tkOpen($owner);
        $staff = Admin::factory()->create(['name' => 'Nusrat Jahan']);
        app(Tickets::class)->staffReply($ticket, $staff, 'We are looking into it.');
        app(Tickets::class)->staffReply($ticket, $staff, 'Secret: the client is rude.', internal: true);

        $response = tkAs($owner)->getJson("/api/client/tickets/{$ticket->id}")->assertOk();

        expect(collect($response->json('replies'))->pluck('body')->all())->toBe(['It shows a blank page since morning.', 'We are looking into it.'])
            ->and($response->json('replies.1'))->toMatchArray(['author_type' => 'staff', 'author_name' => 'Nusrat'])
            ->and($response->getContent())->not->toContain('Secret')->not->toContain('Jahan')->not->toContain('is_internal');
    });

    it('does not show a ticket of another account, even by guessing the id', function () {
        [$owner] = tkOwner();
        [$other] = tkOwner();
        $theirs = tkOpen($other);

        tkAs($owner)->getJson("/api/client/tickets/{$theirs->id}")->assertStatus(404);
        tkAs($owner)->postJson("/api/client/tickets/{$theirs->id}/replies", ['message' => 'hi'])->assertStatus(404);
        tkAs($owner)->postJson("/api/client/tickets/{$theirs->id}/close")->assertStatus(404);
        tkAs($owner)->getJson('/api/client/tickets/99999')->assertStatus(404);
        expect($theirs->fresh()->replies)->toHaveCount(1)->and($theirs->fresh()->status->value)->toBe('open');
    });
});

describe('POST /api/client/tickets/{id}/replies', function () {
    it('adds a reply and puts the ticket back in the queue of support', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $ticket->forceFill(['status' => 'answered', 'last_reply_by' => 'staff'])->save();

        $response = tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'Still not working.'])->assertOk();

        $fresh = $ticket->fresh();
        expect($fresh->status->value)->toBe('open')->and($fresh->last_reply_by)->toBe('client')->and($fresh->replies)->toHaveCount(2)
            ->and($response->json('status'))->toBe('open')->and($response->json('replies.1.body'))->toBe('Still not working.');
    });

    it('reopens a closed ticket', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/close")->assertOk();

        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'One more thing.'])->assertOk();

        expect($ticket->fresh()->status->value)->toBe('open')->and($ticket->fresh()->closed_at)->toBeNull();
    });

    it('takes files, and refuses an empty message', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);

        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => ''])->assertStatus(422)->assertJsonValidationErrors('message');
        tkAs($owner)->post("/api/client/tickets/{$ticket->id}/replies", ['message' => 'Here.', 'attachments' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'replies.1.attachments');
    });

    it('needs tickets.create, and not staff signed in as the client', function () {
        [$owner, $account] = tkOwner();
        $ticket = tkOpen($owner);
        $reader = tkMember($account, ['tickets.view']);

        tkAs($reader, $account)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'hi'])->assertStatus(403)->assertJsonPath('code', 'account_permission_denied');
        test()->withSession(['impersonated_by_admin_id' => Admin::factory()->create()->id, 'impersonation_started_at' => now()->getTimestamp()]);
        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'hi'])->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');
        expect($ticket->fresh()->replies)->toHaveCount(1);
    });

    it('lets any member with the permission work on a ticket the owner opened', function () {
        [$owner, $account] = tkOwner();
        $ticket = tkOpen($owner);
        $helper = tkMember($account, ['tickets.view', 'tickets.create']);

        tkAs($helper, $account)->postJson("/api/client/tickets/{$ticket->id}/replies", ['message' => 'I can add details.'])->assertOk()->assertJsonPath('replies.1.author_name', $helper->name);
    });
});

describe('POST /api/client/tickets/{id}/close', function () {
    it('closes the ticket', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);

        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/close")->assertOk()->assertJsonPath('status', 'closed')->assertJsonPath('closed_at', fn ($v) => $v !== null);

        expect($ticket->fresh()->status->value)->toBe('closed');
        expect(ActivityLog::where('action', 'ticket.closed')->count())->toBe(1);
        tkAs($owner)->postJson("/api/client/tickets/{$ticket->id}/close")->assertOk(); // closing twice changes nothing
        expect(ActivityLog::where('action', 'ticket.closed')->count())->toBe(1);
    });
});

describe('downloads', function () {
    function tkWithFile(ClientUser $owner, string $name = 'error.log'): array
    {
        tkAs($owner)->post('/api/client/tickets', [
            'subject' => 'File', 'department_id' => test()->department->id, 'message' => 'Attached.',
            'attachments' => [UploadedFile::fake()->createWithContent($name, 'line one')],
        ], ['Accept' => 'application/json'])->assertCreated();

        return [Ticket::latest('id')->first(), TicketAttachment::latest('id')->first()];
    }

    it('sends the file as a download, never to be shown in the page', function () {
        [$owner] = tkOwner();
        [$ticket, $file] = tkWithFile($owner);

        $response = tkAs($owner)->get("/api/client/tickets/{$ticket->id}/attachments/{$file->id}")->assertOk();

        expect($response->streamedContent())->toBe('line one')
            ->and($response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('error.log')
            ->and($response->headers->get('Content-Type'))->toContain('application/octet-stream')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('is only for people of the account with tickets.view', function () {
        [$owner, $account] = tkOwner();
        [$ticket, $file] = tkWithFile($owner);
        [$stranger] = tkOwner();
        $noAccess = tkMember($account, ['invoices.view']);

        tkAs($stranger)->get("/api/client/tickets/{$ticket->id}/attachments/{$file->id}")->assertStatus(404);
        tkAs($noAccess, $account)->get("/api/client/tickets/{$ticket->id}/attachments/{$file->id}")->assertStatus(403);
    });

    it('does not mix up a file with another ticket, and never gives out a file of a staff note', function () {
        [$owner] = tkOwner();
        [$ticket, $file] = tkWithFile($owner);
        $other = tkOpen($owner, ['subject' => 'Another']);
        $staff = Admin::factory()->create();
        $note = app(Tickets::class)->staffReply($ticket, $staff, 'internal', true, [UploadedFile::fake()->createWithContent('private.txt', 'secret')]);
        $noteFile = $note->attachments()->sole();

        tkAs($owner)->get("/api/client/tickets/{$other->id}/attachments/{$file->id}")->assertStatus(404);
        tkAs($owner)->get("/api/client/tickets/{$ticket->id}/attachments/{$noteFile->id}")->assertStatus(404);
    });

    it('cleans the file name the person sees', function (string $raw, string $clean) {
        expect(Tickets::cleanName($raw))->toBe($clean);
    })->with([
        'plain' => ['report.pdf', 'report.pdf'],
        'a path' => ['..\\..\\windows\\system32\\evil.txt', 'evil.txt'],
        'a unix path' => ['/etc/passwd', 'passwd'],
        'control characters' => ["a\x00b\nc.txt", 'a_b_c.txt'],
        'empty' => ['', 'file'],
    ]);
});

describe('staff: GET /api/admin/tickets', function () {
    function tkSeed(): array
    {
        [$alice, $aliceAccount] = tkOwner(['name' => 'Alice Rahman']);
        [$bob] = tkOwner(['name' => 'Bob Karim']);
        $a = tkOpen($alice, ['subject' => 'Website down', 'priority' => 'high']);
        $b = tkOpen($bob, ['subject' => 'Invoice question', 'department_id' => TicketDepartment::create(['name' => 'Billing', 'sort_order' => 2])->id]);
        $c = tkOpen($alice, ['subject' => 'Email not working', 'priority' => 'low']);
        Ticket::whereKey($b->id)->update(['status' => 'answered']);
        Ticket::whereKey($c->id)->update(['status' => 'closed']);

        return [$a, $b, $c];
    }

    it('lists tickets with the client, the department and counts', function () {
        [$a, $b, $c] = tkSeed();

        $response = api(staffTk())->getJson('/api/admin/tickets')->assertOk();

        expect(collect($response->json('data'))->pluck('number')->all())->toBe([$c->number, $b->number, $a->number])
            ->and($response->json('meta.counts'))->toBe(['all' => 3, 'open' => 1, 'answered' => 1, 'closed' => 1])
            ->and($response->json('meta.total'))->toBe(3)
            ->and(array_keys($response->json('data.2')))->toBe(['id', 'number', 'subject', 'status', 'priority', 'department', 'client', 'assignedTo', 'lastReplyBy', 'lastReplyAt', 'createdAt', 'closedAt'])
            ->and($response->json('data.2.client'))->toMatchArray(['openedBy' => ['name' => 'Alice Rahman', 'email' => $a->opener->email]])
            ->and($response->json('data.2.client.accountName'))->toBe($a->account->name);
    });

    it('filters by status, priority, department, assignee and search', function () {
        [$a, $b, $c] = tkSeed();
        $me = staffTk();
        Ticket::whereKey($a->id)->update(['assigned_to' => $me->id]);
        $numbers = fn (string $query) => collect(api($me)->getJson("/api/admin/tickets?{$query}")->assertOk()->json('data'))->pluck('number')->all();

        expect($numbers('status=open'))->toBe([$a->number])->and($numbers('status=answered'))->toBe([$b->number])
            ->and($numbers('priority=low'))->toBe([$c->number])->and($numbers('department='.$b->department_id))->toBe([$b->number])
            ->and($numbers('assigned=me'))->toBe([$a->number])->and($numbers('assigned='.$me->id))->toBe([$a->number])
            ->and($numbers('assigned=unassigned'))->toBe([$c->number, $b->number])
            ->and($numbers('search=invoice'))->toBe([$b->number])->and($numbers('search='.$a->number))->toBe([$a->number])
            ->and($numbers('search='.$a->id))->toBe([$a->number])->and($numbers('status=all&priority=all'))->toHaveCount(3);
    });

    it('narrows the counts by the other filters but not by the status', function () {
        tkSeed();

        $counts = api(staffTk())->getJson('/api/admin/tickets?status=open&priority=low')->assertOk()->json('meta.counts');

        expect($counts)->toBe(['all' => 1, 'open' => 0, 'answered' => 0, 'closed' => 1]);
    });

    it('can show the ticket that waited longest first', function () {
        [$a, $b, $c] = tkSeed();
        Ticket::whereKey($a->id)->update(['last_reply_at' => now()->subDays(3)]);

        $numbers = collect(api(staffTk())->getJson('/api/admin/tickets?sort=oldest')->json('data'))->pluck('number')->all();

        expect($numbers[0])->toBe($a->number);
    });

    it('rejects bad filters', function () {
        api(staffTk())->getJson('/api/admin/tickets?status=nope')->assertStatus(422);
        api(staffTk())->getJson('/api/admin/tickets?assigned=%27%20or%201')->assertStatus(422);
        api(staffTk())->getJson('/api/admin/tickets?sort=random')->assertStatus(422);
    });

    it('needs a sign-in and tickets.view', function () {
        test()->getJson('/api/admin/tickets')->assertStatus(401);
        api(staffWith(['clients.view']))->getJson('/api/admin/tickets')->assertStatus(403);
        api(staffWith(['tickets.view']))->getJson('/api/admin/tickets')->assertOk();
    });

    it('lists the options: departments and the staff who can be assigned', function () {
        Admin::factory()->create(['name' => 'Zed Invited', 'invited_at' => now()]);
        $zara = Admin::factory()->create(['name' => 'Zara Ali', 'invited_at' => null]);

        $data = api(staffTk())->getJson('/api/admin/tickets/options')->assertOk()->json('data');

        expect(collect($data['departments'])->pluck('name')->all())->toBe(['Technical Support'])
            ->and(collect($data['assignees'])->pluck('name')->all())->toContain('Zara Ali')->not->toContain('Zed Invited');
    });
});

describe('staff: the thread, replies and changes', function () {
    it('shows the whole thread, staff notes included', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $staff = Admin::factory()->create(['name' => 'Nusrat Jahan']);
        app(Tickets::class)->staffReply($ticket, $staff, 'Looking.');
        app(Tickets::class)->staffReply($ticket, $staff, 'Note for the team.', internal: true);

        $response = api(staffTk())->getJson("/api/admin/tickets/{$ticket->id}")->assertOk();

        expect(collect($response->json('data.replies'))->pluck('body')->all())->toBe(['It shows a blank page since morning.', 'Looking.', 'Note for the team.'])
            ->and($response->json('data.replies.2'))->toMatchArray(['isInternal' => true, 'authorType' => 'staff', 'authorName' => 'Nusrat Jahan'])
            ->and($response->json('data.replies.0'))->toMatchArray(['isInternal' => false, 'authorType' => 'client']);
        api(staffTk())->getJson('/api/admin/tickets/99999')->assertStatus(404);
    });

    it('a reply answers the ticket and emails the client', function () {
        [$owner] = tkOwner(['name' => 'Rahim Uddin']);
        $ticket = tkOpen($owner, ['subject' => 'Website down']);
        $staff = staffTk();

        api($staff)->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'Please clear your cache and try again.'])
            ->assertOk()->assertJsonPath('data.status', 'answered')->assertJsonPath('data.lastReplyBy', 'staff');

        expect($ticket->fresh()->status->value)->toBe('answered')->and($ticket->fresh()->replies)->toHaveCount(2)->and($ticket->fresh()->replies[1]->admin_id)->toBe($staff->id);
        Notification::assertSentTo($owner, ClientTicketReplyNotification::class, function ($notification) use ($ticket, $owner, $staff) {
            $mail = $notification->toMail($owner);

            return $mail->subject === "[{$ticket->number}] Website down" && $mail->actionUrl === 'http://localhost:3002/dashboard/support/'.$ticket->id
                && str_contains(implode(' ', $mail->introLines), 'Please clear your cache') && str_contains(implode(' ', $mail->introLines), $staff->name);
        });
        expect(new ClientTicketReplyNotification('a', 'b', 'c', 'd', 'e'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('an internal note tells nobody and does not change the status', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);

        api(staffTk())->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'Ask the server team.', 'internal' => true])
            ->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.replies.1.isInternal', true);

        Notification::assertNotSentTo($owner, ClientTicketReplyNotification::class);
        Notification::assertSentOnDemandTimes(StaffTicketAlertNotification::class, 1); // only the one from opening the ticket
        expect($ticket->fresh()->last_reply_by)->toBe('client');
    });

    it('takes files, and refuses an empty message', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $staff = staffTk();

        api($staff)->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => ''])->assertStatus(422)->assertJsonValidationErrors('message');
        api($staff)->post("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'Guide attached.', 'attachments' => [UploadedFile::fake()->create('guide.pdf', 20, 'application/pdf')]], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonCount(1, 'data.replies.1.attachments');

        $file = TicketAttachment::latest('id')->first();
        $download = api($staff)->get("/api/admin/tickets/{$ticket->id}/attachments/{$file->id}")->assertOk();
        expect($download->headers->get('Content-Disposition'))->toContain('guide.pdf');
        tkAs($owner)->get("/api/client/tickets/{$ticket->id}/attachments/{$file->id}")->assertOk(); // the client may download what staff sent them
    });

    it('needs tickets.edit to reply or change, and tickets.assign to assign', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $reader = staffWith(['tickets.view']);

        api($reader)->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'hi'])->assertStatus(403);
        api($reader)->putJson("/api/admin/tickets/{$ticket->id}", ['priority' => 'high'])->assertStatus(403);
        api(staffWith(['tickets.view', 'tickets.edit']))->postJson("/api/admin/tickets/{$ticket->id}/assign", ['adminId' => null])->assertStatus(403);
        expect($ticket->fresh()->replies)->toHaveCount(1);
    });

    it('changes the status, priority and department', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $billing = TicketDepartment::create(['name' => 'Billing', 'sort_order' => 2]);
        $staff = staffTk();

        api($staff)->putJson("/api/admin/tickets/{$ticket->id}", ['priority' => 'high', 'departmentId' => $billing->id])
            ->assertOk()->assertJsonPath('data.priority', 'high')->assertJsonPath('data.department.name', 'Billing');
        api($staff)->putJson("/api/admin/tickets/{$ticket->id}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closedAt', fn ($v) => $v !== null);
        api($staff)->putJson("/api/admin/tickets/{$ticket->id}", ['status' => 'open'])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.closedAt', null);

        api($staff)->putJson("/api/admin/tickets/{$ticket->id}", ['status' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('status');
        api($staff)->putJson("/api/admin/tickets/{$ticket->id}", ['departmentId' => 99999])->assertStatus(422)->assertJsonValidationErrors('departmentId');
        expect(ActivityLog::where('action', 'ticket.updated')->count())->toBeGreaterThan(0);
    });

    it('assigns a ticket to an active staff member, and takes it back', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $lead = Admin::factory()->create(['name' => 'Lead Person', 'invited_at' => null]);
        $manager = staffTk();

        api($manager)->postJson("/api/admin/tickets/{$ticket->id}/assign", ['adminId' => $lead->id])->assertOk()->assertJsonPath('data.assignedTo', ['id' => (string) $lead->id, 'name' => 'Lead Person']);
        api($manager)->postJson("/api/admin/tickets/{$ticket->id}/assign", ['adminId' => null])->assertOk()->assertJsonPath('data.assignedTo', null);
        api($manager)->postJson("/api/admin/tickets/{$ticket->id}/assign", ['adminId' => 99999])->assertStatus(422)->assertJsonValidationErrors('adminId');
        api($manager)->postJson("/api/admin/tickets/{$ticket->id}/assign", [])->assertStatus(422);
    });

    it('writes the staff actions to the activity log', function () {
        [$owner] = tkOwner();
        $ticket = tkOpen($owner);
        $staff = staffTk();

        api($staff)->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'hello'])->assertOk();
        api($staff)->postJson("/api/admin/tickets/{$ticket->id}/replies", ['message' => 'note', 'internal' => true])->assertOk();
        api($staff)->postJson("/api/admin/tickets/{$ticket->id}/assign", ['adminId' => $staff->id])->assertOk();

        $actions = ActivityLog::pluck('action')->all();
        expect($actions)->toContain('ticket.opened')->toContain('ticket.staff_replied')->toContain('ticket.note_added')->toContain('ticket.assigned');
    });
});

it('deletes the replies and files of a ticket when the account is removed', function () {
    [$owner, $account] = tkOwner();
    tkOpen($owner);

    ClientAccount::whereKey($account->id)->forceDelete();

    expect(Ticket::count())->toBe(0)->and(TicketReply::count())->toBe(0);
});

describe('visitors who are not signed in', function () {
    it('cannot reach any ticket route', function (string $method, string $path) {
        test()->json($method, $path)->assertStatus(401);
    })->with([
        ['GET', '/api/client/tickets'], ['POST', '/api/client/tickets'], ['GET', '/api/client/tickets/1'], ['POST', '/api/client/tickets/1/replies'],
        ['POST', '/api/client/tickets/1/close'], ['GET', '/api/client/tickets/1/attachments/1'], ['GET', '/api/client/ticket-departments'],
        ['GET', '/api/admin/tickets'], ['GET', '/api/admin/tickets/options'], ['GET', '/api/admin/tickets/1'], ['POST', '/api/admin/tickets/1/replies'],
        ['PUT', '/api/admin/tickets/1'], ['POST', '/api/admin/tickets/1/assign'], ['GET', '/api/admin/tickets/1/attachments/1'],
    ]);
});

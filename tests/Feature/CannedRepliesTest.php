<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\CannedReply;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function cannedStaff(): Admin
{
    return staffWith(['tickets.view', 'tickets.edit']);
}

describe('GET /api/admin/canned-replies', function () {
    it('lists the saved replies by title, with who wrote them', function () {
        $author = Admin::factory()->create(['name' => 'Nusrat Jahan']);
        CannedReply::create(['title' => 'Restart the server', 'body' => 'Please restart.', 'created_by' => $author->id]);
        CannedReply::create(['title' => 'Clear cache', 'body' => 'Please clear the cache.']);

        $data = api(cannedStaff())->getJson('/api/admin/canned-replies')->assertOk()->json('data');

        expect(collect($data)->pluck('title')->all())->toBe(['Clear cache', 'Restart the server'])
            ->and(array_keys($data[0]))->toBe(['id', 'title', 'body', 'createdBy', 'updatedAt'])
            ->and($data[0]['createdBy'])->toBeNull()->and($data[1]['createdBy'])->toBe('Nusrat Jahan');
    });

    it('needs tickets.edit, the permission of the people who reply', function () {
        api(staffWith(['tickets.view']))->getJson('/api/admin/canned-replies')->assertStatus(403);
        api(staffWith(['clients.view']))->getJson('/api/admin/canned-replies')->assertStatus(403);
    });
});

describe('POST /api/admin/canned-replies', function () {
    it('saves a reply, with the author', function () {
        $staff = cannedStaff();

        api($staff)->postJson('/api/admin/canned-replies', ['title' => '  Greeting ', 'body' => "Hello {client_name},\nThanks for writing about {ticket_number}.\n{staff_name}"])
            ->assertCreated()->assertJsonPath('data.title', 'Greeting')->assertJsonPath('data.createdBy', $staff->name)
            ->assertJsonPath('data.body', "Hello {client_name},\nThanks for writing about {ticket_number}.\n{staff_name}");

        expect(CannedReply::sole()->created_by)->toBe($staff->id);
        expect(ActivityLog::where('action', 'canned_reply.created')->count())->toBe(1);
    });

    it('refuses bad input', function (array $body, string $field) {
        CannedReply::create(['title' => 'Existing', 'body' => 'x']);

        api(cannedStaff())->postJson('/api/admin/canned-replies', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        expect(CannedReply::count())->toBe(1);
    })->with([
        'no title' => [['body' => 'x'], 'title'],
        'empty title' => [['title' => '  ', 'body' => 'x'], 'title'],
        'title too long' => [['title' => str_repeat('a', 101), 'body' => 'x'], 'title'],
        'a title that exists' => [['title' => 'Existing', 'body' => 'x'], 'title'],
        'no body' => [['title' => 'New'], 'body'],
        'empty body' => [['title' => 'New', 'body' => '   '], 'body'],
        'body too long' => [['title' => 'New', 'body' => str_repeat('a', 10001)], 'body'],
    ]);

    it('needs tickets.edit', function () {
        api(staffWith(['tickets.view']))->postJson('/api/admin/canned-replies', ['title' => 'x', 'body' => 'y'])->assertStatus(403);
    });
});

describe('PUT and DELETE /api/admin/canned-replies/{id}', function () {
    it('changes a saved reply, and may keep its own title', function () {
        $reply = CannedReply::create(['title' => 'Old title', 'body' => 'Old body']);
        CannedReply::create(['title' => 'Other', 'body' => 'x']);

        api(cannedStaff())->putJson("/api/admin/canned-replies/{$reply->id}", ['title' => 'Old title', 'body' => 'New body'])->assertOk()->assertJsonPath('data.body', 'New body');
        api(cannedStaff())->putJson("/api/admin/canned-replies/{$reply->id}", ['title' => 'New title'])->assertOk()->assertJsonPath('data.title', 'New title')->assertJsonPath('data.body', 'New body');
        api(cannedStaff())->putJson("/api/admin/canned-replies/{$reply->id}", ['title' => 'Other'])->assertStatus(422)->assertJsonValidationErrors('title');

        expect($reply->fresh())->title->toBe('New title');
        expect(ActivityLog::where('action', 'canned_reply.updated')->count())->toBe(2);
    });

    it('deletes one', function () {
        $reply = CannedReply::create(['title' => 'Gone', 'body' => 'x']);

        api(cannedStaff())->deleteJson("/api/admin/canned-replies/{$reply->id}")->assertOk();

        expect(CannedReply::count())->toBe(0);
        expect(ActivityLog::where('action', 'canned_reply.deleted')->count())->toBe(1);
    });

    it('answers 404 for one that does not exist, and needs tickets.edit', function () {
        $reply = CannedReply::create(['title' => 'Keep', 'body' => 'x']);

        api(cannedStaff())->putJson('/api/admin/canned-replies/99999', ['title' => 'x'])->assertStatus(404);
        api(cannedStaff())->deleteJson('/api/admin/canned-replies/99999')->assertStatus(404);
        api(staffWith(['tickets.view']))->putJson("/api/admin/canned-replies/{$reply->id}", ['title' => 'x'])->assertStatus(403);
        api(staffWith(['tickets.view']))->deleteJson("/api/admin/canned-replies/{$reply->id}")->assertStatus(403);
        expect($reply->fresh())->title->toBe('Keep');
    });

    it('keeps the replies when the person who wrote them is removed', function () {
        $author = Admin::factory()->create();
        $reply = CannedReply::create(['title' => 'Mine', 'body' => 'x', 'created_by' => $author->id]);

        $author->forceDelete();

        expect($reply->fresh()->created_by)->toBeNull();
    });
});

it('is closed to visitors who are not signed in', function (string $method, string $path) {
    test()->json($method, $path)->assertStatus(401);
})->with([['GET', '/api/admin/canned-replies'], ['POST', '/api/admin/canned-replies'], ['PUT', '/api/admin/canned-replies/1'], ['DELETE', '/api/admin/canned-replies/1']]);

it('is open to Super Admin, Admin and Support Staff, not to Billing or Sales: they cannot reply to tickets', function () {
    $as = fn (string $role) => tap(Admin::factory()->create())->assignRole($role);

    foreach (['Super Admin', 'Admin', 'Support Staff'] as $role) {
        api($as($role))->getJson('/api/admin/canned-replies')->assertOk();
    }
    foreach (['Billing Manager', 'Sales'] as $role) {
        api($as($role))->getJson('/api/admin/canned-replies')->assertStatus(403);
    }
});

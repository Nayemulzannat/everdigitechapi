<?php

use App\Models\Admin;
use App\Models\ClientLoginHistory;
use App\Models\ClientSession;
use App\Models\ClientUser;
use App\Notifications\ClientConfirmEmailChangeNotification;
use App\Notifications\ClientEmailChangedNotification;
use App\Notifications\ClientNewSignInNotification;
use App\Notifications\ClientPasswordChangedNotification;
use App\Notifications\ClientResetPasswordNotification;
use App\Notifications\ClientVerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

describe('client_sessions tracking', function () {
    beforeEach(fn () => Notification::fake());

    it('writes a row for this browser on every authenticated request', function () {
        $user = clUser();
        $sid = Str::random(40);

        clAs($user, $sid, CL_FIREFOX_LINUX)->getJson('/api/client/me')->assertOk();

        $row = ClientSession::sole();
        expect($row->client_user_id)->toBe($user->id)->and($row->session_id)->toBe($sid)->and($row->ip_address)->toBe('127.0.0.1')
            ->and($row->browser)->toBe('Firefox')->and($row->platform)->toBe('Linux')->and($row->user_agent)->toBe(CL_FIREFOX_LINUX);
    });

    it('does not write a row for a request nobody is signed in on', function () {
        clSpa()->getJson('/api/client/me')->assertStatus(401);
        clSpa()->postJson('/api/client/login', ['email' => 'a@b.co', 'password' => 'x'])->assertStatus(422);

        expect(ClientSession::count())->toBe(0);
    });

    it('keeps one row per browser, and refreshes its time at most once a minute', function () {
        $user = clUser();
        $sid = Str::random(40);
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();
        $first = ClientSession::sole()->last_active_at;

        $this->travel(20)->seconds();
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();
        expect(ClientSession::count())->toBe(1)->and(ClientSession::sole()->last_active_at->equalTo($first))->toBeTrue();

        $this->travel(61)->seconds();
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();
        expect(ClientSession::count())->toBe(1)->and(ClientSession::sole()->last_active_at->gt($first))->toBeTrue();
    });

    it('notices a changed IP address at once', function () {
        $user = clUser();
        $sid = Str::random(40);
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77']);
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();

        expect(ClientSession::sole()->ip_address)->toBe('203.0.113.77');
    });

    it('follows the browser when another client signs in on it', function () {
        $sid = Str::random(40);
        $alice = clUser();
        $bob = clUser();
        clAs($alice, $sid)->getJson('/api/client/me')->assertOk();

        clAs($bob, $sid)->getJson('/api/client/me')->assertOk();

        expect(ClientSession::count())->toBe(1)->and(ClientSession::sole()->client_user_id)->toBe($bob->id);
    });

    it('is written when someone signs in or registers, and removed when they sign out', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clLogin($user->email, CL_PASSWORD)->assertOk();
        expect(ClientSession::where('client_user_id', $user->id)->count())->toBe(1);

        clRegister(['email' => 'new.person@uddintraders.com'])->assertCreated();
        expect(ClientSession::count())->toBe(2);
    });

    it('is removed when the browser signs out', function () {
        $user = clUser();
        $sid = Str::random(40);
        clAs($user, $sid)->getJson('/api/client/me')->assertOk();
        clOtherDevice($user);
        expect(ClientSession::count())->toBe(2);

        clAs($user, $sid)->postJson('/api/client/logout')->assertNoContent();

        expect(ClientSession::pluck('session_id')->all())->not->toContain($sid)->and(ClientSession::count())->toBe(1);
    });

    it('is not touched by a staff sign-in', function () {
        $this->actingAs(Admin::factory()->create(), 'admin');

        test()->withHeaders(['Origin' => 'http://localhost:3003'])->getJson('/api/admin/me')->assertOk();

        expect(ClientSession::count())->toBe(0);
    });
});

describe('GET /sessions', function () {
    beforeEach(fn () => Notification::fake());

    it('lists the devices, this one first, newest first after that', function () {
        $user = clUser();
        $older = clOtherDevice($user, 'Edge', 'Windows', 100);
        $newer = clOtherDevice($user, 'Safari', 'iOS', 30);
        $mine = Str::random(40);

        $data = clAs($user, $mine, CL_FIREFOX_LINUX)->getJson('/api/client/sessions')->assertOk()->json('data');

        expect(collect($data)->pluck('device')->all())->toBe(['Firefox on Linux', 'Safari on iOS', 'Edge on Windows'])
            ->and(collect($data)->pluck('is_current')->all())->toBe([true, false, false])
            ->and($data[0])->toMatchArray(['browser' => 'Firefox', 'platform' => 'Linux', 'ip_address' => '127.0.0.1'])
            ->and(array_keys($data[0]))->toBe(['id', 'device', 'browser', 'platform', 'ip_address', 'last_active_at', 'is_current']);
    });

    it('never shows a session id', function () {
        $user = clUser();
        $other = clOtherDevice($user);
        $mine = Str::random(40);

        $json = clAs($user, $mine)->getJson('/api/client/sessions')->assertOk()->getContent();

        expect($json)->not->toContain($other)->not->toContain($mine)->not->toContain('session_id');
    });

    it('shows only this person\'s devices', function () {
        $user = clUser();
        clOtherDevice(clUser());
        clOtherDevice(clUser(), 'Opera', 'Mac');

        $data = clAs($user)->getJson('/api/client/sessions')->assertOk()->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['is_current'])->toBeTrue();
    });

    it('leaves out a device that has been idle for longer than a session lasts', function () {
        $user = clUser();
        clOtherDevice($user, 'Edge', 'Windows', 60);
        clOtherDevice($user, 'Opera', 'Mac', 121);

        $devices = collect(clAs($user)->getJson('/api/client/sessions')->json('data'))->pluck('device')->all();

        expect($devices)->toContain('Edge on Windows')->not->toContain('Opera on Mac');
    });

    it('gives a device with no known browser a plain name', function () {
        $user = clUser();
        $id = clOtherDevice($user);
        ClientSession::where('session_id', $id)->update(['browser' => null, 'platform' => null]);

        $devices = collect(clAs($user)->getJson('/api/client/sessions')->json('data'))->pluck('device')->all();

        expect($devices)->toContain('Unknown device');
    });

    it('needs a sign-in', function () {
        clSpa()->getJson('/api/client/sessions')->assertStatus(401);
    });
});

describe('DELETE /sessions/others', function () {
    beforeEach(fn () => Notification::fake());

    it('signs out every other device and keeps the current one', function () {
        $user = clUser(['password' => CL_PASSWORD, 'remember_token' => 'old-remember-token']);
        $mine = Str::random(40);
        Session::driver()->getHandler()->write($mine, serialize(['marker' => 'mine']));
        clAs($user, $mine)->getJson('/api/client/me')->assertOk();
        $phone = clOtherDevice($user, 'Chrome', 'Android');
        $laptop = clOtherDevice($user, 'Edge', 'Windows');

        clAs($user, $mine)->deleteJson('/api/client/sessions/others', ['password' => CL_PASSWORD])
            ->assertOk()->assertJsonPath('signed_out', 2)->assertJsonPath('message', 'Signed out of 2 other devices.');

        expect(clDeviceAlive($phone))->toBeFalse()->and(clDeviceAlive($laptop))->toBeFalse()->and(clDeviceAlive($mine))->toBeTrue()
            ->and(ClientSession::pluck('session_id')->all())->toBe([$mine])
            ->and($user->fresh()->remember_token)->not->toBe('old-remember-token');

        // And this browser is still listed, still current, still signed in.
        $list = clAs($user, $mine)->getJson('/api/client/sessions')->assertOk()->json('data');
        expect($list)->toHaveCount(1)->and($list[0]['is_current'])->toBeTrue();
        clAs($user, $mine)->getJson('/api/client/me')->assertOk();
    });

    it('needs the right password, and signs nobody out without it', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $other = clOtherDevice($user);

        clAs($user)->deleteJson('/api/client/sessions/others', ['password' => 'not-my-password'])
            ->assertStatus(422)->assertJsonValidationErrors('password')->assertJsonPath('errors.password.0', 'Your password is incorrect.');
        clAs($user)->deleteJson('/api/client/sessions/others', [])->assertStatus(422)->assertJsonValidationErrors('password');

        expect(clDeviceAlive($other))->toBeTrue()->and(ClientSession::where('session_id', $other)->exists())->toBeTrue();
    });

    it('leaves other people\'s devices alone', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $stranger = clOtherDevice(clUser());
        clOtherDevice($user);

        clAs($user)->deleteJson('/api/client/sessions/others', ['password' => CL_PASSWORD])->assertOk()->assertJsonPath('signed_out', 1);

        expect(clDeviceAlive($stranger))->toBeTrue();
    });

    it('says so when there is nothing to sign out', function () {
        clAs(clUser(['password' => CL_PASSWORD]))->deleteJson('/api/client/sessions/others', ['password' => CL_PASSWORD])
            ->assertOk()->assertJsonPath('signed_out', 0)->assertJsonPath('message', 'You are not signed in anywhere else.');
    });

    it('needs a sign-in', function () {
        clSpa()->deleteJson('/api/client/sessions/others', ['password' => 'x'])->assertStatus(401);
    });

    it('limits password guesses to 5 a minute', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $other = clOtherDevice($user);

        foreach (range(1, 5) as $i) {
            clAs($user)->deleteJson('/api/client/sessions/others', ['password' => 'wrong-guess-'.$i])->assertStatus(422);
        }

        clAs($user)->deleteJson('/api/client/sessions/others', ['password' => CL_PASSWORD])->assertStatus(429);
        expect(clDeviceAlive($other))->toBeTrue();
    });
});

describe('GET /login-history', function () {
    beforeEach(fn () => Notification::fake());

    function clHistory(ClientUser $user, int $count, array $over = []): void
    {
        foreach (range(1, $count) as $i) {
            ClientLoginHistory::create([
                'client_user_id' => $user->id, 'email_attempted' => $user->email, 'ip_address' => '203.0.113.'.$i, 'browser' => 'Chrome', 'platform' => 'Windows',
                'successful' => true, ...$over,
            ]);
        }
    }

    it('lists sign-ins newest first, 20 to a page', function () {
        $user = clUser();
        foreach (range(1, 25) as $i) {
            $row = ClientLoginHistory::create(['client_user_id' => $user->id, 'email_attempted' => $user->email, 'ip_address' => "203.0.113.{$i}", 'successful' => true]);
            ClientLoginHistory::whereKey($row->id)->update(['created_at' => now()->subHours(26 - $i)]);
        }

        $page1 = clAs($user)->getJson('/api/client/login-history')->assertOk();

        $page1->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25)->assertJsonPath('meta.per_page', 20)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.ip_address', '203.0.113.25')->assertJsonPath('data.19.ip_address', '203.0.113.6');
        clAs($user)->getJson('/api/client/login-history?page=2')->assertJsonCount(5, 'data')->assertJsonPath('data.4.ip_address', '203.0.113.1');
    });

    it('only goes back 90 days', function () {
        $user = clUser();
        ClientLoginHistory::whereKey(ClientLoginHistory::create(['client_user_id' => $user->id, 'email_attempted' => $user->email, 'ip_address' => '10.0.0.1', 'successful' => true])->id)->update(['created_at' => now()->subDays(89)]);
        ClientLoginHistory::whereKey(ClientLoginHistory::create(['client_user_id' => $user->id, 'email_attempted' => $user->email, 'ip_address' => '10.0.0.2', 'successful' => true])->id)->update(['created_at' => now()->subDays(91)]);

        $ips = collect(clAs($user)->getJson('/api/client/login-history')->json('data'))->pluck('ip_address')->all();

        expect($ips)->toBe(['10.0.0.1']);
    });

    it('shows this person\'s history only, failed attempts included', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $other = clUser();
        clHistory($other, 3);
        clLogin($user->email, 'wrong-password-123')->assertStatus(422);
        Auth::guard('client')->logout();
        clLogin($user->email, CL_PASSWORD)->assertOk();

        $data = clAs($user)->getJson('/api/client/login-history')->assertOk()->json('data');

        expect($data)->toHaveCount(2)
            ->and(collect($data)->pluck('successful')->all())->toBe([true, false])
            ->and($data[1]['failure_reason'])->toBe('invalid_credentials')
            ->and($data[0])->toMatchArray(['browser' => 'Chrome', 'platform' => 'Windows', 'impersonated' => false])
            ->and(array_keys($data[0]))->toBe(['id', 'ip_address', 'browser', 'platform', 'successful', 'failure_reason', 'impersonated', 'created_at']);
    });

    it('marks a sign-in made by staff on the client\'s behalf', function () {
        $user = clUser();
        clHistory($user, 1, ['impersonated_by_admin_id' => Admin::factory()->create()->id]);

        clAs($user)->getJson('/api/client/login-history')->assertJsonPath('data.0.impersonated', true);
    });

    it('writes dates as ISO 8601', function () {
        $user = clUser();
        clHistory($user, 1);

        expect(clAs($user)->getJson('/api/client/login-history')->json('data.0.created_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    });

    it('needs a sign-in', function () {
        clSpa()->getJson('/api/client/login-history')->assertStatus(401);
    });
});

describe('the emails', function () {
    $mails = [
        'verify your email' => fn (ClientUser $u) => (new ClientVerifyEmailNotification)->toMail($u),
        'new sign-in' => fn (ClientUser $u) => (new ClientNewSignInNotification(now(), null, 'Chrome on Windows', '127.0.0.1'))->toMail($u),
        'reset your password' => fn (ClientUser $u) => (new ClientResetPasswordNotification('tok'))->toMail($u),
        'password changed' => fn (ClientUser $u) => (new ClientPasswordChangedNotification(now(), '127.0.0.1'))->toMail($u),
        'confirm new email' => fn (ClientUser $u) => (new ClientConfirmEmailChangeNotification('http://localhost:3002/confirm-email-change?id=1', $u->name))->toMail($u),
        'email changed' => fn (ClientUser $u) => (new ClientEmailChangedNotification($u->name, 'new@uddintraders.com'))->toMail($u),
    ];

    it('are queued', function (string $class) {
        expect(new $class(...match ($class) {
            ClientNewSignInNotification::class => [now(), null, 'x', null],
            ClientResetPasswordNotification::class => ['t'],
            ClientPasswordChangedNotification::class => [now()],
            ClientConfirmEmailChangeNotification::class => ['u', 'n'],
            ClientEmailChangedNotification::class => ['n', 'e'],
            default => [],
        }))->toBeInstanceOf(ShouldQueue::class);
    })->with([
        ClientVerifyEmailNotification::class, ClientNewSignInNotification::class, ClientResetPasswordNotification::class,
        ClientPasswordChangedNotification::class, ClientConfirmEmailChangeNotification::class, ClientEmailChangedNotification::class,
    ]);

    it('use the EverDigitech layout: the wordmark in the primary colour, and a #0B44F5 button', function (string $name) use ($mails) {
        $html = (string) $mails[$name](clUser(['name' => 'Rahim Uddin']))->render();

        expect($html)->toContain('Ever<span')->toContain('Digitech')->toContain('#0B44F5')->toContain('button-primary')
            ->and($html)->not->toContain('Laravel')->not->toContain('laravel.com');
        expect($html)->toContain('Hello Rahim Uddin');
    })->with(array_keys($mails));

    it('show the logo picture at the top when this site has a public address', function () use ($mails) {
        config(['everdigitech.mail_logo_url' => 'https://api.everdigitech.com/images/everdigitech-email.png']);

        $html = (string) $mails['reset your password'](clUser(['name' => 'Rahim Uddin']))->render();

        expect($html)->toContain('<img src="https://api.everdigitech.com/images/everdigitech-email.png" alt="EverDigitech"')->not->toContain('Ever<span');
    });

    it('show the name as text on localhost, where nobody else could open a picture', function () use ($mails) {
        expect(config('everdigitech.mail_logo_url'))->toBeNull();
        expect((string) $mails['reset your password'](clUser())->render())->toContain('Ever<span')->not->toContain('<img src=');
    });

    it('has the logo file that the picture address points to', function () {
        expect(public_path('images/everdigitech-email.png'))->toBeFile()->and(getimagesize(public_path('images/everdigitech-email.png'))[0])->toBe(480);
    });
});

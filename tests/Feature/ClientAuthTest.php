<?php

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\AccountMember;
use App\Models\Admin;
use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Notifications\ClientNewSignInNotification;
use App\Notifications\ClientVerifyEmailNotification;
use App\Services\EmailVerificationLink;
use App\Services\GeoIp\GeoIpLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

describe('POST /register', function () {
    beforeEach(fn () => Notification::fake());

    it('creates the user, an account and the owner membership, then signs the user in', function () {
        $response = clRegister()->assertCreated();

        $user = ClientUser::where('email', 'rahim@uddintraders.com')->sole();
        $account = ClientAccount::sole();
        $membership = AccountMember::sole();

        expect($user->name)->toBe('Rahim Uddin')
            ->and($user->phone)->toBe('+8801711223344')
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->last_account_id)->toBe($account->id)
            ->and($account->name)->toBe('Uddin Traders')
            ->and($account->company)->toBe('Uddin Traders')
            ->and($account->owner_user_id)->toBe($user->id)
            ->and($account->status)->toBe(ClientStatus::Active)
            ->and($account->client_group)->toBe(ClientGroup::Regular)
            ->and($membership->account_id)->toBe($account->id)
            ->and($membership->user_id)->toBe($user->id)
            ->and($membership->role)->toBe(MemberRole::Owner)
            ->and($membership->status)->toBe(MemberStatus::Active)
            ->and($membership->joined_at)->not->toBeNull()
            ->and(Hash::check(CL_PASSWORD, $user->password))->toBeTrue()
            ->and(Auth::guard('client')->check())->toBeTrue()
            ->and(Auth::guard('admin')->check())->toBeFalse();

        $response->assertJsonPath('data.user.email', 'rahim@uddintraders.com')
            ->assertJsonPath('data.current_account_id', (string) $account->id)
            ->assertJsonPath('data.accounts.0.role', 'owner');
    });

    it('names the account after the person when there is no company', function () {
        clRegister(['company' => null])->assertCreated();
        clRegister(['email' => 'b@uddintraders.com', 'company' => ''])->assertCreated();

        expect(ClientAccount::pluck('name')->unique()->all())->toBe(['Rahim Uddin'])
            ->and(ClientAccount::whereNotNull('company')->count())->toBe(0);
    });

    it('lowercases the email and writes the sign-up in the login history', function () {
        clRegister(['email' => 'Rahim@UddinTraders.COM'])->assertCreated();

        $history = ClientLoginHistory::sole();
        expect(ClientUser::sole()->email)->toBe('rahim@uddintraders.com')
            ->and($history->successful)->toBeTrue()->and($history->browser)->toBe('Chrome')->and($history->platform)->toBe('Windows');
    });

    it('sends the verification email (queued) and no new-sign-in alert', function () {
        clRegister()->assertCreated();

        Notification::assertSentTo(ClientUser::sole(), ClientVerifyEmailNotification::class);
        Notification::assertNotSentTo(ClientUser::sole(), ClientNewSignInNotification::class);
        expect(new ClientVerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
    });

    it('returns the same shape as GET /me', function () {
        $register = clRegister()->assertCreated()->json('data');
        $me = clSpa()->getJson('/api/client/me')->assertOk()->json('data');

        expect($register)->toBe($me)->and(array_keys($me))->toBe(['user', 'accounts', 'current_account_id', 'impersonation']);
    });

    it('rejects an email that is already registered', function () {
        clUser(['email' => 'rahim@uddintraders.com']);

        clRegister()->assertStatus(422)->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'An account with this email already exists. Try signing in.');
        expect(ClientUser::count())->toBe(1);
    });

    it('rejects an email already registered in another case, and a deleted person\'s email', function () {
        tap(clUser(['email' => 'rahim@uddintraders.com']))->delete();

        clRegister(['email' => 'RAHIM@uddintraders.com'])->assertStatus(422)->assertJsonValidationErrors('email');
    });

    it('rejects throwaway email services, subdomains too', function (string $email) {
        clRegister(['email' => $email])->assertStatus(422)->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', 'Use a permanent email address. Temporary email services are not accepted.');
        expect(ClientUser::count())->toBe(0);
    })->with(['mailinator' => 'someone@mailinator.com', 'subdomain' => 'x@inbox.guerrillamail.com', 'upper case' => 'X@YOPMAIL.COM']);

    it('follows the list in config/disposable_domains.php', function () {
        config(['disposable_domains.domains' => ['badmail.test']]);

        clRegister(['email' => 'a@badmail.test'])->assertStatus(422)->assertJsonValidationErrors('email');
        clRegister(['email' => 'a@mailinator.com'])->assertCreated(); // no longer on the list
    });

    it('accepts all three Bangladeshi phone formats and stores +8801XXXXXXXXX', function (string $given) {
        clRegister(['phone' => $given])->assertCreated();

        expect(ClientUser::sole()->phone)->toBe('+8801711223344');
    })->with(['national' => '01711223344', 'country code' => '8801711223344', 'plus' => '+8801711223344', 'spaces and dashes' => '+880 1711-223 344']);

    it('rejects phone numbers that are not Bangladeshi mobiles', function (string $given) {
        clRegister(['phone' => $given])->assertStatus(422)->assertJsonValidationErrors('phone');
    })->with(['too short' => '0171122334', 'too long' => '017112233445', 'landline' => '0221234567', 'old operator 011' => '01111223344', 'other country' => '+919876543210', 'letters' => '01711abc344', 'plus national' => '+01711223344']);

    it('enforces the password rules', function (string $password) {
        clRegister(['password' => $password, 'password_confirmation' => $password])->assertStatus(422)->assertJsonValidationErrors('password');
    })->with(['too short' => 'Aa1-short', 'no upper case' => 'lowercase-only-123', 'no lower case' => 'UPPERCASE-ONLY-123', 'no number' => 'No-Numbers-In-Here']);

    it('accepts a 10 character password (clients need 10, staff need 12)', function () {
        clRegister(['password' => 'Abcdefgh1j', 'password_confirmation' => 'Abcdefgh1j'])->assertCreated();
    });

    it('needs the confirmation to match', function () {
        clRegister(['password_confirmation' => 'Different-Pass-9'])->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('needs the terms accepted', function () {
        clRegister(['accept_terms' => false])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
        clSpa()->postJson('/api/client/register', collect(clRegisterBody())->except('accept_terms')->all())->assertStatus(422)->assertJsonValidationErrors('accept_terms');
    });

    it('needs name, email, phone and password', function () {
        clSpa()->postJson('/api/client/register', [])->assertStatus(422)->assertJsonValidationErrors(['name', 'email', 'phone', 'password', 'accept_terms']);
    });

    it('leaves nothing behind when a step fails (one transaction)', function () {
        AccountMember::creating(fn () => throw new RuntimeException('disk full'));

        clRegister()->assertStatus(500);

        expect(ClientUser::count())->toBe(0)->and(ClientAccount::count())->toBe(0)->and(AccountMember::count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('allows 5 registrations per IP per hour', function () {
        foreach (range(1, 5) as $i) {
            clRegister(['email' => "person{$i}@uddintraders.com"])->assertCreated();
            Auth::guard('client')->logout();
        }

        clRegister(['email' => 'person6@uddintraders.com'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect(ClientUser::count())->toBe(5);

        $this->travel(61)->minutes();
        clRegister(['email' => 'person6@uddintraders.com'])->assertCreated();
    });
});

describe('captcha', function () {
    beforeEach(fn () => Notification::fake());

    it('is skipped when TURNSTILE_SECRET is empty', function () {
        config(['services.turnstile.secret' => null]);
        Http::fake();

        clRegister(['captcha_token' => null])->assertCreated();

        Http::assertNothingSent();
    });

    describe('with a secret', function () {
        beforeEach(fn () => config(['services.turnstile.secret' => 'secret-key']));

        it('lets a token Cloudflare accepts through', function () {
            Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

            clRegister(['captcha_token' => 'good-token'])->assertCreated();

            Http::assertSent(fn ($request) => $request['secret'] === 'secret-key' && $request['response'] === 'good-token' && $request['remoteip'] === '127.0.0.1');
        });

        it('fails when Cloudflare rejects the token', function () {
            Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

            clRegister()->assertStatus(422)->assertJsonValidationErrors('captcha_token');
            expect(ClientUser::count())->toBe(0);
        });

        it('fails without a token, without asking Cloudflare', function () {
            Http::fake();

            clRegister(['captcha_token' => null])->assertStatus(422)->assertJsonValidationErrors('captcha_token');

            Http::assertNothingSent();
        });

        it('fails when Cloudflare cannot be reached', function () {
            Http::fake(['challenges.cloudflare.com/*' => fn () => throw new ConnectionException('timeout')]);

            clRegister()->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        });

        it('fails when Cloudflare answers with an error', function () {
            Http::fake(['challenges.cloudflare.com/*' => Http::response('oops', 500)]);

            clRegister()->assertStatus(422)->assertJsonValidationErrors('captcha_token');
        });

        it('does not spend the one-time token on a form with other mistakes', function () {
            Http::fake();

            clRegister(['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');

            Http::assertNothingSent();
        });
    });
});

describe('email verification', function () {
    beforeEach(fn () => Notification::fake());

    it('puts a link to the client panel in the email, with id, hash, expires and signature', function () {
        $user = clUser();

        $url = (new ClientVerifyEmailNotification)->toMail($user)->actionUrl;
        $values = clLinkValues($url);

        expect($url)->toStartWith('http://localhost:3002/verify-email?')
            ->and(array_keys($values))->toBe(['id', 'hash', 'expires', 'signature'])
            ->and((int) $values['id'])->toBe($user->id)
            ->and($values['hash'])->toBe(sha1($user->email))
            ->and((int) $values['expires'])->toBeGreaterThan(now()->addMinutes(59)->getTimestamp())->toBeLessThanOrEqual(now()->addMinutes(60)->getTimestamp());
    });

    it('verifies the email with a valid link, no sign-in needed', function () {
        $user = clUser(['email_verified_at' => null]);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($user));

        clSpa()->postJson('/api/client/email/verify', $values)->assertOk()->assertJsonPath('message', 'Your email address is verified.');

        expect($user->fresh()->email_verified_at)->not->toBeNull();
    });

    it('says success again when the email is already verified', function () {
        $user = clUser(['email_verified_at' => null]);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($user));
        clSpa()->postJson('/api/client/email/verify', $values)->assertOk();
        $verifiedAt = $user->fresh()->email_verified_at;

        $this->travel(5)->minutes();
        clSpa()->postJson('/api/client/email/verify', $values)->assertOk()->assertJsonPath('message', 'Your email address is already verified.');

        expect($user->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    });

    it('refuses an expired link (after 60 minutes) with its own code', function () {
        $user = clUser(['email_verified_at' => null]);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($user));

        $this->travel(59)->minutes();
        clSpa()->postJson('/api/client/email/verify', $values)->assertOk();
        ClientUser::whereKey($user->id)->update(['email_verified_at' => null]); // the model in hand is stale

        $this->travel(2)->minutes();
        clSpa()->postJson('/api/client/email/verify', $values)->assertStatus(422)->assertJsonPath('code', 'verification_link_expired');
        expect($user->fresh()->email_verified_at)->toBeNull();
    });

    it('refuses a tampered link', function (string $field, string|int $value) {
        $user = clUser(['email_verified_at' => null]);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($user));

        clSpa()->postJson('/api/client/email/verify', [...$values, $field => $value])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_verification_link');

        expect($user->fresh()->email_verified_at)->toBeNull();
    })->with([
        'signature' => ['signature', 'deadbeef'],
        'hash' => ['hash', 'abc123'],
        'longer expiry' => ['expires', 4102444800],
        'someone else' => ['id', 9999],
    ]);

    it('does not let one person\'s link verify another person', function () {
        $alice = clUser(['email_verified_at' => null]);
        $bob = clUser(['email_verified_at' => null]);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($alice));

        clSpa()->postJson('/api/client/email/verify', [...$values, 'id' => $bob->id])->assertStatus(422);

        expect($bob->fresh()->email_verified_at)->toBeNull();
    });

    it('stops working once the email address has changed', function () {
        $user = clUser(['email_verified_at' => null, 'email' => 'old@uddintraders.com']);
        $values = clLinkValues(app(EmailVerificationLink::class)->make($user));
        $user->forceFill(['email' => 'new@uddintraders.com'])->save();

        clSpa()->postJson('/api/client/email/verify', $values)->assertStatus(422)->assertJsonPath('code', 'invalid_verification_link');
    });

    it('needs all four values', function () {
        clSpa()->postJson('/api/client/email/verify', [])->assertStatus(422)->assertJsonValidationErrors(['id', 'hash', 'expires', 'signature']);
    });

    describe('POST /email/verification-notification', function () {
        it('needs a sign-in', function () {
            clSpa()->postJson('/api/client/email/verification-notification')->assertStatus(401);
        });

        it('sends the link again', function () {
            $user = clUser(['email_verified_at' => null]);

            clSpa()->actingAs($user, 'client')->postJson('/api/client/email/verification-notification')->assertOk();

            Notification::assertSentToTimes($user, ClientVerifyEmailNotification::class, 1);
        });

        it('sends nothing to someone who is already verified', function () {
            $user = clUser();

            clSpa()->actingAs($user, 'client')->postJson('/api/client/email/verification-notification')
                ->assertOk()->assertJsonPath('message', 'Your email address is already verified.');

            Notification::assertNothingSent();
        });

        it('allows 1 a minute', function () {
            $user = clUser(['email_verified_at' => null]);
            $send = fn () => clSpa()->actingAs($user, 'client')->postJson('/api/client/email/verification-notification');

            $send()->assertOk();
            $send()->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
            Notification::assertSentToTimes($user, ClientVerifyEmailNotification::class, 1);

            $this->travel(61)->seconds();
            $send()->assertOk();
            Notification::assertSentToTimes($user, ClientVerifyEmailNotification::class, 2);
        });

        it('allows 6 an hour', function () {
            $user = clUser(['email_verified_at' => null]);
            $send = fn () => clSpa()->actingAs($user, 'client')->postJson('/api/client/email/verification-notification');

            foreach (range(1, 6) as $i) {
                $send()->assertOk();
                $this->travel(61)->seconds();
            }

            $send()->assertStatus(429); // a minute has passed, the hour has not
            Notification::assertSentToTimes($user, ClientVerifyEmailNotification::class, 6);

            $this->travel(60)->minutes();
            $send()->assertOk();
        });

        it('counts each person separately', function () {
            $a = clUser(['email_verified_at' => null]);
            $b = clUser(['email_verified_at' => null]);

            clSpa()->actingAs($a, 'client')->postJson('/api/client/email/verification-notification')->assertOk();
            clSpa()->actingAs($b, 'client')->postJson('/api/client/email/verification-notification')->assertOk();
        });
    });

    describe('middleware client.verified', function () {
        beforeEach(function () {
            Route::middleware(['api', 'auth:client', 'client.verified'])->get('api/client/_test/verified', fn () => ['ok' => true]);
        });

        it('answers 403 email_not_verified for an unverified client', function () {
            clSpa()->actingAs(clUser(['email_verified_at' => null]), 'client')->getJson('/api/client/_test/verified')
                ->assertStatus(403)->assertJsonPath('code', 'email_not_verified');
        });

        it('lets a verified client through', function () {
            clSpa()->actingAs(clUser(), 'client')->getJson('/api/client/_test/verified')->assertOk();
        });

        it('lets verification itself, /me and the resend work for an unverified client', function () {
            $user = clUser(['email_verified_at' => null]);

            clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->assertOk()->assertJsonPath('data.user.email_verified', false);
        });
    });
});

describe('POST /login', function () {
    beforeEach(fn () => Notification::fake());

    it('signs in and returns the same shape as /me', function () {
        $user = clUser(['password' => CL_PASSWORD, 'email' => 'rahim@uddintraders.com']);

        $response = clSpa()->postJson('/api/client/login', ['email' => 'Rahim@UddinTraders.com', 'password' => CL_PASSWORD, 'remember' => true])->assertOk();

        expect(Auth::guard('client')->check())->toBeTrue()
            ->and($response->json('data'))->toBe(clSpa()->getJson('/api/client/me')->json('data'))
            ->and($response->json('data.user.id'))->toBe((string) $user->id);
    });

    it('records the sign-in and clears earlier failures', function () {
        $user = clUser(['password' => CL_PASSWORD, 'failed_login_attempts' => 3]);

        clLogin($user->email, CL_PASSWORD)->assertOk();

        $user->refresh();
        $history = ClientLoginHistory::sole();
        expect($user->failed_login_attempts)->toBe(0)->and($user->locked_until)->toBeNull()
            ->and($user->last_login_at)->not->toBeNull()->and($user->last_login_ip)->toBe('127.0.0.1')
            ->and($history->client_user_id)->toBe($user->id)->and($history->successful)->toBeTrue()
            ->and($history->browser)->toBe('Chrome')->and($history->platform)->toBe('Windows')
            ->and($history->impersonated_by_admin_id)->toBeNull();
    });

    it('opens on the account the person owns when none was remembered', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $user->forceFill(['last_account_id' => null])->save();

        clLogin($user->email, CL_PASSWORD)->assertOk();

        expect($user->fresh()->last_account_id)->toBe(ClientAccount::where('owner_user_id', $user->id)->value('id'));
    });

    it('regenerates the session id', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $this->startSession();
        $before = session()->getId();

        clLogin($user->email, CL_PASSWORD)->assertOk();

        expect(session()->getId())->not->toBe($before);
    });

    it('rejects a wrong password with a generic 422', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clLogin($user->email, 'wrong-password-123')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials')->assertJsonMissingPath('errors');

        $history = ClientLoginHistory::sole();
        expect(Auth::guard('client')->check())->toBeFalse()
            ->and($history->client_user_id)->toBe($user->id)->and($history->successful)->toBeFalse()->and($history->failure_reason)->toBe('invalid_credentials')
            ->and($user->fresh()->failed_login_attempts)->toBe(1);
    });

    it('answers an unknown email exactly like a wrong password', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        $unknown = clLogin('nobody@example.com', 'wrong-password-123');
        $wrong = clLogin($user->email, 'wrong-password-123');

        expect($unknown->status())->toBe(422)->and($unknown->json())->toBe($wrong->json())
            ->and(ClientLoginHistory::whereNull('client_user_id')->sole()->email_attempted)->toBe('nobody@example.com');
    });

    it('locks for 15 minutes after 5 failures (the 5th already answers 429)', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        foreach (range(1, 4) as $i) {
            clLogin($user->email, 'wrong-password-123')->assertStatus(422);
        }
        clLogin($user->email, 'wrong-password-123')
            ->assertStatus(429)->assertJsonPath('code', 'account_locked')->assertJsonPath('retry_after_minutes', 15)
            ->assertJsonPath('message', 'Too many failed sign-in attempts. Try again in 15 minutes.')->assertHeader('Retry-After');

        expect($user->fresh()->locked_until->isFuture())->toBeTrue();

        clLogin($user->email, CL_PASSWORD)->assertStatus(429)->assertJsonPath('code', 'account_locked'); // even the right password
        expect(Auth::guard('client')->check())->toBeFalse()->and(ClientLoginHistory::where('failure_reason', 'locked')->count())->toBe(1);

        $this->travel(16)->minutes();
        clLogin($user->email, CL_PASSWORD)->assertOk();
    });

    it('locks an unknown email the same way', function () {
        foreach (range(1, 4) as $i) {
            clLogin('ghost@example.com', 'wrong-password-123')->assertStatus(422);
        }
        clLogin('ghost@example.com', 'wrong-password-123')->assertStatus(429)->assertJsonPath('code', 'account_locked');
    });

    it('counts attempts per email and IP', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        foreach (range(1, 4) as $i) {
            clLogin($user->email, 'wrong-password-123');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
        clLogin($user->email, 'wrong-password-123')->assertStatus(422);
    });

    it('refuses a suspended client with 403, and does not give the suspension away to a wrong password', function () {
        $user = tap(clUser(['password' => CL_PASSWORD]), fn ($u) => $u->forceFill(['status' => 'suspended'])->save());

        clLogin($user->email, 'wrong-password-123')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        clLogin($user->email, CL_PASSWORD)->assertStatus(403)->assertJsonPath('code', 'account_suspended');

        expect(Auth::guard('client')->check())->toBeFalse()
            ->and(ClientLoginHistory::where('failure_reason', 'suspended')->count())->toBe(1)
            ->and($user->fresh()->last_login_at)->toBeNull();
    });

    it('cannot be used with an admin\'s credentials', function () {
        $admin = Admin::factory()->create(['password' => CL_PASSWORD]);

        clLogin($admin->email, CL_PASSWORD)->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
    });

    it('does not sign in a deleted client', function () {
        $user = tap(clUser(['password' => CL_PASSWORD]))->delete();

        clLogin($user->email, CL_PASSWORD)->assertStatus(422);
    });

    it('validates the body', function () {
        clSpa()->postJson('/api/client/login', ['email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    });

    describe('new device alert', function () {
        it('queues an email the first time a browser and system are seen, not the second time', function () {
            $user = clUser(['password' => CL_PASSWORD]);

            clLogin($user->email, CL_PASSWORD)->assertOk();
            Notification::assertSentToTimes($user, ClientNewSignInNotification::class, 1);

            Auth::guard('client')->logout();
            clLogin($user->email, CL_PASSWORD)->assertOk();
            Notification::assertSentToTimes($user, ClientNewSignInNotification::class, 1);
        });

        it('alerts again for a different browser or system', function () {
            $user = clUser(['password' => CL_PASSWORD]);
            clLogin($user->email, CL_PASSWORD, CL_CHROME_WINDOWS)->assertOk();
            Auth::guard('client')->logout();

            clLogin($user->email, CL_PASSWORD, CL_FIREFOX_LINUX)->assertOk();

            Notification::assertSentToTimes($user, ClientNewSignInNotification::class, 2);
        });

        it('does not count a failed attempt as a known device', function () {
            $user = clUser(['password' => CL_PASSWORD]);
            clLogin($user->email, 'wrong-password-123', CL_FIREFOX_LINUX)->assertStatus(422);

            clLogin($user->email, CL_PASSWORD, CL_FIREFOX_LINUX)->assertOk();

            Notification::assertSentToTimes($user, ClientNewSignInNotification::class, 1);
        });

        it('does not alert after registering on the same device', function () {
            clRegister()->assertCreated();
            Auth::guard('client')->logout();

            clLogin('rahim@uddintraders.com', CL_PASSWORD)->assertOk();

            Notification::assertNotSentTo(ClientUser::sole(), ClientNewSignInNotification::class);
        });

        it('is queued', function () {
            expect(new ClientNewSignInNotification(now(), null, 'x', null))->toBeInstanceOf(ShouldQueue::class);
        });

        it('tells the time, the device and an unknown location, and links to changing the password', function () {
            $user = clUser(['password' => CL_PASSWORD, 'name' => 'Rahim Uddin']);
            clLogin($user->email, CL_PASSWORD)->assertOk();

            Notification::assertSentTo($user, ClientNewSignInNotification::class, function ($notification) use ($user) {
                $mail = $notification->toMail($user);
                $text = implode("\n", $mail->introLines);

                expect($mail->subject)->toBe('New sign-in to your EverDigitech account')
                    ->and($text)->toContain('Chrome on Windows')->toContain('Unknown location')->toContain('(IP 127.0.0.1)')->toContain('Bangladesh time')
                    ->and($mail->actionUrl)->toBe('http://localhost:3002/forgot-password');

                return true;
            });
        });

        it('shows the place when a GeoIP lookup is bound', function () {
            app()->bind(GeoIpLookup::class, fn () => new class implements GeoIpLookup
            {
                public function lookup(string $ip): ?string
                {
                    return 'Dhaka, Bangladesh';
                }
            });
            $user = clUser(['password' => CL_PASSWORD]);
            clLogin($user->email, CL_PASSWORD)->assertOk();

            Notification::assertSentTo($user, ClientNewSignInNotification::class, fn ($n) => str_contains(implode("\n", $n->toMail($user)->introLines), 'Dhaka, Bangladesh'));
        });
    });
});

describe('POST /logout', function () {
    beforeEach(fn () => Notification::fake());

    it('signs out the client guard and keeps an admin signed in on the same browser', function () {
        $admin = Admin::factory()->create();
        $client = clUser();

        $this->actingAs($admin, 'admin')->actingAs($client, 'client');
        $this->withSession(['marker' => 'kept', 'login_admin_abc' => $admin->id]);
        $token = csrf_token();

        clSpa()->postJson('/api/client/logout')->assertNoContent();

        expect(Auth::guard('client')->check())->toBeFalse()
            ->and(Auth::guard('admin')->check())->toBeTrue()
            ->and(session('marker'))->toBe('kept')
            ->and(session('login_admin_abc'))->toBe($admin->id)
            ->and(csrf_token())->not->toBe($token);
    });

    it('does not flush the session', function () {
        $this->withSession(['login_admin_abc' => 7, 'marker' => 'kept']);

        clSpa()->postJson('/api/client/logout')->assertNoContent();

        expect(session('login_admin_abc'))->toBe(7)->and(session('marker'))->toBe('kept');
    });

    it('is harmless when nobody is signed in', function () {
        clSpa()->postJson('/api/client/logout')->assertNoContent();
    });

    it('leaves the admin API working for an admin after a client signs out', function () {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->actingAs(clUser(), 'client');

        clSpa()->postJson('/api/client/logout')->assertNoContent();

        test()->withHeaders(['Origin' => 'http://localhost:3003'])->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.user.id', (string) $admin->id);
    });
});

describe('a client suspended while signed in', function () {
    it('is signed out and refused on the next request', function () {
        $user = clUser();
        $this->actingAs($user, 'client');
        $user->forceFill(['status' => 'suspended'])->save();

        clSpa()->getJson('/api/client/me')->assertStatus(403)->assertJsonPath('code', 'account_suspended');

        expect(Auth::guard('client')->check())->toBeFalse();
    });
});

describe('GET /me', function () {
    it('answers 401 without a sign-in', function () {
        clSpa()->getJson('/api/client/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    });

    it('has the documented shape', function () {
        $user = clUser(['name' => 'Rahim Uddin', 'email' => 'rahim@uddintraders.com', 'phone' => '+8801711223344']);
        $account = $user->lastAccount;

        $data = clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->assertOk()->json('data');

        expect($data)->toBe([
            'user' => [
                'id' => (string) $user->id, 'name' => 'Rahim Uddin', 'email' => 'rahim@uddintraders.com', 'phone' => '+8801711223344',
                'email_verified' => true, 'phone_verified' => false, 'two_factor_enabled' => false, 'avatar_url' => null,
            ],
            'accounts' => [
                ['id' => (string) $account->id, 'name' => $account->name, 'role' => 'owner', 'permissions' => ['*'], 'is_owner' => true],
            ],
            'current_account_id' => (string) $account->id,
            'impersonation' => null,
        ]);
    });

    it('reports the verified flags', function () {
        $user = clUser(['email_verified_at' => null, 'phone_verified_at' => now()]);

        clSpa()->actingAs($user, 'client')->getJson('/api/client/me')
            ->assertJsonPath('data.user.email_verified', false)->assertJsonPath('data.user.phone_verified', true);
    });

    it('lists every account the person can work in, with their role and permissions', function () {
        $user = clUser();
        $other = ClientAccount::factory()->create(['name' => 'Karim Traders']);
        AccountMember::factory()->create(['account_id' => $other->id, 'user_id' => $user->id, 'permissions' => ['invoices.view', 'hosting.view']]);

        $accounts = clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->json('data.accounts');

        expect($accounts)->toHaveCount(2)
            ->and($accounts[1])->toBe(['id' => (string) $other->id, 'name' => 'Karim Traders', 'role' => 'member', 'permissions' => ['invoices.view', 'hosting.view'], 'is_owner' => false]);
    });

    it('leaves out accounts where the membership is not active, or the account is gone', function () {
        $user = clUser();
        $invited = ClientAccount::factory()->create();
        AccountMember::factory()->invited()->create(['account_id' => $invited->id, 'user_id' => $user->id]);
        $suspended = ClientAccount::factory()->create();
        AccountMember::factory()->create(['account_id' => $suspended->id, 'user_id' => $user->id, 'status' => MemberStatus::Suspended]);
        $deleted = ClientAccount::factory()->create();
        AccountMember::factory()->create(['account_id' => $deleted->id, 'user_id' => $user->id]);
        $deleted->delete();

        $accounts = clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->json('data.accounts');

        expect($accounts)->toHaveCount(1);
    });

    it('gives a member with no permissions an empty list', function () {
        $user = ClientUser::factory()->create();
        AccountMember::factory()->create(['account_id' => ClientAccount::factory()->create()->id, 'user_id' => $user->id, 'permissions' => null]);

        clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->assertJsonPath('data.accounts.0.permissions', []);
    });

    it('picks the current account: the last used, else the owned one', function () {
        $user = clUser();
        $owned = $user->lastAccount;
        $other = ClientAccount::factory()->create();
        AccountMember::factory()->create(['account_id' => $other->id, 'user_id' => $user->id]);
        $me = fn () => clSpa()->actingAs($user->fresh(), 'client')->getJson('/api/client/me')->json('data.current_account_id');

        expect($me())->toBe((string) $owned->id);

        $user->forceFill(['last_account_id' => $other->id])->save();
        expect($me())->toBe((string) $other->id);

        // The remembered account is no longer one they can use: back to their own.
        AccountMember::where('account_id', $other->id)->where('user_id', $user->id)->update(['status' => 'suspended']);
        expect($me())->toBe((string) $owned->id);
    });

    it('has no current account for a person who belongs to none', function () {
        $user = ClientUser::factory()->create();

        clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->assertOk()->assertJsonPath('data.current_account_id', null)->assertJsonPath('data.accounts', []);
    });

    it('shows who is signed in as the client when a staff member is impersonating', function () {
        $admin = Admin::factory()->create(['name' => 'Nusrat Jahan']);
        $user = clUser();

        $this->withSession(['impersonated_by_admin_id' => $admin->id, 'impersonation_started_at' => now()->getTimestamp()]);

        clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->assertJsonPath('data.impersonation.admin_name', 'Nusrat Jahan');
    });

    it('never exposes secrets', function () {
        $user = clUser(['two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $json = clSpa()->actingAs($user, 'client')->getJson('/api/client/me')->getContent();

        expect($json)->not->toContain('password')->not->toContain('remember_token')->not->toContain('two_factor_secret')->not->toContain('recovery')->not->toContain('JBSWY3DPEHPK3PXP');
    });
});

describe('PUT /current-account', function () {
    it('remembers the account and returns the new /me', function () {
        $user = clUser();
        $other = ClientAccount::factory()->create();
        AccountMember::factory()->create(['account_id' => $other->id, 'user_id' => $user->id]);

        clSpa()->actingAs($user, 'client')->putJson('/api/client/current-account', ['account_id' => $other->id])
            ->assertOk()->assertJsonPath('data.current_account_id', (string) $other->id);

        expect($user->fresh()->last_account_id)->toBe($other->id);
    });

    it('refuses an account the person does not belong to', function () {
        $user = clUser();
        $stranger = ClientAccount::factory()->create();
        $before = $user->last_account_id;

        clSpa()->actingAs($user, 'client')->putJson('/api/client/current-account', ['account_id' => $stranger->id])
            ->assertStatus(403)->assertJsonPath('code', 'account_access_denied');

        expect($user->fresh()->last_account_id)->toBe($before);
    });

    it('refuses an account where the membership is not active', function () {
        $user = clUser();
        $invited = ClientAccount::factory()->create();
        AccountMember::factory()->invited()->create(['account_id' => $invited->id, 'user_id' => $user->id]);

        clSpa()->actingAs($user, 'client')->putJson('/api/client/current-account', ['account_id' => $invited->id])->assertStatus(403);
    });

    it('validates the body', function () {
        clSpa()->actingAs(clUser(), 'client')->putJson('/api/client/current-account', [])->assertStatus(422)->assertJsonValidationErrors('account_id');
    });

    it('needs a sign-in', function () {
        clSpa()->putJson('/api/client/current-account', ['account_id' => 1])->assertStatus(401);
    });
});

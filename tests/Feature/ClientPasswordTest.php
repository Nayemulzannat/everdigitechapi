<?php

use App\Models\Admin;
use App\Models\ClientSession;
use App\Models\ClientUser;
use App\Notifications\ClientPasswordChangedNotification;
use App\Notifications\ClientResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

const CL_NEW_PASSWORD = 'Another-Strong-Pass-42';

describe('POST /forgot-password', function () {
    beforeEach(fn () => Notification::fake());

    it('sends a reset link to a client, and nothing to an unknown address, with the same answer', function () {
        $user = clUser();

        $real = clSpa()->postJson('/api/client/forgot-password', ['email' => $user->email]);
        $unknown = clSpa()->postJson('/api/client/forgot-password', ['email' => 'nobody@example.com']);

        expect($real->status())->toBe(200)->and($unknown->status())->toBe(200)->and($real->json())->toBe($unknown->json())
            ->and($real->json('message'))->toBe('If that email belongs to an account, we have sent a password reset link.');
        Notification::assertSentTo($user, ClientResetPasswordNotification::class);
        Notification::assertCount(1);
    });

    it('puts a link to the client panel in the email: /reset-password?token=..&email=..', function () {
        $user = clUser(['email' => 'rahim@uddintraders.com']);

        $mail = (new ClientResetPasswordNotification('tok123'))->toMail($user);

        expect($mail->actionUrl)->toBe('http://localhost:3002/reset-password?token=tok123&email=rahim%40uddintraders.com')
            ->and($mail->subject)->toBe('Reset your EverDigitech password')
            ->and(implode(' ', $mail->outroLines))->toContain('60 minutes');
        expect(new ClientResetPasswordNotification('x'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('does not mix with the staff reset: a staff email gets nothing, and the token lives in its own table', function () {
        $admin = Admin::factory()->create();
        $user = clUser();

        clSpa()->postJson('/api/client/forgot-password', ['email' => $admin->email])->assertOk();
        clSpa()->postJson('/api/client/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertNotSentTo($admin, ClientResetPasswordNotification::class);
        expect(DB::table('client_user_password_reset_tokens')->where('email', $user->email)->count())->toBe(1)
            ->and(DB::table('admin_password_reset_tokens')->count())->toBe(0);
    });

    it('allows 3 requests per email per hour, then answers 429', function () {
        $user = clUser();
        $ask = fn (string $email) => clSpa()->postJson('/api/client/forgot-password', ['email' => $email]);

        foreach (range(1, 3) as $i) {
            $ask($user->email)->assertOk();
            $this->travel(61)->seconds(); // the broker itself only sends one link a minute
        }

        $ask($user->email)->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
        $ask(strtoupper($user->email))->assertStatus(429); // same address in another spelling
        Notification::assertSentToTimes($user, ClientResetPasswordNotification::class, 3);

        $ask(clUser()->email)->assertOk(); // another address is unaffected

        $this->travel(60)->minutes();
        $ask($user->email)->assertOk();
    });

    it('counts unknown addresses the same way, so the limit reveals nothing', function () {
        foreach (range(1, 3) as $i) {
            clSpa()->postJson('/api/client/forgot-password', ['email' => 'ghost@example.com'])->assertOk();
        }

        clSpa()->postJson('/api/client/forgot-password', ['email' => 'ghost@example.com'])->assertStatus(429);
    });

    it('validates the email', function () {
        clSpa()->postJson('/api/client/forgot-password', ['email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('email');
        clSpa()->postJson('/api/client/forgot-password', [])->assertStatus(422);
    });
});

describe('POST /reset-password', function () {
    beforeEach(fn () => Notification::fake());

    function clResetBody(ClientUser $user, string $token, array $over = []): array
    {
        return ['token' => $token, 'email' => $user->email, 'password' => CL_NEW_PASSWORD, 'password_confirmation' => CL_NEW_PASSWORD, ...$over];
    }

    it('sets the new password, which then signs in', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $token = Password::broker('client_users')->createToken($user);

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertOk()->assertJsonPath('message', 'Your password has been changed. You can sign in now.');

        expect(Hash::check(CL_NEW_PASSWORD, $user->fresh()->password))->toBeTrue()->and(Auth::guard('client')->check())->toBeFalse();
        clLogin($user->email, CL_NEW_PASSWORD)->assertOk();
        clLogin($user->email, CL_PASSWORD)->assertStatus(422);
    });

    it('signs out every other session of that person', function () {
        $user = clUser(['password' => CL_PASSWORD, 'remember_token' => 'old-remember-token']);
        $phone = clOtherDevice($user, 'Chrome', 'Android');
        $laptop = clOtherDevice($user, 'Edge', 'Windows');
        $someoneElse = clOtherDevice(clUser());
        $token = Password::broker('client_users')->createToken($user);

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertOk();

        expect(clDeviceAlive($phone))->toBeFalse()->and(clDeviceAlive($laptop))->toBeFalse()
            ->and(ClientSession::where('client_user_id', $user->id)->count())->toBe(0)
            ->and(clDeviceAlive($someoneElse))->toBeTrue()->and(ClientSession::count())->toBe(1)
            ->and($user->fresh()->remember_token)->not->toBe('old-remember-token');
    });

    it('clears the failed attempts and the lock, so the person can sign in at once', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        foreach (range(1, 5) as $i) {
            clLogin($user->email, 'wrong-password-123');
        }
        expect($user->fresh()->locked_until)->not->toBeNull();
        clLogin($user->email, CL_PASSWORD)->assertStatus(429);

        $token = Password::broker('client_users')->createToken($user);
        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertOk();

        $fresh = $user->fresh();
        expect($fresh->failed_login_attempts)->toBe(0)->and($fresh->locked_until)->toBeNull();
        clLogin($user->email, CL_NEW_PASSWORD)->assertOk();
    });

    it('emails "Your password was changed"', function () {
        $user = clUser();
        $token = Password::broker('client_users')->createToken($user);

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertOk();

        Notification::assertSentTo($user, ClientPasswordChangedNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Your EverDigitech password was changed'
                && str_contains(implode(' ', $mail->introLines), 'signed out of your other devices')
                && str_contains(implode(' ', $mail->introLines), '127.0.0.1');
        });
        expect(new ClientPasswordChangedNotification(now()))->toBeInstanceOf(ShouldQueue::class);
    });

    it('works once only', function () {
        $user = clUser();
        $token = Password::broker('client_users')->createToken($user);
        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertOk();

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token, ['password' => 'Yet-Another-Pass-77', 'password_confirmation' => 'Yet-Another-Pass-77']))
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');

        expect(Hash::check(CL_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
    });

    it('expires after 60 minutes', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $token = Password::broker('client_users')->createToken($user);

        $this->travel(59)->minutes();
        expect(Password::broker('client_users')->tokenExists($user, $token))->toBeTrue();

        $this->travel(2)->minutes();
        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token))->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
        expect(Hash::check(CL_PASSWORD, $user->fresh()->password))->toBeTrue();
    });

    it('answers a wrong token and an unknown email the same way', function () {
        $user = clUser();
        Password::broker('client_users')->createToken($user);

        $wrong = clSpa()->postJson('/api/client/reset-password', clResetBody($user, 'wrong-token'))->assertStatus(422);
        $unknown = clSpa()->postJson('/api/client/reset-password', ['token' => 'x', 'email' => 'nobody@example.com', 'password' => CL_NEW_PASSWORD, 'password_confirmation' => CL_NEW_PASSWORD])->assertStatus(422);

        expect($unknown->json())->toBe($wrong->json())->and($wrong->json('code'))->toBe('invalid_reset_token');
    });

    it('does not accept a staff reset token for a client, or the reverse', function () {
        $user = clUser();
        $admin = Admin::factory()->create(['email' => $user->email === 'x' ? 'y' : 'admin-'.$user->email]);
        $adminToken = Password::broker('admins')->createToken($admin);

        clSpa()->postJson('/api/client/reset-password', ['token' => $adminToken, 'email' => $user->email, 'password' => CL_NEW_PASSWORD, 'password_confirmation' => CL_NEW_PASSWORD])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_reset_token');
    });

    it('enforces the password rules', function (string $password) {
        $user = clUser();
        $token = Password::broker('client_users')->createToken($user);

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token, ['password' => $password, 'password_confirmation' => $password]))
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('password');
    })->with(['too short' => 'Aa1-short', 'no upper case' => 'lowercase-only-123', 'no lower case' => 'UPPERCASE-ONLY-123', 'no number' => 'No-Numbers-In-Here']);

    it('needs the confirmation to match', function () {
        $user = clUser();
        $token = Password::broker('client_users')->createToken($user);

        clSpa()->postJson('/api/client/reset-password', clResetBody($user, $token, ['password_confirmation' => 'Different-Pass-9']))->assertStatus(422)->assertJsonValidationErrors('password');
    });
});

describe('PUT /password', function () {
    beforeEach(fn () => Notification::fake());

    function clChangeBody(array $over = []): array
    {
        return ['current_password' => CL_PASSWORD, 'password' => CL_NEW_PASSWORD, 'password_confirmation' => CL_NEW_PASSWORD, ...$over];
    }

    it('changes the password', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->putJson('/api/client/password', clChangeBody())->assertOk()->assertJsonPath('signed_out', 0);

        expect(Hash::check(CL_NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
    });

    it('signs out the other devices and keeps this one', function () {
        $user = clUser(['password' => CL_PASSWORD, 'remember_token' => 'old-remember-token']);
        $mine = Str::random(40);
        Session::driver()->getHandler()->write($mine, serialize(['marker' => 'mine']));
        $phone = clOtherDevice($user, 'Chrome', 'Android');
        $laptop = clOtherDevice($user, 'Edge', 'Windows');

        clAs($user, $mine)->getJson('/api/client/me')->assertOk(); // this browser gets tracked
        expect(ClientSession::where('client_user_id', $user->id)->count())->toBe(3);

        clAs($user, $mine)->putJson('/api/client/password', clChangeBody())->assertOk()
            ->assertJsonPath('signed_out', 2)->assertJsonPath('message', 'Your password was changed. You were signed out of 2 other devices.');

        expect(clDeviceAlive($phone))->toBeFalse()->and(clDeviceAlive($laptop))->toBeFalse()
            ->and(ClientSession::where('client_user_id', $user->id)->pluck('session_id')->all())->toBe([$mine])
            ->and($user->fresh()->remember_token)->not->toBe('old-remember-token');
    });

    it('emails "Your password was changed"', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->putJson('/api/client/password', clChangeBody())->assertOk();

        Notification::assertSentTo($user, ClientPasswordChangedNotification::class);
    });

    it('refuses a wrong current password and changes nothing', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        $other = clOtherDevice($user);

        clAs($user)->putJson('/api/client/password', clChangeBody(['current_password' => 'not-my-password']))
            ->assertStatus(422)->assertJsonValidationErrors('current_password')->assertJsonPath('errors.current_password.0', 'Your current password is incorrect.');

        expect(Hash::check(CL_PASSWORD, $user->fresh()->password))->toBeTrue()->and(clDeviceAlive($other))->toBeTrue();
        Notification::assertNothingSent();
    });

    it('refuses a new password that is the same as the current one', function () {
        clAs(clUser(['password' => CL_PASSWORD]))->putJson('/api/client/password', clChangeBody(['password' => CL_PASSWORD, 'password_confirmation' => CL_PASSWORD]))
            ->assertStatus(422)->assertJsonValidationErrors('password');
    });

    it('enforces the password rules and the confirmation', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->putJson('/api/client/password', clChangeBody(['password' => 'weak', 'password_confirmation' => 'weak']))->assertStatus(422)->assertJsonValidationErrors('password');
        clAs($user)->putJson('/api/client/password', clChangeBody(['password_confirmation' => 'Different-Pass-9']))->assertStatus(422)->assertJsonValidationErrors('password');
        clAs($user)->putJson('/api/client/password', [])->assertStatus(422)->assertJsonValidationErrors(['current_password', 'password']);
    });

    it('needs a sign-in', function () {
        clSpa()->putJson('/api/client/password', clChangeBody())->assertStatus(401);
    });

    it('limits guesses at the current password to 5 a minute', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        foreach (range(1, 5) as $i) {
            clAs($user)->putJson('/api/client/password', clChangeBody(['current_password' => 'wrong-guess-'.$i]))->assertStatus(422);
        }

        clAs($user)->putJson('/api/client/password', clChangeBody())->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect(Hash::check(CL_PASSWORD, $user->fresh()->password))->toBeTrue();
    });
});

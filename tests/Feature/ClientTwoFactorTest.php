<?php

use App\Models\ActivityLog;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Notifications\ClientTwoFactorChangedNotification;
use App\Services\TwoFactor;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function twoFactorUser(array $attrs = []): ClientUser
{
    $user = clUser(['password' => CL_PASSWORD, ...$attrs]);
    $secret = app(TwoFactor::class)->newSecret();
    $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
    $codes = app(TwoFactor::class)->newRecoveryCodes($user);

    $GLOBALS['twoFactorRecoveryCodes'][$user->id] = $codes;

    return $user->fresh();
}

/** The recovery codes twoFactorUser() made for a user (the database keeps only their hashes). */
function recoveryOf(ClientUser $user): array
{
    return $GLOBALS['twoFactorRecoveryCodes'][$user->id];
}

function totp(ClientUser $user, int $offsetSeconds = 0): string
{
    return app(TwoFactor::class)->code($user->two_factor_secret, time() + $offsetSeconds);
}

beforeEach(fn () => $this->seed(RoleSeeder::class));

describe('the authenticator code', function () {
    it('matches the RFC 6238 test vector', function () {
        // RFC 6238 appendix B, SHA-1, secret "12345678901234567890", 6 digits: time 59 -> 287082, 1111111109 -> 081804.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        expect(app(TwoFactor::class)->code($secret, 59))->toBe('287082')->and(app(TwoFactor::class)->code($secret, 1111111109))->toBe('081804');
    });

    it('accepts the code of the current moment and one step either side, and never twice', function () {
        $user = twoFactorUser();
        $service = app(TwoFactor::class);

        expect($service->verify($user, totp($user, -30)))->toBeTrue()
            ->and($service->verify($user, totp($user, -30)))->toBeFalse() // used already
            ->and($service->verify($user, totp($user, 0)))->toBeTrue()
            ->and($service->verify($user, totp($user, 120)))->toBeFalse() // too far away
            ->and($service->verify($user, '12345'))->toBeFalse()
            ->and($service->verify($user, 'abcdef'))->toBeFalse();
    });
});

describe('turning it on', function () {
    it('needs the password, gives a secret and a link, and is not on until a code proves it works', function () {
        Notification::fake();
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->postJson('/api/client/two-factor/setup', ['password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('password');

        $setup = clAs($user)->postJson('/api/client/two-factor/setup', ['password' => CL_PASSWORD])->assertOk()->json('data');
        expect($setup['secret'])->toMatch('/^[A-Z2-7]{32}$/')
            ->and($setup['otpauth_url'])->toStartWith('otpauth://totp/')->toContain('secret='.$setup['secret'])->toContain(rawurlencode($user->email));
        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

        clAs($user)->getJson('/api/client/two-factor')->assertOk()->assertJsonPath('data', ['enabled' => false, 'recovery_codes_left' => 0]);
        clAs($user)->postJson('/api/client/two-factor/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

        $codes = clAs($user)->postJson('/api/client/two-factor/confirm', ['code' => totp($user->fresh())])->assertOk()->json('data.recovery_codes');
        expect($codes)->toHaveCount(8)->and($codes[0])->toMatch('/^[0-9a-f]{5}-[0-9a-f]{5}$/')->and($user->fresh()->hasTwoFactorEnabled())->toBeTrue();
        Notification::assertSentTo($user, ClientTwoFactorChangedNotification::class, fn ($n) => $n->enabled === true);
        expect(ActivityLog::where('action', 'client.two_factor_enabled')->count())->toBe(1);

        clAs($user)->getJson('/api/client/me')->assertJsonPath('data.user.two_factor_enabled', true);
        clAs($user)->getJson('/api/client/two-factor')->assertJsonPath('data', ['enabled' => true, 'recovery_codes_left' => 8]);
    });

    it('stores the secret and the codes encrypted, and only hashes of the codes', function () {
        $user = twoFactorUser();
        $row = DB::table('client_users')->where('id', $user->id)->first();

        expect($row->two_factor_secret)->not->toContain($user->two_factor_secret)->and($row->two_factor_recovery_codes)->not->toContain(substr(recoveryOf($user)[0], 0, 5))
            ->and(collect($user->two_factor_recovery_codes)->every(fn ($c) => strlen($c) === 64))->toBeTrue();
    });

    it('cannot be set up again while it is on, nor confirmed without a setup', function () {
        $on = twoFactorUser();
        clAs($on)->postJson('/api/client/two-factor/setup', ['password' => CL_PASSWORD])->assertStatus(422)->assertJsonPath('code', 'two_factor_already_on');
    });

    it('cannot be confirmed without a setup', function () {
        $fresh = clUser(['password' => CL_PASSWORD]);

        clAs($fresh)->postJson('/api/client/two-factor/confirm', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'two_factor_not_started');
    });

    it('is refused for a visitor', function () {
        clSpa()->postJson('/api/client/two-factor/setup', ['password' => 'x'])->assertStatus(401);
    });

    it('is refused while staff are signed in as the client', function () {
        $user = clUser(['password' => CL_PASSWORD]);
        test()->withSession(['impersonated_by_admin_id' => superAdmin()->id, 'impersonation_started_at' => now()->getTimestamp()]);

        clSpa()->actingAs($user, 'client')->postJson('/api/client/two-factor/setup', ['password' => CL_PASSWORD])->assertStatus(403)->assertJsonPath('code', 'not_allowed_while_impersonating');
    });
});

describe('signing in with it on', function () {
    it('answers 202 after the password and signs nobody in until the code is right', function () {
        $user = twoFactorUser(['email' => 'rahim@uddintraders.com']);

        clLogin($user->email, CL_PASSWORD)->assertStatus(202)->assertJsonPath('two_factor_required', true);
        $user = $user->fresh();

        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => totp($user)])->assertOk()->assertJsonPath('data.user.email', $user->email);

        expect(ClientLoginHistory::where('client_user_id', $user->id)->where('successful', true)->count())->toBe(1)
            ->and(ClientLoginHistory::where('client_user_id', $user->id)->where('failure_reason', 'two_factor_failed')->count())->toBe(1)
            ->and($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('a wrong password never reaches the code step', function () {
        $user = twoFactorUser();

        clLogin($user->email, 'Not-The-Password-1')->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => totp($user)])->assertStatus(422)->assertJsonPath('code', 'two_factor_expired');
    });

    it('works with a recovery code, once', function () {
        $user = twoFactorUser();

        clLogin($user->email, CL_PASSWORD)->assertStatus(202);
        clSpa()->postJson('/api/client/two-factor-challenge', ['recovery_code' => strtoupper(recoveryOf($user)[0])])->assertOk();
        expect(app(TwoFactor::class)->recoveryCodesLeft($user->fresh()))->toBe(7);
    });

    it('does not take a recovery code twice', function () {
        $user = twoFactorUser();
        app(TwoFactor::class)->useRecoveryCode($user, recoveryOf($user)[0]);

        clLogin($user->email, CL_PASSWORD)->assertStatus(202);
        clSpa()->postJson('/api/client/two-factor-challenge', ['recovery_code' => recoveryOf($user)[0]])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
    });

    it('ends the attempt after 5 wrong codes', function () {
        $user = twoFactorUser();
        clLogin($user->email, CL_PASSWORD)->assertStatus(202);

        foreach (range(1, 4) as $i) {
            clSpa()->postJson('/api/client/two-factor-challenge', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
        }
        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'two_factor_expired');
        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => totp($user)])->assertStatus(422)->assertJsonPath('code', 'two_factor_expired');
    });

    it('ends the attempt after 5 minutes', function () {
        $user = twoFactorUser();
        clLogin($user->email, CL_PASSWORD)->assertStatus(202);

        $this->travel(6)->minutes();
        clSpa()->postJson('/api/client/two-factor-challenge', ['code' => totp($user->fresh())])->assertStatus(422)->assertJsonPath('code', 'two_factor_expired');
    });

    it('asks for a code or a recovery code', function () {
        $user = twoFactorUser();
        clLogin($user->email, CL_PASSWORD)->assertStatus(202);

        clSpa()->postJson('/api/client/two-factor-challenge', [])->assertStatus(422)->assertJsonValidationErrors(['code', 'recovery_code']);
    });

    it('does not ask a person without it', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clLogin($user->email, CL_PASSWORD)->assertOk()->assertJsonPath('data.user.two_factor_enabled', false);
    });
});

describe('recovery codes and turning it off', function () {
    it('makes new recovery codes (the old ones stop working) for the password', function () {
        $user = twoFactorUser();

        clAs($user)->postJson('/api/client/two-factor/recovery-codes', ['password' => 'wrong'])->assertStatus(422);
        $new = clAs($user)->postJson('/api/client/two-factor/recovery-codes', ['password' => CL_PASSWORD])->assertOk()->json('data.recovery_codes');

        expect($new)->toHaveCount(8)->and($new)->not->toContain(recoveryOf($user)[0])
            ->and(app(TwoFactor::class)->useRecoveryCode($user->fresh(), recoveryOf($user)[0]))->toBeFalse()
            ->and(app(TwoFactor::class)->useRecoveryCode($user->fresh(), $new[0]))->toBeTrue();
    });

    it('turns off with the password and a code, and tells the owner', function () {
        Notification::fake();
        $user = twoFactorUser();

        clAs($user)->deleteJson('/api/client/two-factor', ['password' => CL_PASSWORD, 'code' => '000000'])->assertStatus(422)->assertJsonPath('code', 'invalid_two_factor_code');
        clAs($user)->deleteJson('/api/client/two-factor', ['password' => 'wrong', 'code' => totp($user)])->assertStatus(422)->assertJsonValidationErrors('password');
        expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

        clAs($user)->deleteJson('/api/client/two-factor', ['password' => CL_PASSWORD, 'code' => totp($user)])->assertOk();

        $fresh = $user->fresh();
        expect($fresh->hasTwoFactorEnabled())->toBeFalse()->and($fresh->two_factor_secret)->toBeNull()->and($fresh->two_factor_recovery_codes)->toBeNull();
        Notification::assertSentTo($user, ClientTwoFactorChangedNotification::class, fn ($n) => $n->enabled === false);
        expect(ActivityLog::where('action', 'client.two_factor_disabled')->count())->toBe(1);
    });

    it('can sign in without a code after it was turned off', function () {
        $user = twoFactorUser();
        clAs($user)->deleteJson('/api/client/two-factor', ['password' => CL_PASSWORD, 'code' => recoveryOf($user)[0]])->assertOk();

        clLogin($user->email, CL_PASSWORD)->assertOk();
    });

    it('turns off with a recovery code when the phone is lost', function () {
        $user = twoFactorUser();

        clAs($user)->deleteJson('/api/client/two-factor', ['password' => CL_PASSWORD, 'code' => recoveryOf($user)[2]])->assertOk();
        expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
    });

    it('says so when it is not on', function () {
        $user = clUser(['password' => CL_PASSWORD]);

        clAs($user)->deleteJson('/api/client/two-factor', ['password' => CL_PASSWORD, 'code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'two_factor_not_on');
        clAs($user)->postJson('/api/client/two-factor/recovery-codes', ['password' => CL_PASSWORD])->assertStatus(422)->assertJsonPath('code', 'two_factor_not_on');
    });

    it('the email says on or off, and from where', function () {
        $user = clUser(['name' => 'Rahim Uddin']);
        $mail = (new ClientTwoFactorChangedNotification(true, now(), '1.2.3.4'))->toMail($user);

        expect($mail->subject)->toContain('turned on')->and(implode(' ', $mail->introLines))->toContain('1.2.3.4');
    });
});

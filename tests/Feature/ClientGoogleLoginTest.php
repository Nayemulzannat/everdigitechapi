<?php

use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\ClientUser;
use App\Notifications\ClientNewSignInNotification;
use App\Notifications\ClientResetPasswordNotification;
use App\Notifications\ClientVerifyEmailNotification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

const GOOGLE_CLIENT = 'test-client.apps.googleusercontent.com';

function googleClaims(array $over = []): array
{
    return [
        'iss' => 'https://accounts.google.com', 'aud' => GOOGLE_CLIENT, 'sub' => '1098765432101234567',
        'email' => 'rahim@uddintraders.com', 'email_verified' => 'true', 'name' => 'Rahim Uddin',
        'exp' => (string) now()->addHour()->getTimestamp(), ...$over,
    ];
}

function googleLogin(array $claims = [], int $status = 200, string $ua = CL_CHROME_WINDOWS)
{
    Http::fake(['oauth2.googleapis.com/*' => Http::response(googleClaims($claims), $status)]);

    return clSpa($ua)->postJson('/api/client/google', ['credential' => 'a.google.token']);
}

beforeEach(function () {
    config(['services.google.client_id' => GOOGLE_CLIENT]);
    Notification::fake();
});

it('signs a new person up with Google: client, account, verified email, signed in', function () {
    googleLogin()->assertCreated()
        ->assertJsonPath('data.user.email', 'rahim@uddintraders.com')->assertJsonPath('data.user.name', 'Rahim Uddin')
        ->assertJsonPath('data.user.email_verified', true)->assertJsonPath('data.accounts.0.role', 'owner');

    $user = ClientUser::sole();
    expect($user->google_id)->toBe('1098765432101234567')->and($user->email_verified_at)->not->toBeNull()
        ->and($user->phone)->toBeNull()->and(ClientAccount::where('owner_user_id', $user->id)->count())->toBe(1)
        ->and($user->last_account_id)->not->toBeNull()
        ->and(ClientLoginHistory::where('client_user_id', $user->id)->sole()->successful)->toBeTrue();
    Notification::assertNothingSent(); // no verification email (Google verified it) and no new-device alert for a new account
});

it('sends the token to Google and nothing else', function () {
    googleLogin();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://oauth2.googleapis.com/tokeninfo') && $request['id_token'] === 'a.google.token');
});

it('signs the same Google account in again, even if the email on the Google account changed', function () {
    googleLogin()->assertCreated();

    googleLogin(['email' => 'rahim.new@gmail.com'])->assertOk()->assertJsonPath('data.user.email', 'rahim@uddintraders.com');

    expect(ClientUser::count())->toBe(1)->and(ClientAccount::count())->toBe(1);
});

it('links Google to an existing client with the same verified email, keeping the password', function () {
    $user = clUser(['email' => 'rahim@uddintraders.com', 'password' => CL_PASSWORD]);

    googleLogin()->assertOk()->assertJsonPath('data.user.id', (string) $user->id);

    expect($user->fresh()->google_id)->toBe('1098765432101234567')->and(Hash::check(CL_PASSWORD, $user->fresh()->password))->toBeTrue()
        ->and(ClientUser::count())->toBe(1);
});

it('takes the password away when the existing email was never confirmed (no takeover of an address someone else registered)', function () {
    $user = clUser(['email' => 'rahim@uddintraders.com', 'password' => CL_PASSWORD, 'email_verified_at' => null]);
    $otherDevice = clOtherDevice($user);

    googleLogin()->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check(CL_PASSWORD, $fresh->password))->toBeFalse()->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->google_id)->not->toBeNull()->and(clDeviceAlive($otherDevice))->toBeFalse();
});

it('does not link a Google account to a client already linked to another Google account', function () {
    $user = clUser(['email' => 'rahim@uddintraders.com']);
    ClientUser::whereKey($user->id)->update(['google_id' => 'another-google-id']);

    googleLogin()->assertStatus(409)->assertJsonPath('code', 'google_account_mismatch');

    expect($user->fresh()->google_id)->toBe('another-google-id');
});

it('sends the new sign-in alert from an unknown browser, not from a known one', function () {
    $user = clUser(['email' => 'rahim@uddintraders.com']);
    ClientLoginHistory::create(['client_user_id' => $user->id, 'email_attempted' => $user->email, 'successful' => true, 'browser' => 'Chrome', 'platform' => 'Windows']);

    googleLogin()->assertOk();
    Notification::assertNothingSent();

    googleLogin(ua: CL_FIREFOX_LINUX)->assertOk();
    Notification::assertSentTo($user, ClientNewSignInNotification::class);
});

it('refuses a suspended client, and writes it to the history', function () {
    $user = clUser(['email' => 'rahim@uddintraders.com']);
    ClientUser::whereKey($user->id)->update(['status' => 'suspended']);

    googleLogin()->assertStatus(403)->assertJsonPath('code', 'account_suspended');

    expect(ClientLoginHistory::where('client_user_id', $user->id)->sole()->failure_reason)->toBe('suspended');
});

it('refuses a closed (deleted) client', function () {
    tap(clUser(['email' => 'rahim@uddintraders.com']))->delete();

    googleLogin()->assertStatus(403)->assertJsonPath('code', 'account_closed');

    expect(ClientUser::count())->toBe(0);
});

it('refuses a token that is not valid for this site', function (array $claims) {
    googleLogin($claims)->assertStatus(422)->assertJsonPath('code', 'invalid_google_token');

    expect(ClientUser::count())->toBe(0);
})->with([
    'another client id' => [['aud' => 'someone-elses.apps.googleusercontent.com']],
    'not issued by Google' => [['iss' => 'https://evil.example.com']],
    'expired' => [['exp' => '1000']],
    'email not verified by Google' => [['email_verified' => 'false']],
    'no email' => [['email' => '']],
    'no subject' => [['sub' => '']],
]);

it('refuses a token Google rejects', function () {
    googleLogin([], 400)->assertStatus(422)->assertJsonPath('code', 'invalid_google_token');
});

it('answers 502 when Google cannot be reached', function () {
    Http::fake(fn () => throw new ConnectionException('no route'));

    clSpa()->postJson('/api/client/google', ['credential' => 'x'])->assertStatus(502)->assertJsonPath('code', 'google_unreachable');
});

it('is off until a client id is configured', function () {
    config(['services.google.client_id' => null]);

    googleLogin()->assertStatus(503)->assertJsonPath('code', 'google_not_configured');
});

it('needs a credential', function () {
    clSpa()->postJson('/api/client/google', [])->assertStatus(422)->assertJsonValidationErrors('credential');
});

it('gives a Google-only client a way to set a password: forgot password works for them', function () {
    googleLogin()->assertCreated();
    Auth::guard('client')->logout();

    clSpa()->postJson('/api/client/forgot-password', ['email' => 'rahim@uddintraders.com'])->assertOk();

    Notification::assertSentTo(ClientUser::sole(), ClientResetPasswordNotification::class);
    Notification::assertNotSentTo(ClientUser::sole(), ClientVerifyEmailNotification::class);
});

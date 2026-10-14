<?php

use App\Models\ClientUser;
use App\Models\PendingEmailChange;
use App\Notifications\ClientConfirmEmailChangeNotification;
use App\Notifications\ClientEmailChangedNotification;
use App\Services\EmailChangeLink;
use App\Services\EmailVerificationLink;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

describe('PUT /profile', function () {
    beforeEach(function () {
        Storage::fake('public');
        Notification::fake();
    });

    function clProfileBody(array $over = []): array
    {
        return ['name' => 'Rahim Uddin', 'phone' => '01711223344', ...$over];
    }

    /** Multipart, the way a browser sends a file: POST with _method=PUT. */
    function clProfileUpload(ClientUser $user, array $fields, string $method = 'PUT')
    {
        return clSpa()->actingAs($user, 'client')->withHeader('Accept', 'application/json')
            ->post('/api/client/profile', ['_method' => $method, ...$fields]);
    }

    it('updates the name and phone and returns /me', function () {
        $user = clUser(['name' => 'Old Name', 'phone' => '+8801911111111']);

        clSpa()->actingAs($user, 'client')->putJson('/api/client/profile', clProfileBody(['name' => '  Rahim Uddin ', 'phone' => '8801711223344']))
            ->assertOk()->assertJsonPath('data.user.name', 'Rahim Uddin')->assertJsonPath('data.user.phone', '+8801711223344');

        expect($user->fresh())->name->toBe('Rahim Uddin')->phone->toBe('+8801711223344');
    });

    it('clears phone_verified_at when the number changes, and keeps it when only the spelling does', function () {
        $user = clUser(['phone' => '+8801711223344', 'phone_verified_at' => now()]);
        $call = fn (string $phone) => clSpa()->actingAs($user->fresh(), 'client')->putJson('/api/client/profile', clProfileBody(['phone' => $phone]));

        $call('01711223344')->assertOk()->assertJsonPath('data.user.phone_verified', true);
        $call('+880 1711-223344')->assertOk()->assertJsonPath('data.user.phone_verified', true);
        expect($user->fresh()->phone_verified_at)->not->toBeNull();

        $call('01811223344')->assertOk()->assertJsonPath('data.user.phone', '+8801811223344')->assertJsonPath('data.user.phone_verified', false);
        expect($user->fresh()->phone_verified_at)->toBeNull();
    });

    it('rejects a phone that is not a Bangladeshi mobile, and a missing name', function () {
        $user = clUser();

        clSpa()->actingAs($user, 'client')->putJson('/api/client/profile', clProfileBody(['phone' => '12345']))->assertStatus(422)->assertJsonValidationErrors('phone');
        clSpa()->actingAs($user, 'client')->putJson('/api/client/profile', clProfileBody(['name' => '   ']))->assertStatus(422)->assertJsonValidationErrors('name');
    });

    it('needs a sign-in', function () {
        clSpa()->putJson('/api/client/profile', clProfileBody())->assertStatus(401);
    });

    it('does not let the email or status be changed through the profile', function () {
        $user = clUser(['email' => 'rahim@uddintraders.com']);

        clSpa()->actingAs($user, 'client')->putJson('/api/client/profile', clProfileBody(['email' => 'hacker@example.com', 'status' => 'suspended', 'email_verified_at' => null]))->assertOk();

        expect($user->fresh())->email->toBe('rahim@uddintraders.com')->and($user->fresh()->status->value)->toBe('active')->and($user->fresh()->email_verified_at)->not->toBeNull();
    });

    describe('avatar', function () {
        it('stores a 256x256 picture on the public disk and shows its URL in /me', function () {
            $user = clUser();

            $response = clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('me.png', 800, 600)]))->assertOk();

            $path = $user->fresh()->avatar_path;
            expect($path)->toStartWith("avatars/{$user->id}-")->and($path)->toMatch('/\.(webp|jpg)$/');
            Storage::disk('public')->assertExists($path);
            [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
            expect([$width, $height])->toBe([256, 256])
                ->and($response->json('data.user.avatar_url'))->toBe(Storage::disk('public')->url($path));
        });

        it('crops from the centre, whatever the shape of the original', function (int $w, int $h) {
            $user = clUser();

            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('me.jpg', $w, $h)]))->assertOk();

            expect(array_slice(getimagesizefromstring(Storage::disk('public')->get($user->fresh()->avatar_path)), 0, 2))->toBe([256, 256]);
        })->with(['wide' => [1200, 300], 'tall' => [300, 1200], 'small' => [64, 64], 'square' => [500, 500]]);

        it('accepts jpg, png and webp', function (string $name) {
            clProfileUpload(clUser(), clProfileBody(['avatar' => UploadedFile::fake()->image($name, 300, 300)]))->assertOk();
        })->with(['a.jpg', 'a.jpeg', 'a.png', 'a.webp']);

        it('works with a plain POST as well as with the PUT override', function () {
            $user = clUser();

            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]), 'POST')->assertOk();

            Storage::disk('public')->assertExists($user->fresh()->avatar_path);
        });

        it('replaces the old picture and deletes its file', function () {
            $user = clUser();
            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]))->assertOk();
            $first = $user->fresh()->avatar_path;

            clProfileUpload($user->fresh(), clProfileBody(['avatar' => UploadedFile::fake()->image('b.png', 300, 300)]))->assertOk();
            $second = $user->fresh()->avatar_path;

            expect($second)->not->toBe($first);
            Storage::disk('public')->assertMissing($first);
            Storage::disk('public')->assertExists($second);
        });

        it('keeps the picture when the profile is saved without one', function () {
            $user = clUser();
            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]))->assertOk();
            $path = $user->fresh()->avatar_path;

            clSpa()->actingAs($user->fresh(), 'client')->putJson('/api/client/profile', clProfileBody(['name' => 'New Name']))->assertOk();

            expect($user->fresh()->avatar_path)->toBe($path);
            Storage::disk('public')->assertExists($path);
        });

        it('removes the picture on request', function () {
            $user = clUser();
            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]))->assertOk();
            $path = $user->fresh()->avatar_path;

            clSpa()->actingAs($user->fresh(), 'client')->putJson('/api/client/profile', clProfileBody(['remove_avatar' => true]))->assertOk()->assertJsonPath('data.user.avatar_url', null);

            expect($user->fresh()->avatar_path)->toBeNull();
            Storage::disk('public')->assertMissing($path);
        });

        it('rejects other file types', function (string $name) {
            $user = clUser();

            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image($name, 300, 300)]))
                ->assertStatus(422)->assertJsonValidationErrors('avatar')->assertJsonPath('errors.avatar.0', 'The picture must be a JPG, PNG or WebP file.');

            expect($user->fresh()->avatar_path)->toBeNull();
            expect(Storage::disk('public')->allFiles())->toBe([]);
        })->with(['gif' => 'a.gif', 'bmp' => 'a.bmp']);

        it('rejects a file that is not really a picture, whatever its name says', function () {
            clProfileUpload(clUser(), clProfileBody(['avatar' => UploadedFile::fake()->createWithContent('photo.png', '<?php echo "not an image";')]))
                ->assertStatus(422)->assertJsonValidationErrors('avatar');
        });

        it('rejects svg and pdf files', function (string $name, string $mime) {
            clProfileUpload(clUser(), clProfileBody(['avatar' => UploadedFile::fake()->create($name, 10, $mime)]))->assertStatus(422)->assertJsonValidationErrors('avatar');
        })->with(['svg' => ['logo.svg', 'image/svg+xml'], 'pdf' => ['cv.pdf', 'application/pdf']]);

        it('rejects a picture over 2 MB and accepts one just under it', function () {
            $user = clUser();

            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('big.jpg', 300, 300)->size(2049)]))
                ->assertStatus(422)->assertJsonValidationErrors('avatar')->assertJsonPath('errors.avatar.0', 'The picture must be 2 MB or smaller.');

            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('ok.jpg', 300, 300)->size(2048)]))->assertOk();
        });

        it('rejects a picture with absurd dimensions', function () {
            clProfileUpload(clUser(), clProfileBody(['avatar' => UploadedFile::fake()->image('wide.png', 8001, 10)]))->assertStatus(422)->assertJsonValidationErrors('avatar');
        });

        it('leaves the old picture alone when a new one is rejected', function () {
            $user = clUser();
            clProfileUpload($user, clProfileBody(['avatar' => UploadedFile::fake()->image('a.png', 300, 300)]))->assertOk();
            $path = $user->fresh()->avatar_path;

            clProfileUpload($user->fresh(), clProfileBody(['avatar' => UploadedFile::fake()->image('a.gif', 300, 300)]))->assertStatus(422);

            expect($user->fresh()->avatar_path)->toBe($path);
            Storage::disk('public')->assertExists($path);
        });
    });
});

describe('email change', function () {
    beforeEach(fn () => Notification::fake());

    function clAskEmailChange(ClientUser $user, array $over = [])
    {
        return clSpa()->actingAs($user, 'client')->postJson('/api/client/email/change', ['new_email' => 'new@uddintraders.com', 'password' => CL_PASSWORD, ...$over]);
    }

    /** The four link values for the user's pending change, the way the client panel posts them. */
    function clChangeLink(ClientUser $user): array
    {
        return clLinkValues(app(EmailChangeLink::class)->make(PendingEmailChange::where('client_user_id', $user->id)->sole()));
    }

    describe('POST /email/change', function () {
        it('saves a pending change and mails a link to the NEW address only', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);

            clAskEmailChange($user, ['new_email' => 'New@UddinTraders.com'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'new@uddintraders.com') && str_contains($m, 'stays active until you confirm'));

            $pending = PendingEmailChange::sole();
            expect($pending->client_user_id)->toBe($user->id)->and($pending->new_email)->toBe('new@uddintraders.com')
                ->and($pending->expires_at->isBetween(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();

            Notification::assertSentOnDemand(ClientConfirmEmailChangeNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new@uddintraders.com');
            Notification::assertNotSentTo($user, ClientConfirmEmailChangeNotification::class);
            Notification::assertSentOnDemandTimes(ClientConfirmEmailChangeNotification::class, 1);
        });

        it('does not switch the email before the link is used', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);

            clAskEmailChange($user)->assertOk();

            $fresh = $user->fresh();
            expect($fresh->email)->toBe('old@uddintraders.com')->and($fresh->email_verified_at)->not->toBeNull();
            clLogin('old@uddintraders.com', CL_PASSWORD)->assertOk();
            Illuminate\Support\Facades\Auth::guard('client')->logout();
            clLogin('new@uddintraders.com', CL_PASSWORD)->assertStatus(422);
        });

        it('puts a link to the client panel in the email, valid for 60 minutes', function () {
            $user = clUser(['name' => 'Rahim Uddin', 'password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            $values = clChangeLink($user);

            Notification::assertSentOnDemand(ClientConfirmEmailChangeNotification::class, function ($notification, $channels, $notifiable) {
                $mail = $notification->toMail($notifiable);
                expect($mail->actionUrl)->toStartWith('http://localhost:3002/confirm-email-change?')
                    ->and(array_keys(clLinkValues($mail->actionUrl)))->toBe(['id', 'hash', 'expires', 'signature'])
                    ->and(implode(' ', $mail->introLines).implode(' ', $mail->outroLines))->toContain('60 minutes')->toContain('keeps its current email address');

                return true;
            });
            expect((int) $values['id'])->toBe(PendingEmailChange::sole()->id)->and($values['hash'])->toBe(sha1('new@uddintraders.com'));
        });

        it('refuses a wrong password', function () {
            $user = clUser(['password' => CL_PASSWORD]);

            clAskEmailChange($user, ['password' => 'not-my-password'])->assertStatus(422)->assertJsonValidationErrors('password');

            expect(PendingEmailChange::count())->toBe(0);
            Notification::assertNothingSent();
        });

        it('refuses an address that is not allowed', function (string $email) {
            clUser(['email' => 'taken@uddintraders.com']);
            tap(clUser(['email' => 'deleted@uddintraders.com']))->delete();
            $user = clUser(['email' => 'me@uddintraders.com', 'password' => CL_PASSWORD]);

            clAskEmailChange($user, ['new_email' => $email])->assertStatus(422)->assertJsonValidationErrors('new_email');

            expect(PendingEmailChange::count())->toBe(0);
        })->with(['not an email' => 'nope', 'already used' => 'taken@uddintraders.com', 'used by a deleted account' => 'deleted@uddintraders.com', 'throwaway' => 'x@mailinator.com', 'the current one' => 'ME@uddintraders.com']);

        it('replaces an earlier request, and the earlier link stops working', function () {
            $user = clUser(['password' => CL_PASSWORD]);
            clAskEmailChange($user, ['new_email' => 'first@uddintraders.com'])->assertOk();
            $firstLink = clChangeLink($user);

            clAskEmailChange($user, ['new_email' => 'second@uddintraders.com'])->assertOk();

            expect(PendingEmailChange::count())->toBe(1)->and(PendingEmailChange::sole()->new_email)->toBe('second@uddintraders.com');
            clSpa()->postJson('/api/client/email/change/confirm', $firstLink)->assertStatus(422)->assertJsonPath('code', 'invalid_email_change_link');
            expect($user->fresh()->email)->not->toBe('first@uddintraders.com');
        });

        it('needs a sign-in', function () {
            clSpa()->postJson('/api/client/email/change', ['new_email' => 'a@uddintraders.com', 'password' => 'x'])->assertStatus(401);
        });

        it('allows 3 requests an hour', function () {
            $user = clUser(['password' => CL_PASSWORD]);

            foreach (['a', 'b', 'c'] as $name) {
                clAskEmailChange($user, ['new_email' => "{$name}@uddintraders.com"])->assertOk();
            }

            clAskEmailChange($user, ['new_email' => 'd@uddintraders.com'])->assertStatus(429);
            Notification::assertSentOnDemandTimes(ClientConfirmEmailChangeNotification::class, 3);
        });
    });

    describe('POST /email/change/confirm', function () {
        it('applies the change, marks the new address verified and tells the OLD address', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'name' => 'Rahim Uddin', 'password' => CL_PASSWORD, 'email_verified_at' => null]);
            clAskEmailChange($user)->assertOk();

            clSpa()->postJson('/api/client/email/change/confirm', clChangeLink($user))
                ->assertOk()->assertJsonPath('message', 'Your email address was changed.')->assertJsonPath('email', 'new@uddintraders.com');

            $fresh = $user->fresh();
            expect($fresh->email)->toBe('new@uddintraders.com')->and($fresh->email_verified_at)->not->toBeNull()->and(PendingEmailChange::count())->toBe(0);
            Notification::assertSentOnDemand(ClientEmailChangedNotification::class, function ($notification, $channels, $notifiable) {
                $mail = $notification->toMail($notifiable);

                return $notifiable->routes['mail'] === 'old@uddintraders.com'
                    && $mail->subject === 'The email address of your EverDigitech account was changed'
                    && str_contains(implode(' ', $mail->introLines), 'new@uddintraders.com');
            });
            Auth::guard('client')->logout();
            clLogin('new@uddintraders.com', CL_PASSWORD)->assertOk();
            Auth::guard('client')->logout();
            clLogin('old@uddintraders.com', CL_PASSWORD)->assertStatus(422);
        });

        it('needs no sign-in', function () {
            $user = clUser(['password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            Auth::guard('client')->logout();

            clSpa()->postJson('/api/client/email/change/confirm', clChangeLink($user))->assertOk();
        });

        it('works once only', function () {
            $user = clUser(['password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            $values = clChangeLink($user);
            clSpa()->postJson('/api/client/email/change/confirm', $values)->assertOk();

            clSpa()->postJson('/api/client/email/change/confirm', $values)->assertStatus(422)->assertJsonPath('code', 'invalid_email_change_link');
            Notification::assertSentOnDemandTimes(ClientEmailChangedNotification::class, 1);
        });

        it('refuses an expired link (after 60 minutes) and drops the request', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            $values = clChangeLink($user);

            $this->travel(61)->minutes();
            clSpa()->postJson('/api/client/email/change/confirm', $values)->assertStatus(422)->assertJsonPath('code', 'email_change_link_expired');

            expect($user->fresh()->email)->toBe('old@uddintraders.com')->and(PendingEmailChange::count())->toBe(0);
        });

        it('refuses a tampered link', function (string $field, string|int $value) {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();

            clSpa()->postJson('/api/client/email/change/confirm', [...clChangeLink($user), $field => $value])->assertStatus(422)->assertJsonPath('code', 'invalid_email_change_link');

            expect($user->fresh()->email)->toBe('old@uddintraders.com')->and(PendingEmailChange::count())->toBe(1);
        })->with([
            'signature' => ['signature', 'deadbeef'],
            'hash (another address)' => ['hash', 'abc123'],
            'longer expiry' => ['expires', 4102444800],
            'another request' => ['id', 9999],
        ]);

        it('does not accept an email-verification signature in its place', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            $pending = PendingEmailChange::sole();
            $verifyValues = clLinkValues(app(EmailVerificationLink::class)->make($user));

            clSpa()->postJson('/api/client/email/change/confirm', [
                'id' => $pending->id, 'hash' => sha1($pending->new_email), 'expires' => $pending->expires_at->getTimestamp(), 'signature' => $verifyValues['signature'],
            ])->assertStatus(422);

            expect($user->fresh()->email)->toBe('old@uddintraders.com');
        });

        it('refuses when someone registered the address in the meantime', function () {
            $user = clUser(['email' => 'old@uddintraders.com', 'password' => CL_PASSWORD]);
            clAskEmailChange($user)->assertOk();
            $values = clChangeLink($user);
            clUser(['email' => 'new@uddintraders.com']);

            clSpa()->postJson('/api/client/email/change/confirm', $values)->assertStatus(422)->assertJsonPath('code', 'email_already_taken');

            expect($user->fresh()->email)->toBe('old@uddintraders.com')->and(PendingEmailChange::count())->toBe(0);
        });

        it('needs all four values', function () {
            clSpa()->postJson('/api/client/email/change/confirm', [])->assertStatus(422)->assertJsonValidationErrors(['id', 'hash', 'expires', 'signature']);
        });
    });
});

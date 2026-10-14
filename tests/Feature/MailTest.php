<?php

use App\Models\Admin;
use App\Notifications\AdminInviteNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/** What happens when the mail server is down or misconfigured: the app keeps working and says so honestly. */
describe('when email cannot be sent', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        // Every notification fails to send, like an SMTP server that refuses the connection.
        Event::listen(NotificationSending::class, fn () => throw new RuntimeException('Connection could not be established with host "mail.example.test"'));
    });

    it('does not create the staff member, and says why (502 mail_failed)', function () {
        $actor = staffWith(['staff.create'], 'Creator');

        api($actor)->postJson('/api/admin/staff', ['name' => 'Nobody', 'email' => 'nobody@everdigitech.test', 'phone' => null, 'role' => roleNamed('Sales')->id])
            ->assertStatus(502)
            ->assertJsonPath('code', 'mail_failed')
            ->assertJsonPath('message', "We couldn't send the invite email. Check that the mail settings are correct, then try again.");

        expect(Admin::where('email', 'nobody@everdigitech.test')->exists())->toBeFalse();
        expect(DB::table('admin_invitation_tokens')->count())->toBe(0);
    });

    it('lets the same request work again once email works', function () {
        $actor = staffWith(['staff.create'], 'Creator');
        $body = ['name' => 'Nobody', 'email' => 'nobody@everdigitech.test', 'phone' => null, 'role' => roleNamed('Sales')->id];
        api($actor)->postJson('/api/admin/staff', $body)->assertStatus(502);

        Event::forget(NotificationSending::class);
        api($actor)->postJson('/api/admin/staff', $body)->assertCreated();

        expect(Admin::where('email', 'nobody@everdigitech.test')->exists())->toBeTrue();
    });

    it('answers 502 mail_failed on resend and leaves the invite time alone', function () {
        $person = tap(Admin::factory()->create())->assignRole('Sales');
        $person->forceFill(['invited_at' => now()->subDays(2)])->save();

        api(staffWith(['staff.create'], 'Creator'))->postJson("/api/admin/staff/{$person->id}/resend-invite")
            ->assertStatus(502)->assertJsonPath('code', 'mail_failed');

        expect($person->fresh()->invited_at->isToday())->toBeFalse();
    });

    it('answers forgot-password the same for a real and an unknown email, with no error', function () {
        $admin = Admin::factory()->create();

        $real = spaCall()->postJson('/api/admin/forgot-password', ['email' => $admin->email]);
        $unknown = spaCall()->postJson('/api/admin/forgot-password', ['email' => 'nobody@example.com']);

        expect($real->status())->toBe(200)->and($unknown->status())->toBe(200)->and($real->json())->toBe($unknown->json());
    });
});

describe('when the mail server rejects the address itself', function () {
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
    });

    it('blames the address (422 on email), not the mail settings, and saves nothing', function () {
        Event::listen(NotificationSending::class, fn () => throw new UnexpectedResponseException(
            'Expected response code "250/251/252" but got code "550", with message "550 The mail server could not deliver mail to typo@gmail.comm. The account or domain may not exist."',
            550,
        ));

        api(staffWith(['staff.create'], 'Creator'))->postJson('/api/admin/staff', ['name' => 'Typo', 'email' => 'typo@gmail.comm', 'phone' => null, 'role' => roleNamed('Sales')->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email')
            ->assertJsonPath('errors.email.0', "The mail server won't accept typo@gmail.comm. Check the address for typos.");

        expect(Admin::where('email', 'typo@gmail.comm')->exists())->toBeFalse();
    });

    it('still reports a refusal that is not about this address as mail_failed', function () {
        Event::listen(NotificationSending::class, fn () => throw new UnexpectedResponseException('Expected response code "250" but got code "550", with message "550 Relay not permitted"', 550));

        api(staffWith(['staff.create'], 'Creator'))->postJson('/api/admin/staff', ['name' => 'Fine', 'email' => 'fine@everdigitech.test', 'phone' => null, 'role' => roleNamed('Sales')->id])
            ->assertStatus(502)->assertJsonPath('code', 'mail_failed');
    });
});

describe('php artisan mail:test', function () {
    it('sends one email with the current settings', function () {
        config(['mail.default' => 'array']);

        $this->artisan('mail:test', ['to' => 'me@example.com'])->assertSuccessful()->expectsOutputToContain('Sent to me@example.com');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        expect($messages)->toHaveCount(1)
            ->and($messages->first()->getOriginalMessage()->getTo()[0]->getAddress())->toBe('me@example.com')
            ->and($messages->first()->getOriginalMessage()->getSubject())->toBe('Test email from '.config('app.name'));
    });

    it('warns that the log mailer sends nothing', function () {
        config(['mail.default' => 'log']);

        $this->artisan('mail:test', ['to' => 'me@example.com'])->assertSuccessful()->expectsOutputToContain('nothing is sent');
    });

    it('rejects an invalid address', function () {
        $this->artisan('mail:test', ['to' => 'not-an-email'])->assertFailed()->expectsOutputToContain('not a valid email address');
    });

    it('fails with a plain-words hint when the server cannot be reached', function () {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'username' => null, 'password' => null, 'timeout' => 2, 'scheme' => null],
        ]);

        $this->artisan('mail:test', ['to' => 'me@example.com'])->assertFailed()->expectsOutputToContain('The email could not be sent');
    });
});

it('has a 15 second mail timeout by default, so a wrong host cannot hang a request', function () {
    expect(config('mail.mailers.smtp.timeout'))->toBe(15);
});

it('sends the invite with the configured sender', function () {
    config(['mail.from' => ['address' => 'noreply@everdigitech.com', 'name' => 'EverDigitech']]);
    $admin = Admin::factory()->create();

    $mail = (new AdminInviteNotification('tok'))->toMail($admin);

    expect($mail->subject)->toBe('You have been invited to the EverDigitech admin panel');
});

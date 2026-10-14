<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** `php artisan mail:test you@example.com`: sends one email with the current mail settings and says what went wrong, if anything. */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : Where to send the test email}';

    protected $description = 'Send a test email with the current mail settings';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$to}\" is not a valid email address.");

            return self::INVALID;
        }

        $mailer = (string) config('mail.default');
        $settings = (array) config("mail.mailers.{$mailer}");
        $this->table(['Setting', 'Value'], [
            ['Mailer', $mailer],
            ['Host', ($settings['host'] ?? '-').':'.($settings['port'] ?? '-')],
            ['Encryption', ($settings['scheme'] ?? null) ?: 'automatic (STARTTLS on 587)'],
            ['Username', $settings['username'] ?? '-'],
            ['From', config('mail.from.address').' ('.config('mail.from.name').')'],
        ]);

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("MAIL_MAILER is \"{$mailer}\": the email is only written to the log, nothing is sent. Set MAIL_MAILER=smtp for live email.");
        }

        try {
            Mail::raw(
                'This is a test email from '.config('app.name').".\n\nIf you can read this, the mail settings work.\nSent at ".now()->toDateTimeString().' (server time).',
                fn ($message) => $message->to($to)->subject('Test email from '.config('app.name')),
            );
        } catch (Throwable $e) {
            $this->error('The email could not be sent: '.$e->getMessage());
            $this->line($this->hint($e->getMessage()));

            return self::FAILURE;
        }

        $this->info("Sent to {$to}. Check the inbox (and the spam folder).");

        return self::SUCCESS;
    }

    /** What the usual SMTP errors mean in plain words. */
    private function hint(string $error): string
    {
        return match (true) {
            str_contains($error, '535') || stripos($error, 'authenticat') !== false => 'Hint: the username or password was refused. Use the full address (noreply@your-domain) as MAIL_USERNAME and check MAIL_PASSWORD.',
            stripos($error, 'timed out') !== false || stripos($error, 'timeout') !== false => 'Hint: the server did not answer. Check MAIL_HOST and MAIL_PORT, and that the hosting firewall allows that outgoing port.',
            stripos($error, 'refused') !== false || stripos($error, 'Connection could not be established') !== false => 'Hint: could not connect. Check MAIL_HOST and MAIL_PORT (465 with MAIL_SCHEME=smtps, or 587).',
            stripos($error, 'certificate') !== false || stripos($error, 'ssl') !== false || stripos($error, 'tls') !== false => 'Hint: the secure connection failed. Port 465 needs MAIL_SCHEME=smtps; port 587 needs MAIL_SCHEME empty. Use the host name on the mail certificate (often mail.your-domain).',
            default => 'Hint: see storage/logs/laravel.log for the full error.',
        };
    }
}

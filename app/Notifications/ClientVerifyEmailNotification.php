<?php

namespace App\Notifications;

use App\Models\ClientUser;
use App\Services\EmailVerificationLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Verify your email address". Queued, so registration doesn't wait for the mail server. */
class ClientVerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  ClientUser  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        // The link is made when the email is built (not when it is queued), so the 60 minutes start when it is sent.
        $url = app(EmailVerificationLink::class)->make($notifiable);
        $minutes = (int) config('everdigitech.email_verification_minutes', 60);

        return (new MailMessage)
            ->subject('Verify your EverDigitech email address')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Thanks for creating an EverDigitech account. Please confirm that this is your email address.')
            ->action('Verify email address', $url)
            ->line("This link works for {$minutes} minutes. If it has expired, sign in and ask for a new one.")
            ->line('If you did not create an account, you can ignore this email.');
    }
}

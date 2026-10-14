<?php

namespace App\Notifications;

use App\Models\ClientUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password": the link goes to the client panel, {CLIENT_APP_URL}/reset-password?token=..&email=.. */
class ClientResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  ClientUser  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        $url = config('everdigitech.client_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
        $minutes = (int) config('auth.passwords.client_users.expire');

        return (new MailMessage)
            ->subject('Reset your EverDigitech password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset the password of your EverDigitech account.')
            ->action('Choose a new password', $url)
            ->line("This link works for {$minutes} minutes.")
            ->line('If you did not ask for this, you can ignore this email. Your password stays the same.');
    }
}

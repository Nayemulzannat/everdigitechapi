<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to a new staff member: a link to set their first password. */
class AdminInviteNotification extends Notification
{
    public function __construct(public readonly string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = config('everdigitech.admin_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'invite' => 1,
        ]);
        $hours = (int) (config('auth.passwords.admin_invites.expire') / 60);

        return (new MailMessage)
            ->subject('You have been invited to the EverDigitech admin panel')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('An admin account has been created for you. Set your password to sign in.')
            ->action('Set your password', $url)
            ->line("This link expires in {$hours} hours. If it has expired, ask a Super Admin to send a new invite.");
    }
}

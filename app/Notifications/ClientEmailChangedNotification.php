<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the OLD address once the change is confirmed: "Your email address was changed". */
class ClientEmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $newEmail) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('The email address of your EverDigitech account was changed')
            ->greeting('Hello '.$this->name.',')
            ->line("The email address of your EverDigitech account was changed to **{$this->newEmail}**. From now on you sign in, and we write to you, there.")
            ->line('If this was you, there is nothing to do.')
            ->line('If it was not you, contact support right away.')
            ->action('Contact support', config('everdigitech.client_url').'/support');
    }
}

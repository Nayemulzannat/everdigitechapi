<?php

namespace App\Notifications;

use App\Models\ClientUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** "Your password was changed", after a reset or a change from the account page. */
class ClientPasswordChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Carbon $at, public readonly ?string $ip = null) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @param  ClientUser  $notifiable */
    public function toMail(object $notifiable): MailMessage
    {
        $when = $this->at->copy()->timezone(config('everdigitech.timezone'))->format('j M Y, g:i A').' (Bangladesh time)';

        return (new MailMessage)
            ->subject('Your EverDigitech password was changed')
            ->greeting('Hello '.$notifiable->name.',')
            ->line("The password of your EverDigitech account was changed on {$when}".($this->ip ? " from IP {$this->ip}." : '.'))
            ->line('You were signed out of your other devices.')
            ->line('If this was you, there is nothing to do.')
            ->line('If it was not you, reset your password now, then contact support.')
            ->action('Reset my password', config('everdigitech.client_url').'/forgot-password');
    }
}

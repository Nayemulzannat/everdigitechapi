<?php

namespace App\Notifications;

use App\Models\ClientUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** "New sign-in to your EverDigitech account": the first time someone signs in from a browser and system we haven't seen. */
class ClientNewSignInNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Carbon $at,
        public readonly ?string $location,
        public readonly string $device,
        public readonly ?string $ip,
    ) {}

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
            ->subject('New sign-in to your EverDigitech account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We noticed a sign-in to your account from a browser or device we haven\'t seen before.')
            ->line("**When:** {$when}")
            ->line('**Where:** '.($this->location ?? 'Unknown location').($this->ip ? " (IP {$this->ip})" : ''))
            ->line("**Device:** {$this->device}")
            ->line('If this was you, there is nothing to do.')
            ->line('If it was not you, change your password right away.')
            ->action('Change my password', config('everdigitech.client_url').'/forgot-password');
    }
}

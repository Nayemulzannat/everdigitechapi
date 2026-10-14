<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** "Two-factor authentication was turned on/off": so the owner notices when someone else did it. */
class ClientTwoFactorChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly bool $enabled, public readonly Carbon $at, public readonly ?string $ip) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Two-factor authentication was turned '.($this->enabled ? 'on' : 'off').' on your EverDigitech account')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Two-factor authentication was turned '.($this->enabled ? 'on' : 'off').' on '.$this->at->timezone(config('everdigitech.timezone'))->format('j M Y, g:i A').($this->ip ? ' from the IP address '.$this->ip : '').'.')
            ->line($this->enabled ? 'From now on you need a code from your authenticator app to sign in.' : 'Your account is now protected by your password only.')
            ->line('If this was not you, change your password at once and contact support.');
    }
}

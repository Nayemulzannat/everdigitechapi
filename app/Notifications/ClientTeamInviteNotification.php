<?php

namespace App\Notifications;

use App\Services\TeamInvitations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the invited address (on-demand: that person may not have an account yet). The link is carried as text. */
class ClientTeamInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        public readonly string $accountName,
        public readonly string $inviterName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->inviterName.' invited you to '.$this->accountName.' on EverDigitech')
            ->greeting('Hello,')
            ->line($this->inviterName.' invited you to join the account "'.$this->accountName.'" on EverDigitech.')
            ->line('You will be able to work with the parts of the account they chose for you. You need an EverDigitech login with this email address: if you do not have one, you can create it first.')
            ->action('Accept the invitation', $this->url)
            ->line('This link works for '.TeamInvitations::DAYS.' days.')
            ->line('If you do not know this person, ignore this email: nothing will happen.');
    }
}

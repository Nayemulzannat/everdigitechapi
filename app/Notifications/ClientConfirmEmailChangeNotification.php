<?php

namespace App\Notifications;

use App\Services\EmailChangeLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the NEW address: "Confirm your new email address". Nothing changes until the link is used.
 * The link is made when the request is accepted and carried as text, so the queued mail does not depend on the pending row.
 */
class ClientConfirmEmailChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $url, public readonly string $name) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your new EverDigitech email address')
            ->greeting('Hello '.$this->name.',')
            ->line('You asked to use this address for your EverDigitech account. Confirm it to finish the change.')
            ->action('Confirm new email address', $this->url)
            ->line('This link works for '.EmailChangeLink::MINUTES.' minutes. Until you use it, your account keeps its current email address.')
            ->line('If you did not ask for this, ignore this email: nothing will change.');
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Sent to the support mailbox (on demand) when a client opens a ticket or writes in one. The mailbox forwards it to
 * the staff. It carries a link to the ticket in the admin panel and the start of the message.
 */
class StaffTicketAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  'new_ticket'|'client_reply'  $kind */
    public function __construct(
        public readonly string $kind,
        public readonly string $number,
        public readonly string $subject,
        public readonly string $url,
        public readonly string $accountName,
        public readonly string $personName,
        public readonly string $department,
        public readonly string $priority,
        public readonly string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isNew = $this->kind === 'new_ticket';

        return (new MailMessage)
            ->subject('['.$this->number.'] '.($isNew ? 'New ticket' : 'Client replied').': '.$this->subject)
            ->greeting($isNew ? 'A new support ticket' : 'A client replied to a ticket')
            ->line('**'.$this->subject.'** ('.$this->number.')')
            ->line('From: '.$this->personName.' · '.$this->accountName)
            ->line('Department: '.$this->department.' · Priority: '.ucfirst($this->priority))
            ->line('> '.str_replace("\n", "\n> ", Str::limit($this->message, 600)))
            ->action('Open the ticket', $this->url)
            ->line('Reply in the admin panel, not by email: an email reply does not reach the client.');
    }
}

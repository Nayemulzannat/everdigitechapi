<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Support answered a ticket. The link opens the ticket in the client panel (sign-in needed). */
class ClientTicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $number,
        public readonly string $subject,
        public readonly string $excerpt,
        public readonly string $url,
        public readonly string $staffName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            // Sent through the support mailbox's own login when one is set (SUPPORT_MAIL_USERNAME), else the normal mailer.
            ->mailer(config('mail.mailers.support.username') ? 'support' : null)
            // From the support mailbox, so the client recognises it and a reply lands where staff can see it.
            ->from(config('everdigitech.support.email'), config('everdigitech.support.name'))
            ->replyTo(config('everdigitech.support.email'), config('everdigitech.support.name'))
            ->subject('['.$this->number.'] '.$this->subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->staffName.' from EverDigitech support replied to your ticket "'.$this->subject.'" ('.$this->number.'):')
            ->line('> '.str_replace("\n", "\n> ", $this->excerpt))
            ->action('Open the ticket', $this->url)
            ->line('Please reply in the client panel, so your answer goes straight into the ticket.');
    }
}

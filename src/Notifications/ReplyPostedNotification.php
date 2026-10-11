<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Symfony\Component\Mime\Email;

/**
 * Sent to a ticket's participants when a new message is posted.
 *
 * @internal
 */
class ReplyPostedNotification extends TicketNotification
{
    public function __construct(Ticket $ticket, public TicketMessage $message)
    {
        parent::__construct($ticket);
    }

    public function key(): string
    {
        return 'ticket.reply';
    }

    public function title(): string
    {
        return 'New reply on '.$this->ticket->reference;
    }

    public function body(): string
    {
        return Str::limit((string) $this->message->body, 140);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = parent::toMail($notifiable);

        // An AI answer is an automatic reply (RFC 3834): the customer's
        // autoresponder must not answer it back.
        if ($this->message->source === MessageSource::Ai) {
            $mail->withSymfonyMessage(function (Email $message): void {
                $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-replied');
            });
        }

        return $mail;
    }

    /**
     * The whole reply, under "Re: <ticket title> [<reference>]" — the bracketed
     * reference threads a reply whose client dropped the headers.
     */
    protected function mailContent(MailMessage $mail): MailMessage
    {
        $title = trim((string) $this->ticket->title);
        $subject = preg_match('/^(re|aw|r|sv|fwd?):/i', $title) === 1 ? $title : 'Re: '.$title;
        $mail->subject($subject.' ['.$this->ticket->reference.']');

        $body = (string) $this->message->body;

        if ($this->message->body_format === BodyFormat::Html) {
            $body = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", $body)), ENT_QUOTES | ENT_HTML5);
        }

        foreach (preg_split('/\n\s*\n/', trim(str_replace(["\r\n", "\r"], "\n", $body))) ?: [] as $paragraph) {
            if (trim($paragraph) !== '') {
                // Mail lines are Markdown: a reply's text never becomes a link.
                $mail->line(str_replace(['](', ']:', '!['], ["]\u{200B}(", "]\u{200B}:", "!\u{200B}["], trim($paragraph)));
            }
        }

        return $mail->line('Reference: '.$this->ticket->reference);
    }
}

<?php

declare(strict_types=1);

namespace Selli\Ticketing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Selli\Ticketing\Contracts\NotificationPreferences;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Mail\MailMessageIds;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Notifications\Channels\SlackWebhookChannel;
use Selli\Ticketing\Support\Ticketing;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * Base class for the package's ticket notifications. Channel selection is
 * delegated to the {@see NotificationPreferences} contract (per-user/per-tenant)
 * and then run through the digest throttle. Subclasses declare a stable key()
 * plus the human-facing title()/body(); the per-channel payloads have sensible
 * defaults a host can override.
 *
 * @internal
 */
abstract class TicketNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Channels resolved by the subscriber (preferences + digest throttle). When
     * set they are authoritative, so a queue RETRY re-uses them rather than
     * re-running the throttle and possibly suppressing the redelivery.
     *
     * @var list<string>|null
     */
    protected ?array $onlyChannels = null;

    public function __construct(public Ticket $ticket)
    {
        $this->onConnection(config('ticketing.queue.connection'));
        $this->onQueue(config('ticketing.queue.queue'));
    }

    /**
     * @param  list<string>  $channels
     */
    public function onlyChannels(array $channels): static
    {
        $this->onlyChannels = $channels;

        return $this;
    }

    /**
     * Stable preference key, e.g. "ticket.assigned".
     */
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function body(): string;

    /**
     * @return list<string>
     */
    public function supportedChannels(): array
    {
        return ['mail', 'database', 'broadcast', 'slack'];
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        // Prefer channels the subscriber already resolved+throttled; otherwise
        // (e.g. a direct $user->notify()) fall back to raw preferences.
        $channels = $this->onlyChannels ?? app(NotificationPreferences::class)
            ->channels($notifiable, $this->key(), $this->supportedChannels());

        // Map the package's "slack" key onto the dependency-free webhook channel.
        return array_map(
            static fn (string $channel): string => $channel === 'slack' ? SlackWebhookChannel::class : $channel,
            $channels,
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->mailContent(new MailMessage);

        // Tag the Reply-To so a recipient's reply threads straight back to this
        // ticket (the +t_<token> address the inbound channel understands).
        $replyTo = app(Ticketing::class)->replyAddressFor($this->ticket);

        if ($replyTo !== null) {
            $mail->replyTo($replyTo);
        }

        return $this->threaded($mail);
    }

    protected function mailContent(MailMessage $mail): MailMessage
    {
        return $mail
            ->subject($this->title())
            ->line($this->body())
            ->line('Reference: '.$this->ticket->reference);
    }

    /**
     * Thread the email with the ticket's others in the recipient's client, and
     * mark it as ours: a signed Message-ID, In-Reply-To / References pointing
     * at the customer's latest email (or the ticket's thread root), and an
     * X-Ticketing-Mailer header the inbound channel drops on sight.
     */
    protected function threaded(MailMessage $mail): MailMessage
    {
        return $mail->withSymfonyMessage(function (Email $message): void {
            $headers = $message->getHeaders();
            $headers->addTextHeader('X-Ticketing-Mailer', 'selli/ticketing');

            try {
                $id = MailMessageIds::forTicket($this->ticket->getKey());
                $root = MailMessageIds::root($this->ticket->getKey());
            } catch (InvalidConfigurationException) {
                return; // no token secret: the client threads on the subject
            }

            $inbound = Ticketing::ticketMessageModel()::query()->withoutTenancy()
                ->where('ticket_id', $this->ticket->getKey())
                ->whereNotNull('email_message_id')
                ->where('email_message_id', 'not like', 'sha256:%');
            $first = self::validId((clone $inbound)->oldest()->value('email_message_id')) ?? $root;
            $parent = self::validId((clone $inbound)->latest()->value('email_message_id')) ?? $root;

            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $id);
            $headers->addIdHeader('In-Reply-To', $parent);
            $headers->addIdHeader('References', array_values(array_unique([$first, $parent])));
        });
    }

    /**
     * A stored Message-ID usable in a header (a sender's client may have
     * written one the RFC does not allow), or null.
     */
    protected static function validId(mixed $id): ?string
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        try {
            new Address($id);
        } catch (RfcComplianceException) {
            return null;
        }

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'key' => $this->key(),
            'ticket_id' => $this->ticket->getKey(),
            'reference' => $this->ticket->reference,
            'title' => $this->title(),
            'body' => $this->body(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toSlack(object $notifiable): string
    {
        return $this->title().' — '.$this->body().' ('.$this->ticket->reference.')';
    }
}

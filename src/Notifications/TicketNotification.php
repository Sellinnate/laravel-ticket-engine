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
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Notifications\Channels\SlackWebhookChannel;

/**
 * Base class for the package's ticket notifications. Channel selection is
 * delegated to the {@see NotificationPreferences} contract (per-user/per-tenant)
 * and then run through the digest throttle. Subclasses declare a stable key()
 * plus the human-facing title()/body(); the per-channel payloads have sensible
 * defaults a host can override.
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
        return (new MailMessage)
            ->subject($this->title())
            ->line($this->body())
            ->line('Reference: '.$this->ticket->reference);
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

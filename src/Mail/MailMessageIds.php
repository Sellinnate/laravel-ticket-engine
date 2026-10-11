<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Support\Str;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Support\MailThreadToken;

/**
 * The Message-IDs the package sends with: `tk.<unique>.<ticket token>@<domain>`
 * for each email, `tk.root.<ticket token>@<domain>` for the ticket's thread.
 * The ticket token is the signed one of the reply address, so a reply's
 * In-Reply-To / References lead back to the ticket without any stored state,
 * and our own mail coming back in is recognised.
 *
 * @internal
 */
class MailMessageIds
{
    /**
     * A fresh Message-ID for one email about the ticket (without angle brackets).
     *
     * @throws InvalidConfigurationException without a token secret
     */
    public static function forTicket(int|string $ticketId): string
    {
        return 'tk.'.Str::lower(Str::random(16)).'.'.MailThreadToken::issue($ticketId).'@'.self::domain();
    }

    /**
     * The thread root every email about the ticket references.
     *
     * @throws InvalidConfigurationException without a token secret
     */
    public static function root(int|string $ticketId): string
    {
        return 'tk.root.'.MailThreadToken::issue($ticketId).'@'.self::domain();
    }

    /**
     * The ticket id a Message-ID of ours names, or null (someone else's id, a
     * forged signature, or no secret to check it with).
     */
    public static function ticketFrom(string $messageId): ?string
    {
        $id = trim($messageId, " \t\n\r<>");

        if (preg_match('/^tk\.[a-z0-9]+\.([^.@]+\.[^.@]+)@/', $id, $matches) !== 1) {
            return null;
        }

        try {
            return MailThreadToken::verify($matches[1]);
        } catch (InvalidConfigurationException) {
            return null;
        }
    }

    /**
     * How a Message-ID is stored in the indexed `email_message_id` column:
     * without angle brackets, hashed when longer than the column.
     */
    public static function column(string $messageId): string
    {
        $id = trim($messageId, " \t\n\r<>");

        return mb_strlen($id) > 191 ? 'sha256:'.hash('sha256', $id) : $id;
    }

    /**
     * The right-hand side of our ids: the reply-to domain, the sender's, the
     * app's host — whichever is configured first.
     */
    public static function domain(): string
    {
        foreach ([config('ticketing.mail.outbound.reply_to_base'), config('mail.from.address')] as $address) {
            if (is_string($address) && ($at = strrpos($address, '@')) !== false) {
                $domain = strtolower(trim(substr($address, $at + 1)));

                if (preg_match('/^[a-z0-9.-]+$/', $domain) === 1) {
                    return $domain;
                }
            }
        }

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && preg_match('/^[a-z0-9.-]+$/i', $host) === 1 ? strtolower($host) : 'localhost';
    }
}

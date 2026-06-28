<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Selli\Ticketing\Contracts\InboundMailRouter;

/**
 * Config-driven router. Each ticketing.mail.inbound.routes entry is
 * ['match' => <addr|'*'|/regex/>, 'tenant' => <id|null>, 'type' => <key>]; the
 * first entry that matches any recipient wins. The recipient's +t_ sub-address
 * tag is stripped before matching, so support+t_abc@x matches a route for
 * support@x.
 */
class ConfigInboundMailRouter implements InboundMailRouter
{
    public function route(InboundEmail $email): ?MailRoute
    {
        /** @var array<int, array{match?: string, tenant?: int|string|null, type?: string}> $routes */
        $routes = config('ticketing.mail.inbound.routes', []);
        $defaultType = (string) config('ticketing.mail.inbound.default_type', 'support');

        foreach ($routes as $route) {
            $match = (string) ($route['match'] ?? '');

            foreach ($email->recipients as $recipient) {
                if ($this->matches($match, $this->baseAddress($recipient))) {
                    return new MailRoute(
                        type: isset($route['type']) ? (string) $route['type'] : $defaultType,
                        tenant: $route['tenant'] ?? null,
                    );
                }
            }
        }

        return null;
    }

    protected function matches(string $match, string $address): bool
    {
        if ($match === '') {
            return false;
        }

        if ($match === '*') {
            return true;
        }

        // A /.../ value is treated as a regular expression.
        if (strlen($match) >= 2 && $match[0] === '/' && str_ends_with($match, '/')) {
            return preg_match($match, $address) === 1;
        }

        return strcasecmp($match, $address) === 0;
    }

    /**
     * Strip ONLY the +t_<token> reply tag and lower-case:
     * "Support+t_abc@Example.com" → "support@example.com". Any other plus-alias
     * (e.g. "support+sales@…") is preserved so a host can route on it.
     */
    protected function baseAddress(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            return strtolower(trim($address));
        }

        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if (preg_match('/^(.*)\+t_[^+@]+$/', $local, $matches) === 1) {
            $local = $matches[1];
        }

        return strtolower(trim($local.'@'.$domain));
    }
}

# Laravel Ticketing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/selli/ticketing.svg?style=flat-square)](https://packagist.org/packages/selli/ticketing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/Sellinnate/laravel-ticketing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Sellinnate/laravel-ticketing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/selli/ticketing.svg?style=flat-square)](https://packagist.org/packages/selli/ticketing)

**The agnostic, headless, multi-tenant ticketing domain engine for Laravel.**
Attach tickets to anything — your models, your UI, your tenant.

`selli/ticketing` is not a help-desk app and not a UI. It is the reusable domain
layer you build any ticketing mechanism on — customer support, technical
interventions, internal requests, incident management, work queues — in days
instead of weeks. A ticket can be about any Eloquent model in your app (an order,
a device, a contract), about a service, or about nothing at all. The link to your
domain happens **by contract** (`Ticketable`), never by coupling to your schema.

> Headless by design: zero UI, zero forced user model, multi-tenant by default,
> workflow configurable per ticket type. The same engine serves a Filament
> back-office, an Inertia/Vue front-end, a mobile API or a queued job without
> changes.

## Installation

```bash
composer require selli/ticketing
php artisan vendor:publish --tag="ticketing-migrations"
php artisan vendor:publish --tag="ticketing-config"
php artisan migrate
```

## Quick start

Make any model the subject of tickets:

```php
use Selli\Ticketing\Concerns\HasTickets;
use Selli\Ticketing\Contracts\Ticketable;

class Order extends Model implements Ticketable
{
    use HasTickets; // tickets() relation + openTicket() helper
}
```

Open and work tickets through the facade or the host helper:

```php
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\MessageVisibility;

// A free-standing request
$ticket = Ticketing::open(
    type: 'support',
    title: 'Login is broken',
    requester: $user,
    priority: Priority::High,
);

// A ticket about any host entity
$ticket = $order->openTicket(type: 'incident', title: 'Missing shipment', requester: $user);

// Conversation, with public/internal visibility
Ticketing::for($ticket)->postMessage($agent, 'Looking into it', MessageVisibility::Internal);
Ticketing::for($ticket)->postMessage($agent, 'Refund issued', MessageVisibility::Public);
```

Actors are agnostic too — implement `CanRequestTickets` and/or `CanActOnTickets`
on whichever model(s) represent your requesters and agents (even two different
populations such as `Customer` and `Operator`).

## Multi-tenancy

Tenancy is structural, not an afterthought. Every table carries a tenant column,
every read is scoped by a global scope, every write is auto-assigned to the
current tenant. With no tenant resolved the scope **fails closed** — only shared
(null-tenant) rows are visible, never another tenant's data.

```php
use Selli\Ticketing\Tenancy\TenantContext;

// CLI / queues / email: act explicitly as a tenant
app(TenantContext::class)->forTenant($tenantId, function () {
    Ticketing::open(type: 'support', title: '...', requester: $requester);
});
```

Bind your own `TenantResolver` (or a bridge to stancl/spatie tenancy) to inherit
the current tenant from your existing infrastructure.

## Architecture at a glance

- **Three-level API** — `Ticketing` facade for common cases, Action classes for
  fine control and testing, domain events for extension.
- **Everything is an event** — `TicketOpened`, `MessagePosted`, … are the primary
  extension hooks. Automations, notifications, audit and integrations attach here.
- **Override-friendly** — every model is resolved from the container and
  replaceable: `Ticketing::useTicketModel(MyTicket::class)`.
- **Immutable audit trail** — `ticket_activities` is append-only; updates/deletes
  throw.
- **Config-validated at boot** — a workflow that references a missing state fails
  fast, not at runtime.

## Testing

```bash
composer test            # Pest
composer test-coverage   # Pest with a 90% minimum
composer analyse         # PHPStan (Larastan) level 6
composer format          # Laravel Pint
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Sellinnate](https://github.com/Sellinnate)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

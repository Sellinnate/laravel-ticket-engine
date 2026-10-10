---
title: "Installation"
description: "Install selli/ticketing and run the migrations."
type: guide
---

# Installation

Require the package:

```bash
composer require selli/ticketing
```

Then run the install command — it publishes the config and migrations and offers to run them:

```bash
php artisan ticketing:install
```

That is equivalent to:

```bash
php artisan vendor:publish --tag="ticketing-migrations"
php artisan vendor:publish --tag="ticketing-config"
php artisan migrate
```

## Wire your models

The package never assumes a users table. Point it at *your* models by implementing two small contracts.

```php
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Contracts\CanActOnTickets;

class User extends Authenticatable implements CanRequestTickets, CanActOnTickets
{
    public function requesterLabel(): string { return $this->name; }
    public function requesterEmail(): ?string { return $this->email; }

    public function agentLabel(): string { return $this->name; }
    public function agentEmail(): ?string { return $this->email; }
}
```

- **`CanRequestTickets`** — anyone who can open and own a ticket (a customer, an employee).
- **`CanActOnTickets`** — an agent who works tickets (can be the same model, or a different one).

To make a model *ticketable* (the subject a ticket is about), implement `Ticketable` or use the
`HasTickets` convenience trait:

```php
use Selli\Ticketing\Concerns\HasTickets;

class Order extends Model
{
    use HasTickets; // adds $order->tickets() and $order->openTicket(...)
}
```

## Database notes {#database-notes}

The suite runs on SQLite, MySQL 8.4 and PostgreSQL 16 in CI. Two MySQL/MariaDB limits apply to the
optional key settings, and must be decided **before** the first `migrate`:

| Setting | On MySQL/MariaDB (utf8mb4) |
| --- | --- |
| `ticketing.ids.type = 'ulid'` | Call `Schema::defaultStringLength(191);` in a service provider's `boot()` before migrating. At the default length of 255, the participants unique index (a ULID plus three strings) exceeds InnoDB's 3072-byte key limit. |
| `ticketing.tables.prefix` | Keep it to **three characters** or fewer (e.g. `tk_`). MySQL caps identifiers at 64 characters and some generated index names are already 61 long. |

A string tenant column (`tenancy.column_type = 'string'`) works with the defaults. PostgreSQL and SQLite
have neither limit.

## See it work

```bash
php artisan ticketing:demo
```

This seeds one example ticket with a public reply and an internal note, and prints its reference — your
first ticket in well under fifteen minutes.

## Next

- [Quick Start](/getting-started/quick-start) — open, reply, transition and assign from code.
- [Core concepts](/getting-started/concepts) — the mental model.

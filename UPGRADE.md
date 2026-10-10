# Upgrade Guide

`selli/ticketing` follows semantic versioning from 1.0.0 on. What is covered is defined on the
[Public API](https://laravel-ticketing.selli.io/reference/public-api) page; the release rules are on
[Versioning & support](https://laravel-ticketing.selli.io/reference/versioning).

## General procedure (any upgrade)

1. Read the section for every version you skip, below.
2. `composer update selli/ticketing`
3. Publish and run any new migrations — they are always additive:

   ```bash
   php artisan vendor:publish --tag=ticketing-migrations
   php artisan migrate
   ```

4. Compare your published `config/ticketing.php` with the package's: new keys have a default that keeps
   the previous behaviour, so copying them is optional but recommended — except where a version's
   **Behaviour changes** below say otherwise (for 1.0.0: `contacts.inbound_email` and
   `workflow.reopen_on_reply` are on by default).

## From a pre-release checkout (`dev-main` before 1.0.0) to 1.0.0

There was no tagged release before 1.0.0. If you installed `dev-main`, these are the changes that can
affect you.

### New migrations

1.0.0 adds new migrations next to the original ones (none of the original files changed). Publish and
run them as above. They add:

- query indexes on `tickets`, `ticket_participants`, `ticket_messages`, `sla_clocks` and
  `satisfaction_ratings`;
- a nullable `channel` column on `tickets`;
- the contacts, knowledge-base, widget-key, conversation and AI-turn tables;
- AI and classification columns on `tickets` (`language`, `sentiment`, `ai_status`, `ai_exchanges`,
  `escalation_reason`, `escalated_at`, `first_ai_response_at`) and a `role` on `team_members`.

### Removed claims (no code is removed)

The README, config comments and `composer.json` `suggest` advertised a Spatie state-class workflow driver,
an auto-wired `beyondcode/laravel-mailbox` bridge and stancl/spatie tenancy and permission bridges. None of
them existed. If your config sets `ticketing.workflow.driver` to `state-class`, the package threw
`Unknown workflow driver` before and still does: register your own driver with
`WorkflowManager::extend()`. Tenant resolvers for stancl/tenancy and spatie/laravel-multitenancy are now
documented recipes on the [Multi-tenancy](https://laravel-ticketing.selli.io/concepts/tenancy) page.

### Behaviour changes

- **Tickets record their channel.** `Ticketing::open()` and `for()->open()` accept a `channel` argument
  (`TicketChannel` or a string). Tickets opened through the facade default to `web`, the REST API writes
  `api` and inbound email writes `email`. Existing rows keep `channel = null`.
- **REST listing filters.** `GET /tickets` now goes through `TicketQuery`: list filters accept several
  values (`?status=open,pending` or `?status[]=open&status[]=pending`) instead of silently ignoring arrays,
  and new filters (`state`, `type`, `channel`, `assignee`, `team`, `tag`, `created_from`/`created_to`, `q`,
  `sort`) are available. The default order is still newest first.
- **Inbound email senders become contacts.** With no `InboundRequesterResolver` of your own, the email
  channel now resolves the sender to a `Contact` (author and requester of the ticket) instead of leaving them
  empty. Set `ticketing.contacts.inbound_email` to `false` for the old behaviour.
- **Requester replies reopen resolved or closed tickets** within 7 days, through the workflow's `reopen`
  transition (`ticketing.workflow.reopen_on_reply`). Set `enabled` to `false` to keep them as they are.
- **REST ids are strings.** `subject.id`, `assignee.id` and `author.id` are always serialised as strings
  (they are string columns); a freshly assigned ticket used to return an integer.
- **Message sources** gain `ai` and `widget`.
- **Requesters are checked against the ticket's tenant**: opening a ticket for a requester of another tenant
  throws `CrossTenantException`, and the ticket policy no longer lets a user of another tenant in through a
  participant row.
- **Inbound email rate-limit keys** are hashed with SHA-256 instead of SHA-1. Counters in flight at the
  moment of the upgrade restart from zero (at most one minute of flood allowance).

### Classes now marked `@internal`

Listeners, the automation engine internals, SLA calculators, routing strategies, HTTP
controllers/requests/resources, jobs, command classes, GDPR internals, token helpers and factories are
`@internal`. They still exist, but they are no longer covered by semver. If you extended a routing strategy,
implement the `AssignmentStrategy` contract instead and register it with `AssignmentManager::extend()`.

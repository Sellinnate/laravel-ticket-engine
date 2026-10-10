---
title: "Migrating an existing ticket module"
description: "Move a home-grown support module — requests, messages, an AI chatbot, KPIs — onto selli/ticketing without a big-bang rewrite."
type: guide
---

# Migrating an existing ticket module

Most products that need ticketing already have *something*: a `support_requests` table, a few statuses, a
messages table, an AI chatbot bolted on, a dashboard with a few counts. This guide moves such a module onto
`selli/ticketing` step by step, using a module like the **customer-support module of SalesMind**
(`SupportRequest`) as the running example.

::: callout info "In plain words"
You keep your app running the whole time. First the package runs next to your module, then new requests go
to the package while old ones are copied over, then the old module is switched off. At every step you can
stop and go back.
:::

## 1. Map your concepts

| Your module (example) | selli/ticketing |
| --- | --- |
| `SupportRequest` (subject, body, status, priority, `company_id`, `user_id`) | `Ticket` of a [type](/concepts/workflow) (e.g. `support`), `title`, first message, `status`, `priority`, tenant = company, requester = user |
| A request about an order / a device | the ticket's **subject** (`Ticketing::for($order)->open(...)`) |
| Guest requests with only a name and an email | [`Contact`](/concepts/contacts) requesters |
| `SupportRequestMessage` (+ an `is_internal` flag) | `TicketMessage`, `visibility` public / internal |
| `assigned_to` | assignee (any model implementing `CanActOnTickets`) and/or a team |
| Status strings (`new`, `waiting_customer`, `done`…) | the states of your workflow, mapped to the semantics open / paused / closed |
| Chatbot sessions and their transcripts | [widget conversations](/channels/widget), which become tickets with their transcript |
| FAQ / help pages used by the chatbot | [knowledge-base](/concepts/knowledge-base) articles (+ the [RAG bridge](/integrations/rag-engine)) |
| "Escalate to an operator" | [AI escalation](/concepts/ai#escalation) and [human takeover](/concepts/ai#takeover) |
| Dashboard counts | [`Ticketing::metrics()`](/concepts/reporting) |
| Support emails | the [email channel](/channels/email) |

## 2. Install next to the old module

```bash
composer require selli/ticketing
php artisan ticketing:install
```

- **Tenancy**: bind a `TenantResolver` that returns your company id (see [Multi-tenancy](/concepts/tenancy)).
- **Actors**: implement `CanRequestTickets` on your customer model and `CanActOnTickets` on your operator
  model (one model can do both).
- **Workflow**: declare a type whose states match your statuses, so nothing is lost in the copy:

```php
// config/ticketing.php
'workflow' => [
    'workflows' => [
        'support' => [
            'initial' => 'new',
            'states' => ['new', 'in_progress', 'waiting_customer', 'done', 'archived'],
            'transitions' => [/* … */],
            'semantics' => ['open' => ['new', 'in_progress'], 'paused' => ['waiting_customer'], 'closed' => ['done', 'archived']],
        ],
    ],
],
'types' => ['support' => ['name' => 'Support', 'workflow' => 'support']],
```

Nothing is user-facing yet; the old module keeps working.

## 3. Send new requests to the package

Switch the entry points one by one, behind a feature flag per tenant if you like:

```php
// was: SupportRequest::create([...])
$ticket = Ticketing::open(type: 'support', title: $data['subject'], requester: $user, channel: 'web');
Ticketing::postMessage($ticket, $user, $data['body']);
```

- The **chatbot**: replace it with the widget API (or keep your UI and call it) — conversations, AI
  answers with sources and hand-over to an operator come with it. Configure the AI per type and tenant.
- **Email**: point your inbound webhook (or IMAP mailbox) at `Ticketing::receiveEmail()`.
- **Operators' screens**: list with `Ticketing::tickets()` (filters, sort, pagination) and act with the
  facade; or use the REST API from an SPA.

## 4. Copy the history

Copy the **closed** requests with a one-off command. Run it with the side effects off — no notifications,
AI turns, automations, webhooks or SLA clocks for years-old requests:

```php
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Enums\{MessageVisibility, Priority};
use Selli\Ticketing\Events\{MessagePosted, ParticipantAdded, TicketAssigned, TicketOpened};

// Only the package's domain events are silenced (routing, SLA, notifications, AI, automation, webhooks
// listen to them); Eloquent's own events keep running — they assign the tenant to every row.
Event::fakeFor(function () {
    SupportRequest::query()->with('messages')->chunkById(200, function ($requests) {
        foreach ($requests as $old) {
            app(TenantContext::class)->forTenant($old->company_id, function () use ($old) {
                $ticket = Ticketing::open(
                    type: 'support',
                    title: $old->subject,
                    requester: $old->user ?? Ticketing::contacts()->remember($old->guest_email, $old->guest_name),
                    priority: Priority::tryFrom($old->priority_level) ?? Priority::Normal,
                    channel: $old->source ?? 'web',
                );

                foreach ($old->messages as $m) {
                    $message = Ticketing::postMessage($ticket, $m->author, $m->body,
                        $m->is_internal ? MessageVisibility::Internal : MessageVisibility::Public,
                        meta: ['legacy_id' => $m->id]);
                    $message->forceFill(['created_at' => $m->created_at, 'updated_at' => $m->created_at])->saveQuietly();
                }

                $ticket->setCustomField('legacy_id', $old->id)->forceFill([
                    'status' => $old->status,                       // a state of your workflow
                    'created_at' => $old->created_at,
                    'first_response_at' => $old->first_answered_at,
                    'resolved_at' => $old->closed_at,
                ])->saveQuietly();
            });
        }
    });
}, [TicketOpened::class, MessagePosted::class, ParticipantAdded::class, TicketAssigned::class]);
```

```bash
php artisan ticketing:rag:index            # if you use the RAG bridge
```

- **Requests still open at the cut-over**: finish them in the old module, or open them through the normal
  path (without `Event::fakeFor`, with notifications muted through your preferences) so their SLA clocks
  start — at import time: the old deadlines are not carried over.
- Copied tickets have no SLA clocks: they count in the response and resolution times (computed from the
  timestamps you copy) but not in SLA compliance.
- Keep the old ids (the `legacy_id` custom field, `meta.legacy_id` on messages) so links and conversations
  can be traced back.
- Import the FAQ/help pages as articles with the right audience, and publish them.
- Do it per tenant and compare counts (`Ticketing::metrics()` against your old dashboard) before moving on.

## 5. Switch off the old module

When every entry point writes to the package and the history is copied: make the old tables read-only,
redirect old URLs to the new screens, and remove the old code in a later release. Keep the tables until
your retention policy allows deleting them.

## Pitfalls

- **Statuses**: map every old status to a declared state; a status outside the workflow cannot transition.
- **Tenants**: a requester of another tenant is refused (`CrossTenantException`) — check guest data.
- **Times**: SLA durations are computed from the timestamps you copy; wrong time zones skew the KPIs.
- **AI**: enable it per type and tenant after the import, in `draft` mode first.
- **GDPR**: requests of people who asked for erasure should be erased in the old module first, or
  anonymised with `Ticketing::anonymiseRequester()` right after the copy.

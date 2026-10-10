---
title: "AI assistance"
description: "A provider-agnostic AI that classifies, answers with sources, drafts and summarises — and hands over to humans by rule."
type: concept
---

# AI assistance

The package can let an AI answer customers — on tickets and in widget conversations — while staying
**provider-agnostic**: it ships the orchestration (modes, escalation rules, human takeover, idempotency, cost
tracking) and talks to any model through one small contract.

::: callout info "In plain words"
A customer writes. The AI reads the conversation, looks up your knowledge base and either answers (citing
its sources), writes a draft for an agent, or decides a human must take it — because it is not sure, found
nothing, the topic is sensitive or the customer asked for a person. The moment an agent replies, the AI steps
aside for good.
:::

## Turning it on

```php
// config/ticketing.php
'ai' => [
    'enabled' => true,
    'driver' => 'fake',          // null | fake | your own (AiManager::extend)
    'mode' => 'off',             // default for every tenant and type
    'modes' => [
        'types' => ['support' => 'auto', 'incident' => 'draft'],
        'tenants' => [
            5 => 'off',
            8 => ['mode' => 'draft', 'types' => ['support' => 'auto']],
        ],
    ],
    'queue' => true,             // run AI turns on the queue (recommended)
],
```

| Mode | What the AI does |
| --- | --- |
| `auto` | Answers the customer publicly (with sources), escalates when a rule fires. |
| `draft` | Writes an **internal** draft for an agent; rules still flag (and notify) when a human must handle it. |
| `off` | Nothing. |

The mode is resolved per tenant and ticket type (most specific wins); bind your own `AiSettingsResolver` to
read it from a settings table. Everything stays off while `ai.enabled` is false.

## What happens on a customer message

1. `MessagePosted` from the requester (a contact, the requester participant, or an email/widget message)
   queues one **AI turn** — at most one per message, even if the job is retried.
2. The driver **classifies** the message: category, priority, language, sentiment, sensitive topics, "wants
   a human". The first classification fills the ticket's `language` / `sentiment`, a missing `category` and
   a **higher** priority (never a lower one).
3. **Rules before answering** — human requested, sensitive topic, negative sentiment (opt-in), too many AI
   exchanges — escalate without spending an answer.
4. The driver **answers** with sources and a confidence. **Rules after answering** — no answer, no sources,
   confidence below `min_confidence` — escalate.
5. Under the ticket's row lock the package re-checks that no human took over meanwhile, then posts the reply
   (`source = ai`, sources and confidence in `meta.ai`), the draft, or escalates.

The AI only sees **public** messages: internal notes never leave the team.

## Escalation rules {#escalation}

```php
'escalation' => [
    'min_confidence' => 0.7,
    'require_sources' => true,
    'sensitive_topics' => ['billing', 'legal', 'privacy', 'complaint'],
    'max_exchanges' => 3,
    'on_negative_sentiment' => false,
    'team' => 'support-desk',     // fallback owner when routing rules find none (key or id)
    'notify_leads' => true,
    'handoff_message' => 'Thanks — a member of our team will get back to you shortly.',
    'summarize' => true,
],
```

A customer asking for a person and a provider error **always** escalate. Escalating (`EscalateTicket`, also
`Ticketing::escalate($ticket)`) works on tickets that are not resolved or closed — otherwise it throws
`EscalationNotAllowedException` (`422` over REST): reopen the ticket first. It:

- sets `ai_status = escalated`, `escalation_reason` and `escalated_at`, and audits `ticket.escalated`;
- makes sure someone owns the ticket: routing rules first, then the escalation team;
- posts the hand-off message to the customer (auto mode) and an internal AI summary for the agent;
- emits `TicketEscalated`, which notifies the assignee and the team's **leads** (`team_members.role =
  lead`) — or whoever your `EscalationRecipients` binding returns. Automation rules can react to the
  `ticket.escalated` trigger.

## Human takeover {#takeover}

An agent can take over **at any moment**:

```php
Ticketing::takeOver($ticket, $agent);          // assigns an unowned ticket to the agent
POST /tickets/{ticket}/takeover                // over the REST API
```

An agent's public reply is an implicit takeover. Either way `ai_status` becomes `suspended` and the AI never
writes on that ticket again — the check happens under the ticket lock just before every AI write, so a
takeover that lands while the model is thinking still wins. A ticket resolved while the AI was handling it,
with no human reply, ends as `ai_status = resolved` (it counts as resolved by the AI).

## Tracking usage and cost {#usage}

Every turn is a row in `ticketing_ai_turns`: mode, outcome (`answered`, `drafted`, `escalated`, `skipped`,
`failed`), escalation reason, confidence, number of sources, driver and model, input/output tokens, cost and
currency, latency, and a short excerpt + fingerprint of the question (scrubbed on GDPR erasure) that groups
repeated unanswered questions.

| Event | When |
| --- | --- |
| `AiReplied` | the AI answered (ticket or conversation) |
| `AiDraftCreated` | a draft was written (draft mode) |
| `TicketEscalated` | a ticket was handed to humans |
| `HumanTookOver` | an agent took over |
| `AiTurnFailed` | the driver failed |
| `AiUsageRecorded` | a turn finished and its usage was recorded — hook budgets and alerts here |

## Writing a driver {#drivers}

```php
use Selli\Ticketing\Ai\{AiAnswer, AiClassification, AiRequest, AiSource, AiSummary, AiUsage};
use Selli\Ticketing\Contracts\AiDriver;

final class MyDriver implements AiDriver
{
    public function classify(AiRequest $request): AiClassification { /* … */ }

    public function answer(AiRequest $request): AiAnswer
    {
        // $request->question, ->history (public only), ->locale, ->audience (KnowledgeAudience),
        // ->ticket / ->conversation, ->tenant, ->ticketType, ->channel
        $hits = Ticketing::knowledge()->search($request->question, $request->audience, $request->locale, 5);
        // … call your model with the hits as context, treating them as data, not instructions …

        return new AiAnswer($text, $sources, $confidence, new AiUsage('my-model', $in, $out, $cost, 'EUR'));
    }

    public function summarize(AiRequest $request): AiSummary { /* … */ }
}

Ticketing::ai()->extend('mine', fn ($app, array $config) => new MyDriver($config));
// config: 'ai.driver' => 'mine', 'ai.drivers.mine' => [...]
```

- Always pass `$request->audience` to the knowledge base: it is what keeps internal articles out of customer
  answers.
- Fence retrieved content as untrusted data in your prompt, and return `AiUsage` so costs are tracked.
- The built-in `null` driver never answers (every turn escalates); `fake` is deterministic (keyword
  heuristics + knowledge-base search) and scriptable in tests (`queueAnswer()`, `queueClassification()`,
  `failNext()`, `requests()`).

## Common pitfalls

::: callout warning "Watch out"
- **Queues.** With `ai.queue = true` (the default) you need a queue worker; turns run after the surrounding
  transaction commits.
- **SLA.** An AI reply stamps `first_ai_response_at`, not `first_response_at`: SLA clocks measure humans.
- **PII.** Whatever your driver sends to a provider leaves your system. Redact or pick an EU/self-hosted model.
:::

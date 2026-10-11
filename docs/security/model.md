---
title: "Security model"
description: "What the package protects, where the trust boundaries are, and the controls on each of them."
type: concept
---

# Security model

`selli/ticketing` handles conversations between your customers and your team — personal data, sometimes
for several companies in the same database, reachable from public websites, mailboxes and AI models. This
page lists the boundaries and what guards each one. The full v1 adversarial review, finding by finding, is in
[`docs/technical/hardening-v1.md`](/technical/hardening-v1).

::: callout info "In plain words"
A tenant never sees another tenant's data; a customer never sees an internal note or article; whatever
comes from outside — a website visitor, an email, an AI model, an AI agent — is treated as untrusted and
can only do what the person behind it may do.
:::

## Tenants

- Every package table carries the tenant column; with tenancy enabled (`ticketing.tenancy.enabled`, the
  default) every query goes through a global scope that **fails closed** (no tenant ⇒ shared rows only).
  With tenancy disabled — a single-tenant app — the scope adds no filter and every row is visible. Bypasses
  (`withoutTenancy()`) are internal and paired with an explicit tenant check.
- Cross-tenant links are refused: a requester, an assignee, a team, a category, a merged ticket of another
  tenant raise `CrossTenantException`.
- Background work (queues, CLI, inbound email, widget) runs inside the tenant of the row it handles.
- The RAG index keeps each tenant in its own RAG tenant (one-to-one naming).

## People and permissions

- Host-facing entry points — REST API, MCP tools, broadcasting channels — authorize through policies
  (`TicketPolicy`, `KbArticlePolicy`, yours); the facade and actions are the trusted lower layer.
- Customers see their own tickets and only public messages; agents see their tenant. Someone of another
  tenant is never "on" a ticket, whatever row names them.
- Knowledge-base audiences (public, customer, internal, per client) are enforced in SQL for every read and
  search, and re-checked for every semantic hit.

## Public widget

- A public key per site (one tenant, one origin allow-list; an empty list allows no browser), bearer tokens
  per conversation stored as SHA-256 hashes, compared in constant time, expiring.
- Visitor identity is trusted only with an HMAC signature computed on your server; claims are kept apart.
- Rate limits per IP, per key and per conversation start, counted on the resolved key; a honeypot field,
  message caps, a `HumanVerifier` hook (captcha); IPs stored only as salted hashes; CORS answered before
  throttling so the client can read a `429`.

## Email

- Only a signed reply token, or a sender already on the ticket, can thread into an existing ticket; an
  ambiguous subject reference fails closed.
- Automated mail (auto-replies, bounces, no-reply senders, our own notifications) never opens or answers a
  ticket; a sender is rate limited; a Message-ID is ingested once.
- Outbound notifications cannot carry a working link built from customer text (Markdown links are
  neutralised).

## Links, files and webhooks

- CSAT links and thread tokens are HMAC-signed; attachments use temporary signed URLs, with size and type
  checks.
- Webhooks are signed and refuse private, loopback and link-local targets (SSRF guard).

## AI and agents

- The AI receives public messages only, and knowledge the customer's audience may read; retrieved text and
  the conversation are fenced as untrusted data in prompts.
- AI writes are fenced: one turn per message, ownership re-checked under the ticket lock, nothing written
  after a human takeover or on a closed ticket; providers are never called for tickets with the AI off.
- MCP tools act as the authenticated user through the same policies; customer-visible changes need an
  explicit confirmation; articles are drafted, never published.

## Personal data

- Erasure (`anonymiseRequester`, contact anonymisation) scrubs denormalised identity data, AI question
  excerpts, widget traces, and purges the RAG index (with a durable exclusion); export covers tickets,
  conversations and contacts; retention anonymises or deletes on schedule.
- The audit trail is append-only.

## Your part

- Keep `APP_KEY` / `ticketing.mail.token.secret` secret; rotate widget secrets if exposed.
- Put the REST API and MCP server behind your authentication; set your tenant before they run.
- Choose AI and embedding providers (and regions) compatible with your data-protection obligations.
- Review article audiences before publishing.

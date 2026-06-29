---
title: "Core concepts"
description: "The mental model: tickets, subjects, requesters, agents, types and tenants."
type: concept
---

# Core concepts

A handful of ideas explain the whole engine.

## Ticket

The central record. It has a human reference (`SUPPORT-2026-000042`), a status, a priority, a category, and
relations to a subject, a requester, an assignee and a team. Tickets are **soft-deleted** and never lose
their audit history.

## Subject — "what it's about"

A ticket points at one of *your* models through a polymorphic `subject` relation. A ticket can be about an
`Order`, a `Subscription`, a `Server`, or nothing at all (a free-standing request). You set it with
`Ticketing::for($model)->open(...)`.

## Requester & agent — "who"

Identity is agnostic. Two contracts decide roles:

- **`CanRequestTickets`** — opens and owns tickets.
- **`CanActOnTickets`** — works tickets (an agent).

The same model can implement both, or they can be different models entirely. The package never assumes a
`users` table.

## Type — the unit of configuration

A ticket **type** (`support`, `incident`, …) decides which [workflow](/concepts/workflow) applies, the
default priority, and serves as a key for [routing](/concepts/routing), [SLA](/concepts/sla) and
[automation](/concepts/automation) rules. Types are provisioned automatically from config the first time
they're used.

## Tenant — the security boundary

Every package model is tenant-scoped by a global scope that **fails closed**: with no tenant resolved, a
query sees only shared rows, never another tenant's. Single-tenant apps simply leave tenancy on with one
tenant (or turn it off). See [Multi-tenancy](/concepts/tenancy).

## The three-layer API

1. **Facade** — `Ticketing::open(...)`, the common path.
2. **Actions** — `OpenTicket`, `TransitionTicket`, … injectable, unit-testable, the fine-control layer.
3. **Events** — `TicketOpened`, `StateTransitioned`, … the extension layer. SLA, routing, notifications,
   automation and broadcasting are all just listeners.

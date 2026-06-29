---
title: "Multi-tenancy"
description: "A global tenant scope that fails closed — the first line of defence against data leakage."
type: concept
---

# Multi-tenancy

Multi-tenancy is **on by default** and is treated as a security boundary, not a convenience.

## Fail-closed scoping

Every package model carries a tenant column and a global `TenantScope`. The safety properties:

- With a tenant resolved, only that tenant's rows (plus shared rows, if `allow_shared` is on) are visible.
- With **no** tenant resolved but tenancy enabled, only shared (null-tenant) rows are visible — **never**
  another tenant's. A missing context fails closed, not open.

Architectural tests assert no package model can be queried without the scope (outside the explicit
`withoutTenancy()` escape hatch).

## Resolving the tenant

The current tenant comes from a `TenantResolver` contract. The default resolves it from the authenticated
user's tenant column. For CLI, queues and inbound email — where there is no authenticated user — operations
accept an explicit tenant, and the escalation sweep iterates per tenant:

```php
app(\Selli\Ticketing\Tenancy\TenantContext::class)->forTenant(5, function () {
    Ticketing::open(type: 'support', title: '...', requester: $user);
});
```

## Single-tenant mode

Set `ticketing.tenancy.enabled` to `false` for a single-pool deployment. Role/identity checks (authorization,
broadcast channels) then apply without a tenant match.

## Configuration

```php
'tenancy' => [
    'enabled' => true,
    'resolver' => \Selli\Ticketing\Tenancy\DefaultTenantResolver::class,
    'column' => 'tenant_id',
    'allow_shared' => true,                  // permit null-tenant (shared) rows
    'require_tenant_for_writes' => false,    // fail closed on a tenant-less write
],
```

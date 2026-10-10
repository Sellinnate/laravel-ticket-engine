---
title: "Versioning & support"
description: "The semver policy, what counts as a breaking change, deprecations and the supported PHP/Laravel versions."
type: reference
---

# Versioning & support

`selli/ticketing` follows [Semantic Versioning 2.0](https://semver.org) from `1.0.0` on, applied to the
[public API](/reference/public-api).

| Release | Contains | Example |
| --- | --- | --- |
| **Patch** `1.0.x` | Bug and security fixes only. No new config keys, no migrations. | `1.0.3` |
| **Minor** `1.x.0` | New features, new optional config keys, new **additive** migrations, deprecations. | `1.2.0` |
| **Major** `x.0.0` | Removals and breaking changes, each listed in `UPGRADE.md`. | `2.0.0` |

## What counts as breaking

- Removing or renaming anything on the public API page, or changing a public method signature in a way that
  breaks existing callers.
- Adding a method to a public **contract** (your implementations would stop compiling).
- Changing a column type, dropping a column or table, or editing a published migration.
- Renaming or removing a config key, or changing a default in a way that alters behaviour.
- Removing a field from a `v1` REST response, or changing its type.

Not breaking: adding optional parameters at the end of a method, new events, new config keys with
backward-compatible defaults, new nullable columns, new response fields, and anything marked `@internal`.

## Deprecations

A feature is deprecated in a minor release (a `@deprecated` tag, a CHANGELOG entry and, where cheap, a
`E_USER_DEPRECATED` notice) and removed no earlier than the next major release.

## Supported versions

| `selli/ticketing` | PHP | Laravel | Databases tested in CI | Security fixes |
| --- | --- | --- | --- | --- |
| `1.x` | 8.3, 8.4, 8.5 | 11, 12, 13 | SQLite, MySQL 8.4, PostgreSQL 16 | ✅ |
| `< 1.0` (unreleased) | — | — | — | ❌ |

Every pull request runs the full suite on PHP 8.3–8.5 × Laravel 11–13 × lowest/stable dependencies on Ubuntu
and Windows, plus MySQL and PostgreSQL jobs.

## Optional integrations

Optional packages (`suggest` in `composer.json`) are integrated behind contracts and only activate when the
package is installed. Their supported version ranges are documented on each integration page and follow
the same rules: a minor release of `selli/ticketing` may add support for a new major version of an
integration, never drop one.

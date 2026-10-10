# Contributing

Thanks for helping! Contributions are welcome through pull requests on
[GitHub](https://github.com/Sellinnate/laravel-ticket-engine).

## Before you start

- For anything larger than a small fix, open an issue first to agree on the approach.
- Security issues: see [SECURITY.md](SECURITY.md) — never in a public issue.
- Read the [public API](https://laravel-ticketing.selli.io/reference/public-api) and
  [versioning](https://laravel-ticketing.selli.io/reference/versioning) pages: within `1.x` nothing public
  may break, migrations are only ever added, config keys are only ever added.

## Development setup

```bash
git clone git@github.com:Sellinnate/laravel-ticket-engine.git && cd laravel-ticket-engine
composer install
npm ci            # docs site and the JS widget client tests
```

## Quality gates (all must pass — CI runs the same)

```bash
composer test                     # Pest, SQLite in memory
composer analyse                  # PHPStan (Larastan) level 8, no baseline
vendor/bin/pint --test            # code style
composer validate --strict
npm run test:js                   # reference widget client
npm run docs:build                # documentation site
```

Run the suite against real databases too — JSON paths, string/integer comparisons, NULLs in unique
indexes and row locks behave differently from SQLite:

```bash
TICKETING_TEST_DB=mysql TICKETING_TEST_DB_DATABASE=ticketing_test TICKETING_TEST_DB_USERNAME=root vendor/bin/pest
TICKETING_TEST_DB=pgsql TICKETING_TEST_DB_DATABASE=ticketing_test TICKETING_TEST_DB_USERNAME=postgres vendor/bin/pest
```

## Pull requests

- One topic per PR, with tests for every new or fixed behaviour (coverage stays ≥ 90 %).
- Follow the existing style: small actions, events after commit, tenant-safe queries (`withoutTenancy()` only
  with an explicit tenant check), policies on every host-facing entry point.
- New database columns or indexes go in a **new** migration; never edit a published one.
- Update the docs (`docs/`) and `CHANGELOG.md` (under *Unreleased*) in the same PR; a behaviour change also
  needs an `UPGRADE.md` note.
- Commit messages: one imperative English sentence (`fix: …`, `feat: …`, `docs: …`).

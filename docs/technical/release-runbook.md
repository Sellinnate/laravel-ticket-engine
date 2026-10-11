---
title: "Release runbook"
description: "Step-by-step: from the reviewed release branch to v1.0.0 on Packagist and the documentation online."
type: reference
---

# Release runbook

How to publish a `selli/ticketing` release — written for 1.0.0, valid for every later one. Each step has
the exact commands and how to check it worked.

## 0. Preconditions

- [ ] The release pull request (`feature/v1-stable` → `main`) is approved and **every check is green**:
      the test matrix (PHP 8.3–8.5 × Laravel 11–13, lowest/stable, Linux/Windows), MySQL and PostgreSQL,
      coverage ≥ 90 %, PHPStan, code style, the widget client.
- [ ] `CHANGELOG.md` has the `## 1.0.0 - <date>` section, dated the day of the tag (change it if the tag
      comes later); `UPGRADE.md` covers every behaviour change.
- [ ] `composer validate --strict` passes.

## 1. Merge

Merge the release pull request into `main` (merge commit or squash — the history of the phases is in the
pull requests). Then locally:

```bash
git switch main && git pull
composer install && composer test && vendor/bin/phpstan analyse && vendor/bin/pint --test
```

## 2. Tag

```bash
git tag -a v1.0.0 -m "selli/ticketing 1.0.0"
git push origin v1.0.0
```

Check: `git ls-remote --tags origin v1.0.0` shows the tag.

## 3. GitHub release

```bash
# the 1.0.0 section of the changelog as release notes
awk '/^## 1.0.0/{f=1;next} /^## /{f=0} f' CHANGELOG.md > /tmp/release-notes.md
gh release create v1.0.0 --title "v1.0.0" --notes-file /tmp/release-notes.md --verify-tag
```

::: callout warning "Changelog workflow"
The *Update Changelog* workflow runs when a release is published and prepends a section with the release
notes. `CHANGELOG.md` already has the 1.0.0 section, so either disable that workflow for this release
(*Actions → Update Changelog → Disable workflow*, re-enable afterwards) or delete the duplicate heading it
commits to `main`.
:::

## 4. Packagist

The GitHub webhook updates Packagist on push. Check:

```bash
curl -s https://repo.packagist.org/p2/selli/ticketing.json | jq -r '.packages["selli/ticketing"][0].version'   # v1.0.0
```

or open <https://packagist.org/packages/selli/ticketing>. If the version is missing after a few minutes,
press *Update* on the Packagist page (maintainer account) and check the webhook under the repository's
*Settings → Webhooks*.

Smoke test in a fresh application:

```bash
composer create-project laravel/laravel /tmp/ticketing-smoke && cd /tmp/ticketing-smoke
composer require selli/ticketing:^1.0
php artisan ticketing:install --no-interaction && php artisan migrate && php artisan ticketing:demo
```

The dist archive must not contain `tests/`, `docs/`, `art/`, `site/` or the docs tooling (see
`.gitattributes`): `composer show selli/ticketing --path` and list it.

## 5. Documentation site

Built with docmd, served by the Cloudflare Worker `laravel-ticketing` (`wrangler.jsonc`) from `./site`, with
the generated `404.html` for unknown paths.

```bash
npm ci
npm run docs:build                 # writes ./site (git-ignored)
npx wrangler login                 # once, with the Selli Cloudflare account
npx wrangler deploy                # deploys the Worker "laravel-ticketing"
```

First deployment only: in the Cloudflare dashboard, *Workers & Pages → laravel-ticketing → Settings →
Domains & Routes → Add custom domain* `laravel-ticketing.selli.io` (the `selli.io` zone must be on the same
account). Then check:

```bash
curl -sI https://laravel-ticketing.selli.io/ | head -1                    # HTTP/2 200
curl -sI https://laravel-ticketing.selli.io/does-not-exist | head -1      # HTTP/2 404 (the docmd 404 page)
```

Later releases: `npm run docs:build && npx wrangler deploy` after merging documentation changes.

## 6. After the release

- [ ] Announce internally; tell product teams (e.g. SalesMind) which version to require: `^1.0`.
- [ ] Open the `## Unreleased` section in `CHANGELOG.md` for the next changes.
- [ ] Watch the issue tracker and the security advisories for the first days.

## Rolling back

A published tag is never moved or deleted (Packagist and lock files may already point at it). Fix forward:
release `1.0.1` with the fix, and if needed mark `1.0.0` as broken in the GitHub release notes.

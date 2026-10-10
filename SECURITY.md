# Security Policy

## Supported versions

| Version | Supported |
| --- | --- |
| 1.x | ✅ security fixes |
| `dev-main` before 1.0.0 | ❌ upgrade to 1.x |

Security fixes are released as patch versions of the latest `1.x` minor.

## Reporting a vulnerability

**Please do not open a public issue.** Report it privately through GitHub:
[Security → Report a vulnerability](https://github.com/Sellinnate/laravel-ticket-engine/security/advisories/new).

Include what you found, the affected version, and how to reproduce it (a failing test is ideal). We
acknowledge reports within a few working days, keep you informed while we fix it, and credit you in the
advisory unless you prefer otherwise. Please give us a reasonable time to release a fix before any public
disclosure.

## Scope

In scope: the `selli/ticketing` package — tenant isolation, authorization (policies, REST API, MCP tools),
the public widget API (keys, origins, signed identities, tokens, rate limits), inbound email processing,
signed links (CSAT, attachments, thread tokens), webhooks, GDPR erasure, and the AI / RAG bridges
(what reaches a model or a customer).

Out of scope: your application's own authentication, the configuration of your mail provider, queue or
vector store, and third-party packages (report those to their maintainers).

## Hardening notes

The security model and the v1 adversarial review are documented in the
[security pages](https://laravel-ticketing.selli.io/security/model) of the documentation.

# Codex Project Instructions

## Repository Role

- This repository is the WordPress bridge plugin for the Omnichannel workspace.
- Display name may be `OmniChannel SEO AI Bridge`; main plugin file is `omi-seo-ai-bridge.php`.
- The Laravel backend at `..\omnichannel-client` is canonical for business workflow, publishing schedule, Site Sync orchestration, Agent/MCP, and core contracts. Plugin ZIP distribution is GitHub Releases only.
- This plugin exposes WordPress REST endpoints, reads/writes WP content/media/SEO metadata, pushes local changes to Laravel, and receives Laravel writes through token-authenticated API calls.

## Source Of Truth

- Code/runtime wins for plugin bootstrap, hooks, REST routes, auth, and packaging behavior.
- For backend contracts, read `..\omnichannel-backend\AGENTS.md`, `..\omnichannel-backend\docs\README.md`, and the relevant canonical backend docs.
- Plugin contract index: `docs/site_sync_v1_contract.json`.
- Do not use backend archive docs as source of truth.

## Mandatory Cross-Repo Checks

- For any non-trivial task, Codex MUST query `codebase-memory` near the start for relevant prior decisions/context when the MCP server is available.
- `codebase-memory` is NOT source of truth. Codex MUST verify memory results against current plugin code, backend code, and canonical backend docs before acting.
- For REST route, payload, authentication, Site Sync, publishing, article import/export, media, updater, or capability changes, Codex MUST inspect both repositories.
- Backend files to check commonly include `..\omnichannel-backend\app\Addons\SeoContentAi\routes\api.php`, relevant controllers/services, and canonical docs.
- Plugin files to check commonly include `omi-seo-ai-bridge.php`, `includes/class-rest-controller.php`, `includes/class-laravel-push-sync.php`, `includes/class-site-sync-v2-provider.php`, `includes/class-site-sync-outbox.php`, `includes/class-capability-manifest.php`, and `includes/class-plugin-updater.php`.
- Codex MUST NOT assume backward compatibility until current consumers are checked.
- Codex MUST NOT delete legacy endpoints, fallback headers, adapters, handlers, or compatibility code until zero caller is proven across both repos.

## Safety Boundaries

- Codex MUST NOT read tokens, passwords, private keys, `.env`, credentials, or live WP option values containing secrets.
- Codex MUST NOT store secrets, `.env` values, tokens, production logs, credentials, or speculation in `codebase-memory`.
- Codex MUST write memory ONLY WHEN a durable decision has been verified from code/docs or explicitly confirmed by the user.
- Codex MUST NOT deploy, upload, commit, push, install dependencies, run migrations, alter databases, package releases, or bump plugin version unless explicitly requested.
- Codex MUST NOT automatically increment the plugin version. Version changes require explicit release/versioning request.
- Codex MUST NOT package plugin ZIPs, create GitHub Releases, or bump plugin version unless explicitly requested.
- Codex MUST NOT use backend `.secure\deploy-diff.ps1` for plugin files; that script only tracks `..\omnichannel-backend`.

## Plugin Architecture

- Main bootstrap/header/version constant: `omi-seo-ai-bridge.php`.
- REST namespace: `omi-seo-ai/v1`.
- Read endpoints use read bearer token; write endpoints use write bearer token.
- Token extraction supports `Authorization: Bearer`, `x-omi-read-token`, and `x-omi-write-token`; do not remove fallbacks without proving all callers migrated.
- Site Sync v2 surfaces include capabilities, profile, delta, batches, manifest, taxonomy terms, and Laravel delta callback.
- Laravel owns publishing schedule; plugin should receive publish/write outcomes, not become schedule source of truth.
- WP-Cron outbox supports plugin-side delta push, but Laravel scheduler/workers remain required for backend orchestration.

## Coding Rules

- New PHP files MUST use `declare(strict_types=1);`.
- Preserve WordPress coding/security basics: capability checks, nonces for admin actions, sanitization, escaping, and `hash_equals` for token comparison.
- Avoid hard-coded secrets or environment-specific URLs beyond existing option/config patterns.
- Keep changes focused; do not rewrite the plugin bootstrap or large classes without a clear need.

## Verification

- This repo currently has no `composer.json`, `package.json`, or `phpunit.xml`; do not invent test/build commands.
- For source changes, inspect syntax-sensitive PHP around changed methods and recommend a WP/PHP syntax check only when available in the user's environment.
- Plugin packaging is manual ZIP uploaded to GitHub Releases (`wp-seo-ai-x.y.z.zip`, folder `wp-seo-ai/`). Do not host packages on Laravel.
- For contract changes, also use backend verification guidance in `..\omnichannel-backend\AGENTS.md`.

## Skills

- Use `$plugin-release-package` only for explicit plugin version/package/release requests.
- Use backend `$cross-repo-contract-change` for API/auth/site-sync/publishing/media/updater/capability contract changes involving both repos.

## Final Response

- Final responses MUST be concise and in Vietnamese unless the user asks otherwise.
- Include files changed, cross-repo files checked when relevant, verification status, and confirmation when version/package/deploy were not run.

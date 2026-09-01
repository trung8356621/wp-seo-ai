---
name: plugin-release-package
description: "WordPress bridge plugin versioning — bump version on every code change; build/package only when user asks to publish release."
---

# Purpose

Keep `wp-seo-ai` version in sync with code changes so GitHub tag + Release ZIP updates work on WordPress sites.

# Version bump policy (MANDATORY on code changes)

**Any edit** to plugin code under `wp-seo-ai/` (PHP, includes, tests that change runtime behavior, REST/profile/sync contracts, etc.) MUST bump version in the same session — **before finishing the task**.

Do **not** wait for the user to say "release" or "bump version".

Exceptions (no bump):
- docs-only / comments-only with zero runtime effect (rare — when unsure, bump patch)
- user explicitly says "do not bump version"

# Version targets (keep synchronized)

File: `omi-seo-ai-bridge.php`

1. Plugin header: `Version:           {version}`
2. Constant: `define('OMI_SEO_AI_BRIDGE_VERSION', '{version}');`

Both MUST match exactly.

# Bump rule

Default: **patch** increment on the third segment.

Example: `1.0.86` → `1.0.87`

Use minor/major only when user explicitly requests.

# Required context

- Plugin main file: `omi-seo-ai-bridge.php`
- Distribution: GitHub Releases — `https://github.com/trung8356621/wp-seo-ai`
- Tag: `{version}` (example `1.0.87`; `v1.0.87` also accepted by updater)
- Canonical asset: `wp-seo-ai-{version}.zip`
- ZIP root folder: `wp-seo-ai/`
- Main file inside ZIP: `wp-seo-ai/omi-seo-ai-bridge.php`
- Legacy read-compat only: `omi-seo-ai-bridge-{version}.zip` (do not generate for new releases)
- Laravel observes/triggers updates; it does not host ZIP packages

# Agent workflow after code change

1. Read current `Version:` and `OMI_SEO_AI_BRIDGE_VERSION`.
2. Bump patch (unless user specified otherwise).
3. Write both fields in `omi-seo-ai-bridge.php`.
4. In final report: state **old → new** version and remind publish steps below.

# Publish workflow (user action — do NOT run unless explicitly requested)

User must git push + GitHub Release for WP sites to receive update:

1. Commit (include version bump in same commit as code change when possible).
2. Tag Git: `{version}`.
3. Build: `php bin/build-plugin-release.php {version}`
   - Output: `D:/work/build/wp-seo-ai-{version}.zip` (never under plugin repo)
4. Verify: `php tests/ReleasePackageContractTest.php`
5. Create GitHub Release for tag; upload **exact** asset `wp-seo-ai-{version}.zip`
6. Force refresh update check on WP (`force_refresh=1` / Check GitHub)

Do **not** commit, push, tag, build ZIP, or create GitHub Release unless user explicitly asks in that turn.

Never upload unversioned `wp-seo-ai.zip` or GitHub source archives as the installer.

# Trigger for build/package only

Use build + contract test steps only when user explicitly asks to **package**, **publish**, or **create GitHub Release**.

Ordinary code edits: **version bump only** (no ZIP unless requested).

# Expected final report (after code change)

- Old version → new version
- Files changed including `omi-seo-ai-bridge.php`
- Reminder: commit → tag `{version}` → build ZIP → GitHub Release (if not done)
- Confirmation: no commit/push/release unless user requested

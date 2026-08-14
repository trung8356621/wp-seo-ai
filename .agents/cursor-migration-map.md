# Cursor Migration Map - WordPress Plugin

| Cursor source | Codex destination | Status | Notes |
|---|---|---|---|
| `.cursorrules` | `AGENTS.md`; `.agents/skills/plugin-release-package/SKILL.md` | migrated | Converted auto-version/package workflow into explicit-release-only workflow per latest safety rules. |
| `cmd.md` | `.agents/cursor-migration-map.md` | needs-review | Local symlink note retained in map only; not an always-on Codex rule. |
| `docs/site_sync_v1_contract.json` | `AGENTS.md` | merged | Contract endpoints and minimum bridge role referenced, not copied in full. |
| `omi-seo-ai-bridge.php` | `AGENTS.md`; `.agents/skills/plugin-release-package/SKILL.md` | merged | Bootstrap/header/version/constants and plugin structure verified. |
| `includes/class-rest-controller.php` | `AGENTS.md` | merged | REST namespace, auth, endpoint surfaces verified. |
| `includes/class-laravel-push-sync.php` | `AGENTS.md` | merged | WordPress-to-Laravel push role verified. |
| `includes/class-site-sync-outbox.php` | `AGENTS.md` | merged | Outbox/WP-Cron delta role verified. |
| `includes/class-plugin-updater.php` | `AGENTS.md`; `.agents/skills/plugin-release-package/SKILL.md` | merged | GitHub Release updater only; Laravel update server removed. |
| `README.md` | `.agents/cursor-migration-map.md` | skipped-obsolete | File not present. |
| `.cursor/rules/**/*.mdc` | `.agents/cursor-migration-map.md` | skipped-obsolete | Directory not present. |
| `.cursor/commands/**/*.md` | `.agents/cursor-migration-map.md` | skipped-obsolete | Directory not present. |
| `.cursor/skills/**` | `.agents/cursor-migration-map.md` | skipped-obsolete | Directory not present. |
| `composer.json` | `.agents/cursor-migration-map.md` | skipped-obsolete | File not present. |
| `package.json` | `.agents/cursor-migration-map.md` | skipped-obsolete | File not present. |
| `phpunit.xml` | `.agents/cursor-migration-map.md` | skipped-obsolete | File not present. |

## Conflict Log

| Source A | Source B | Decision | Reason | User review |
|---|---|---|---|---|
| Plugin `.cursorrules` says always auto-increment version and remind packaging | Migration request says do not bump version/package unless explicitly requested | Explicit request required for version/package | Latest safety scope wins and prevents accidental release. | No |
| Backend deploy-diff workflow | Real `.secure\deploy-diff.ps1` only accepts backend project files | Plugin AGENTS says backend deploy-diff does not track plugin | Script path normalization rejects sibling repo. | No |

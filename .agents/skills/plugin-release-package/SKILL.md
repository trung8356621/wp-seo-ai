---
name: plugin-release-package
description: "Trigger only for explicit WordPress plugin release, package, zip, version bump, update server, compress_plugin, or publish plugin requests. Do not use for ordinary plugin edits, contract checks, or documentation-only tasks."
---

# Purpose

Handle explicit release/package work for the WordPress bridge plugin.

# Trigger conditions

Use only when the user explicitly asks to bump version, create a plugin zip, package the plugin, update the Laravel plugin update server, run `compress_plugin.ps1`, or release the WordPress plugin.

Do not trigger for normal code edits.

# Required context

- Plugin main file: `omi-seo-ai-bridge.php`.
- Current plugin header `Version:` and `OMI_SEO_AI_BRIDGE_VERSION` constant.
- Packaging script: `..\omnichannel-backend\compress_plugin.ps1`.
- Backend update-server location is defined by that script; do not infer a different target.

# Workflow

1. Confirm the user explicitly requested release/package/versioning.
2. Read the current version from plugin header and constant.
3. Choose version bump only from user instruction. If user did not specify bump type, ask before changing version.
4. Keep header version and `OMI_SEO_AI_BRIDGE_VERSION` synchronized.
5. Run packaging script only after explicit approval/request:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "..\omnichannel-backend\compress_plugin.ps1"
```

6. Check generated files in backend update-server storage only when packaging was requested.

# Verification

- Confirm version header and constant match.
- Confirm package command result when run.
- Check both repo statuses.

# Safety and approval boundaries

- MUST NOT bump version automatically.
- MUST NOT package automatically after ordinary edits.
- MUST NOT deploy, FTP/SFTP upload, commit, or push unless separately and explicitly requested.
- MUST NOT read credentials or live tokens.

# Expected final report

- Old version and new version, if changed.
- Whether packaging ran.
- Generated package path if packaging ran.
- Files changed in plugin and backend update-server storage.

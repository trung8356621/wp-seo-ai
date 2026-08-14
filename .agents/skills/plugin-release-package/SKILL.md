---
name: plugin-release-package
description: "Trigger only for explicit WordPress plugin version bump or GitHub Release packaging requests. Do not use for ordinary plugin edits or contract checks."
---

# Purpose

Handle explicit version/release work for the WordPress bridge plugin.

# Trigger conditions

Use only when the user explicitly asks to bump version or publish a GitHub Release for `wp-seo-ai`.

Do not trigger for normal code edits.

# Required context

- Plugin main file: `omi-seo-ai-bridge.php`.
- Current plugin header `Version:` and `OMI_SEO_AI_BRIDGE_VERSION` constant.
- Distribution: GitHub Releases at `https://github.com/trung8356621/wp-seo-ai`.
- Expected asset name: `omi-seo-ai-bridge-{version}.zip` with plugin folder `omi-seo-ai-bridge/` at ZIP root.

# Workflow

1. Confirm the user explicitly requested release/versioning.
2. Read the current version from plugin header and constant.
3. Choose version bump only from user instruction.
4. Keep header version and `OMI_SEO_AI_BRIDGE_VERSION` synchronized.
5. Do not create GitHub Releases, upload ZIP, commit, or push unless separately requested.
6. There is no `compress_plugin.ps1` and no Laravel update-server packaging.

# Safety and approval boundaries

- MUST NOT bump version automatically.
- MUST NOT package automatically after ordinary edits.
- MUST NOT deploy, commit, or push unless separately and explicitly requested.

# Expected final report

- Old version and new version, if changed.
- Expected GitHub tag `vx.y.z` and ZIP filename.
- Confirmation that Laravel was not used as a package host.

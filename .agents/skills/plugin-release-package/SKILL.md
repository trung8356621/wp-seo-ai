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
- Current plugin header `Version:` and `OMI_SEO_AI_BRIDGE_VERSION` constant (must match).
- Distribution: GitHub Releases at `https://github.com/trung8356621/wp-seo-ai`.
- Canonical asset: `wp-seo-ai-{version}.zip`
- ZIP root folder: `wp-seo-ai/`
- Main file inside ZIP: `wp-seo-ai/omi-seo-ai-bridge.php`
- Legacy read-compat only: `omi-seo-ai-bridge-{version}.zip` (do not generate for new releases).

# Workflow

1. Confirm the user explicitly requested release/versioning.
2. Read the current version from plugin header and constant; keep them synchronized.
3. Choose version bump only from user instruction.
4. Build package:
   - `php bin/build-plugin-release.php {version}`
   - Output: `D:/work/build/wp-seo-ai-{version}.zip` (never under the plugin repo)
5. Run `php tests/ReleasePackageContractTest.php`.
6. Do not create GitHub Releases, upload ZIP, commit, or push unless separately requested.
7. Never upload unversioned `wp-seo-ai.zip` or GitHub source archives as the installer.

# Safety and approval boundaries

- MUST NOT bump version automatically.
- MUST NOT package automatically after ordinary edits.
- MUST NOT deploy, commit, or push unless separately and explicitly requested.

# Expected final report

- Old version and new version, if changed.
- Expected GitHub tag `{version}` and ZIP filename `wp-seo-ai-{version}.zip`.
- Confirmation that Laravel was not used as a package host.

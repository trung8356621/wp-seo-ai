<?php

declare(strict_types=1);

/**
 * Release automation planning contract (no network / no git mutations).
 *
 * php tests/ReleaseAutomationContractTest.php
 */

define('ABSPATH', __DIR__.'/');

require_once dirname(__DIR__).'/includes/class-github-release-client.php';
require_once dirname(__DIR__).'/includes/class-release-package.php';
require_once dirname(__DIR__).'/includes/class-release-automation.php';

use OmiSeoAiBridge\GitHub_Release_Client;
use OmiSeoAiBridge\Release_Automation;
use OmiSeoAiBridge\Release_Package;

$failures = 0;

function omi_rel_auto_assert(bool $ok, string $message): void
{
    global $failures;
    if ($ok) {
        echo "PASS  {$message}\n";

        return;
    }
    $failures++;
    echo "FAIL  {$message}\n";
}

$root = dirname(__DIR__);

omi_rel_auto_assert(
    Release_Automation::bump_semver('1.0.89', 'patch') === '1.0.90',
    'patch 1.0.89 -> 1.0.90'
);
omi_rel_auto_assert(
    Release_Automation::bump_semver('1.0.89', 'minor') === '1.1.0',
    'minor 1.0.89 -> 1.1.0'
);
omi_rel_auto_assert(
    Release_Automation::bump_semver('1.0.89', 'major') === '2.0.0',
    'major 1.0.89 -> 2.0.0'
);
omi_rel_auto_assert(
    Release_Automation::bump_semver('1.0.89', 'nope') === null,
    'invalid bump rejected'
);

$plan = Release_Automation::plan($root, 'patch');
omi_rel_auto_assert(($plan['ok'] ?? false) === true, 'plan ok for current tree');
omi_rel_auto_assert(is_string($plan['current_version'] ?? null), 'plan has current_version');
omi_rel_auto_assert(is_string($plan['next_version'] ?? null), 'plan has next_version');
omi_rel_auto_assert(
    ($plan['tag'] ?? null) === ($plan['next_version'] ?? null),
    'tag equals next_version (no v-prefix)'
);
omi_rel_auto_assert(
    ($plan['asset_name'] ?? '') === GitHub_Release_Client::expected_asset_name((string) $plan['next_version']),
    'asset uses hyphen updater contract wp-seo-ai-{ver}.zip'
);
omi_rel_auto_assert(
    str_contains((string) ($plan['asset_name'] ?? ''), 'wp-seo-ai-'),
    'asset prefix wp-seo-ai-'
);
omi_rel_auto_assert(
    ! str_contains((string) ($plan['asset_name'] ?? ''), 'wp-seo-ai.'),
    'asset is NOT dotted wp-seo-ai.{ver}.zip'
);
omi_rel_auto_assert(
    in_array(Release_Automation::MAIN_FILE, $plan['files_to_change'] ?? [], true),
    'files_to_change includes main plugin file'
);
omi_rel_auto_assert(
    is_array($plan['test_commands'] ?? null) && $plan['test_commands'] !== [],
    'plan lists standalone tests'
);
omi_rel_auto_assert(
    str_contains((string) ($plan['build_command'] ?? ''), 'build-plugin-release.php'),
    'plan includes build command'
);

$bad = Release_Automation::plan($root, 'weird');
omi_rel_auto_assert(($bad['ok'] ?? true) === false, 'invalid bump plan fails');

$ssot = Release_Automation::read_canonical_version($root);
omi_rel_auto_assert(($ssot['ok'] ?? false) === true, 'canonical version SSOT ok');
omi_rel_auto_assert(
    ($ssot['header'] ?? '') === ($ssot['constant'] ?? ''),
    'header equals constant'
);

$tmp = sys_get_temp_dir().'/omi-rel-auto-'.bin2hex(random_bytes(4));
mkdir($tmp, 0775, true);
$main = $tmp.'/'.Release_Automation::MAIN_FILE;
file_put_contents(
    $main,
    "<?php\n/**\n * Version:           1.2.3\n */\ndefine('OMI_SEO_AI_BRIDGE_VERSION', '1.2.3');\n"
);
$wrote = Release_Automation::write_canonical_version($tmp, '1.2.4');
omi_rel_auto_assert(($wrote['ok'] ?? false) === true, 'write_canonical_version ok');
$after = Release_Package::read_source_versions($tmp);
omi_rel_auto_assert(($after['header'] ?? '') === '1.2.4', 'header bumped');
omi_rel_auto_assert(($after['constant'] ?? '') === '1.2.4', 'constant bumped');
@unlink($main);
@rmdir($tmp);

$releaseBin = (string) file_get_contents($root.'/bin/release.php');
omi_rel_auto_assert(str_contains($releaseBin, '--dry-run'), 'release.php supports dry-run');
omi_rel_auto_assert(str_contains($releaseBin, '--execute'), 'release.php supports execute');
omi_rel_auto_assert(str_contains($releaseBin, 'Refusing to overwrite'), 'release.php refuses tag overwrite');
omi_rel_auto_assert(str_contains($releaseBin, 'Working tree is dirty'), 'release.php guards dirty tree on execute');

$workflow = (string) file_get_contents($root.'/.github/workflows/release.yml');
omi_rel_auto_assert(str_contains($workflow, 'workflow_dispatch'), 'workflow_dispatch enabled');
omi_rel_auto_assert(str_contains($workflow, 'bump'), 'workflow bump input');
omi_rel_auto_assert(str_contains($workflow, 'dry_run'), 'workflow dry_run input');
omi_rel_auto_assert(str_contains($workflow, 'bin/release.php'), 'workflow calls release.php');

$helper = (string) file_get_contents($root.'/scripts/release.ps1');
omi_rel_auto_assert(str_contains($helper, 'workflow run release.yml'), 'ps1 can dispatch workflow');
omi_rel_auto_assert(str_contains($helper, 'bin\\release.php') || str_contains($helper, 'bin/release.php'), 'ps1 can local dry-run');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} assertion(s) failed\n");
    exit(1);
}

echo "OK\n";

<?php

declare(strict_types=1);

/**
 * Safe wp-seo-ai release orchestrator (dry-run by default for local use).
 *
 * Usage:
 *   php bin/release.php --dry-run --bump=patch
 *   php bin/release.php --bump=patch --execute   # CI only; pushes tag + creates release
 *
 * Does not store secrets. Uses git + gh when --execute.
 */

$root = dirname(__DIR__);

if (! defined('ABSPATH')) {
    define('ABSPATH', $root.DIRECTORY_SEPARATOR);
}

require_once $root.'/includes/class-github-release-client.php';
require_once $root.'/includes/class-release-package.php';
require_once $root.'/includes/class-release-automation.php';

use OmiSeoAiBridge\Release_Automation;
use OmiSeoAiBridge\Release_Package;

/**
 * @param  list<string>  $argv
 * @return array{bump:string,dry_run:bool,execute:bool,skip_tests:bool,dist:?string,help:bool}
 */
function omi_release_parse_args(array $argv): array
{
    $bump = 'patch';
    $dryRun = true;
    $execute = false;
    $skipTests = false;
    $dist = null;
    $help = false;

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $help = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $dryRun = true;
            $execute = false;
            continue;
        }
        if ($arg === '--execute') {
            $execute = true;
            $dryRun = false;
            continue;
        }
        if ($arg === '--skip-tests') {
            $skipTests = true;
            continue;
        }
        if (str_starts_with($arg, '--bump=')) {
            $bump = strtolower(trim(substr($arg, 7)));
            continue;
        }
        if (str_starts_with($arg, '--dist=')) {
            $dist = substr($arg, 7);
            continue;
        }
        if (in_array($arg, Release_Automation::BUMP_LEVELS, true)) {
            $bump = $arg;
        }
    }

    return [
        'bump' => $bump,
        'dry_run' => $dryRun,
        'execute' => $execute,
        'skip_tests' => $skipTests,
        'dist' => $dist,
        'help' => $help,
    ];
}

/**
 * @return array{ok:bool,output:string,code:int}
 */
function omi_release_run(string $command, string $cwd): array
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($command, $descriptor, $pipes, $cwd);
    if (! is_resource($proc)) {
        return ['ok' => false, 'output' => 'Failed to start: '.$command, 'code' => 1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]) ?: '';
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[2]);
    $code = proc_close($proc);
    $output = trim($stdout.(($stderr !== '') ? "\n".$stderr : ''));

    return ['ok' => $code === 0, 'output' => $output, 'code' => $code];
}

function omi_release_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "RELEASE FAILED: {$message}\n");
    exit($code);
}

/**
 * @return array{ok:bool,value:string,error:?string}
 */
function omi_release_git(string $root, string $args): array
{
    $cmd = 'git -c safe.directory='.escapeshellarg($root).' -C '.escapeshellarg($root).' '.$args;
    $result = omi_release_run($cmd, $root);
    if (! $result['ok']) {
        return ['ok' => false, 'value' => '', 'error' => $result['output'] !== '' ? $result['output'] : 'git failed'];
    }

    return ['ok' => true, 'value' => trim($result['output']), 'error' => null];
}

$opts = omi_release_parse_args($argv);
if ($opts['help']) {
    echo <<<TXT
wp-seo-ai release orchestrator

  php bin/release.php --dry-run --bump=patch|minor|major
  php bin/release.php --execute --bump=patch [--dist=path]

Default is --dry-run (no commit/tag/push/release).
--execute is intended for GitHub Actions on main after guards pass.

Canonical asset: wp-seo-ai-{version}.zip  (hyphen — updater contract)
Tag: {version}  (no v-prefix)

TXT;
    exit(0);
}

$plan = Release_Automation::plan($root, $opts['bump']);
if (! $plan['ok']) {
    foreach ($plan['errors'] as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    omi_release_fail('Release plan invalid.');
}

$current = (string) $plan['current_version'];
$next = (string) $plan['next_version'];
$tag = (string) $plan['tag'];
$asset = (string) $plan['asset_name'];

echo "=== wp-seo-ai release plan ===\n";
echo 'mode: '.($opts['dry_run'] ? 'DRY-RUN' : 'EXECUTE')."\n";
echo "bump: {$opts['bump']}\n";
echo "current_version: {$current}\n";
echo "next_version: {$next}\n";
echo "tag: {$tag}\n";
echo "asset: {$asset}\n";
echo 'files_to_change: '.implode(', ', $plan['files_to_change'])."\n";
echo "build_command: {$plan['build_command']}\n";
echo "verify_command: {$plan['verify_command']}\n";
echo "tests:\n";
foreach ($plan['test_commands'] as $cmd) {
    echo "  - {$cmd}\n";
}

$branch = omi_release_git($root, 'branch --show-current');
if (! $branch['ok']) {
    omi_release_fail('Cannot read git branch: '.($branch['error'] ?? ''));
}
if ($branch['value'] !== Release_Automation::MAIN_BRANCH) {
    omi_release_fail(
        'Must release from '.Release_Automation::MAIN_BRANCH.' (current: '.$branch['value'].').'
    );
}
echo 'branch: '.$branch['value']." OK\n";

$status = omi_release_git($root, 'status --porcelain');
if (! $status['ok']) {
    omi_release_fail('Cannot read git status: '.($status['error'] ?? ''));
}
if ($status['value'] !== '') {
    if ($opts['execute']) {
        omi_release_fail("Working tree is dirty. Commit or stash first.\n".$status['value']);
    }
    echo "working_tree: DIRTY (allowed in dry-run only; --execute will refuse)\n";
    echo $status['value']."\n";
} else {
    echo "working_tree: clean OK\n";
}

$tagRef = 'refs/tags/'.$tag;
$tagLocalCmd = 'git -c safe.directory='.escapeshellarg($root)
    .' -C '.escapeshellarg($root)
    .' rev-parse -q --verify '.escapeshellarg($tagRef);
$tagLocal = omi_release_run($tagLocalCmd, $root);
if ($tagLocal['ok'] && trim($tagLocal['output']) !== '') {
    omi_release_fail("Tag {$tag} already exists locally. Refusing to overwrite.");
}

$tagRemoteCmd = 'git -c safe.directory='.escapeshellarg($root)
    .' -C '.escapeshellarg($root)
    .' ls-remote --tags origin '.escapeshellarg($tagRef);
$remoteTag = omi_release_run($tagRemoteCmd, $root);
if ($remoteTag['ok'] && trim($remoteTag['output']) !== '') {
    omi_release_fail("Tag {$tag} already exists on origin. Refusing to overwrite.");
}
echo "tag_collision: none OK\n";

if ($opts['dry_run']) {
    echo "\nDRY-RUN complete. No version write, tests-on-execute, commit, tag, push, or GitHub Release.\n";
    echo "To release via Actions: gh workflow run release.yml -f bump={$opts['bump']}\n";
    echo "Or locally (CI): php bin/release.php --execute --bump={$opts['bump']}\n";
    exit(0);
}

// --- execute path ---
$write = Release_Automation::write_canonical_version($root, $next);
if (! $write['ok']) {
    omi_release_fail(implode('; ', $write['errors']));
}
echo "version_written: {$next}\n";

$written = Release_Automation::read_canonical_version($root);
if (! $written['ok'] || $written['current'] !== $next) {
    omi_release_fail('Post-write version SSOT check failed.');
}
if ($written['current'] !== $tag) {
    omi_release_fail("Plugin version {$written['current']} disagrees with tag {$tag}.");
}

if (! $opts['skip_tests']) {
    foreach (Release_Automation::discover_standalone_tests($root) as $testFile) {
        $rel = str_replace('\\', '/', substr($testFile, strlen($root) + 1));
        echo "test: php {$rel}\n";
        $test = omi_release_run('php '.escapeshellarg($testFile), $root);
        if (! $test['ok']) {
            // Best-effort rollback of version file to keep tree sane on failure.
            Release_Automation::write_canonical_version($root, $current);
            omi_release_fail("Tests failed ({$rel}):\n".$test['output']);
        }
    }
    echo "tests: all passed OK\n";
} else {
    echo "tests: SKIPPED (--skip-tests)\n";
}

$dist = $opts['dist'] !== null && $opts['dist'] !== ''
    ? $opts['dist']
    : (getenv('RELEASE_DIST_ROOT') ?: null);
$buildArgs = escapeshellarg($root.'/bin/build-plugin-release.php').' '.escapeshellarg($next);
if (is_string($dist) && $dist !== '') {
    $buildArgs .= ' --dist='.escapeshellarg($dist);
}
$build = omi_release_run('php '.$buildArgs, $root);
if (! $build['ok']) {
    Release_Automation::write_canonical_version($root, $current);
    omi_release_fail("Build failed:\n".$build['output']);
}
echo $build['output']."\n";

$distRoot = is_string($dist) && $dist !== ''
    ? rtrim(str_replace('\\', '/', $dist), '/')
    : Release_Package::default_dist_root($root);
$zipPath = $distRoot.'/'.$asset;
$verify = Release_Package::verify_zip($zipPath, $next);
if (! ($verify['ok'] ?? false)) {
    Release_Automation::write_canonical_version($root, $current);
    omi_release_fail('ZIP verify failed: '.implode('; ', $verify['errors'] ?? []));
}
echo "zip_verify: OK ({$zipPath})\n";

$commit = omi_release_git($root, 'add -- '.escapeshellarg(Release_Automation::MAIN_FILE));
if (! $commit['ok']) {
    omi_release_fail('git add failed: '.($commit['error'] ?? ''));
}
$commitMsg = escapeshellarg($next);
$committed = omi_release_run(
    'git -c safe.directory='.escapeshellarg($root)
    .' -C '.escapeshellarg($root)
    .' commit -m '.$commitMsg,
    $root
);
if (! $committed['ok']) {
    omi_release_fail("git commit failed:\n".$committed['output']);
}
echo "commit: {$next}\n";

$tagged = omi_release_git($root, 'tag '.escapeshellarg($tag));
if (! $tagged['ok']) {
    omi_release_fail('git tag failed: '.($tagged['error'] ?? ''));
}
echo "tag_created: {$tag}\n";

$pushMain = omi_release_git($root, 'push origin '.Release_Automation::MAIN_BRANCH);
if (! $pushMain['ok']) {
    $err = (string) ($pushMain['error'] ?? '');
    $hint = '';
    if (preg_match('/protected branch|cannot push|permission|GH006|rejected/i', $err) === 1) {
        $hint = "\nHint: branch protection may block GITHUB_TOKEN from pushing the version commit."
            ." Allow github-actions[bot] to push to main, or use a fine-scoped token with contents:write.";
    }
    omi_release_fail('git push main failed (no force): '.$err.$hint);
}
$pushTag = omi_release_git($root, 'push origin '.escapeshellarg($tag));
if (! $pushTag['ok']) {
    omi_release_fail(
        'git push tag failed (no force). Version commit may already be on remote;'
        .' do not recreate the tag blindly: '.($pushTag['error'] ?? '')
    );
}
echo "push: main + tag OK\n";

$gh = omi_release_run(
    'gh release create '.escapeshellarg($tag)
    .' '.escapeshellarg($zipPath)
    .' --repo trung8356621/wp-seo-ai'
    .' --title '.escapeshellarg($next)
    .' --notes '.escapeshellarg('wp-seo-ai '.$next),
    $root
);
if (! $gh['ok']) {
    omi_release_fail("gh release create failed (tag was pushed; do not recreate blindly):\n".$gh['output']);
}
echo "github_release: created OK\n";

$ghView = omi_release_run(
    'gh release view '.escapeshellarg($tag)
    .' --repo trung8356621/wp-seo-ai'
    .' --json tagName,assets',
    $root
);
$releaseMeta = json_decode($ghView['output'], true);
$assetNames = [];
if (is_array($releaseMeta) && is_array($releaseMeta['assets'] ?? null)) {
    foreach ($releaseMeta['assets'] as $row) {
        if (is_array($row) && isset($row['name'])) {
            $assetNames[] = (string) $row['name'];
        }
    }
}
$tagName = is_array($releaseMeta) ? (string) ($releaseMeta['tagName'] ?? '') : '';
if (
    ! $ghView['ok']
    || $tagName !== $tag
    || ! in_array($asset, $assetNames, true)
) {
    omi_release_fail(
        "Release verify failed. Expected tag {$tag} and asset {$asset}."
        ." Found tagName={$tagName} assets=[".implode(',', $assetNames)."]."
        ." Raw:\n".$ghView['output']
    );
}
echo 'release_verify: '.$tagName.' ['.implode(',', $assetNames)."] OK\n";
echo "RELEASE OK {$next}\n";
exit(0);

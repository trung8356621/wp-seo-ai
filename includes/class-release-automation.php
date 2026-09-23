<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

/**
 * Release planning helpers (semver bump, SSOT checks, artifact naming).
 * Not loaded by WordPress runtime — used by bin/release.php, CI, and tests only.
 */
final class Release_Automation
{
    public const MAIN_BRANCH = 'main';

    public const MAIN_FILE = 'omi-seo-ai-bridge.php';

    /** @var list<string> */
    public const BUMP_LEVELS = ['patch', 'minor', 'major'];

    /**
     * @return array{ok:bool,errors:list<string>,header:string,constant:string,current:?string}
     */
    public static function read_canonical_version(string $pluginRoot): array
    {
        $versions = Release_Package::read_source_versions($pluginRoot);
        $errors = [];
        if ($versions['header'] === '') {
            $errors[] = 'Missing Version header in '.self::MAIN_FILE;
        }
        if ($versions['constant'] === '') {
            $errors[] = 'Missing OMI_SEO_AI_BRIDGE_VERSION constant.';
        }
        if (
            $versions['header'] !== ''
            && $versions['constant'] !== ''
            && $versions['header'] !== $versions['constant']
        ) {
            $errors[] = 'Version SSOT mismatch: header='.$versions['header']
                .' constant='.$versions['constant']
                .' (both must match before release).';
        }

        $current = null;
        if ($errors === [] && $versions['header'] !== '') {
            $current = Release_Package::parse_version_arg($versions['header']);
            if ($current === null) {
                $errors[] = 'Canonical version is not valid semver x.y.z: '.$versions['header'];
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'header' => $versions['header'],
            'constant' => $versions['constant'],
            'current' => $current,
        ];
    }

    public static function bump_semver(string $version, string $level): ?string
    {
        $parsed = Release_Package::parse_version_arg($version);
        if ($parsed === null || ! in_array($level, self::BUMP_LEVELS, true)) {
            return null;
        }

        $parts = array_map('intval', explode('.', $parsed));
        while (count($parts) < 3) {
            $parts[] = 0;
        }
        [$major, $minor, $patch] = $parts;

        return match ($level) {
            'major' => ($major + 1).'.0.0',
            'minor' => $major.'.'.($minor + 1).'.0',
            'patch' => $major.'.'.$minor.'.'.($patch + 1),
            default => null,
        };
    }

    /**
     * Write header Version + OMI_SEO_AI_BRIDGE_VERSION to the same value.
     *
     * @return array{ok:bool,errors:list<string>}
     */
    public static function write_canonical_version(string $pluginRoot, string $version): array
    {
        $version = Release_Package::parse_version_arg($version) ?? '';
        if ($version === '') {
            return ['ok' => false, 'errors' => ['Invalid version for write.']];
        }

        $main = rtrim($pluginRoot, '/\\').DIRECTORY_SEPARATOR.self::MAIN_FILE;
        if (! is_readable($main) || ! is_writable($main)) {
            return ['ok' => false, 'errors' => ['Cannot write '.$main]];
        }

        $src = (string) file_get_contents($main);
        $next = preg_replace(
            '/^(\s*\*\s*Version:\s*)([0-9.]+)(\s*)$/mi',
            '${1}'.$version.'${3}',
            $src,
            1,
            $countHeader
        );
        if (! is_string($next) || $countHeader !== 1) {
            return ['ok' => false, 'errors' => ['Failed to update Version header.']];
        }

        $next = preg_replace(
            "/(define\(\s*'OMI_SEO_AI_BRIDGE_VERSION'\s*,\s*')([^']+)('\s*\))/",
            '${1}'.$version.'${3}',
            $next,
            1,
            $countConst
        );
        if (! is_string($next) || $countConst !== 1) {
            return ['ok' => false, 'errors' => ['Failed to update OMI_SEO_AI_BRIDGE_VERSION.']];
        }

        if (file_put_contents($main, $next) === false) {
            return ['ok' => false, 'errors' => ['Failed to save '.$main]];
        }

        $check = self::read_canonical_version($pluginRoot);
        if (! $check['ok'] || $check['current'] !== $version) {
            return [
                'ok' => false,
                'errors' => array_merge(
                    ['Wrote version but re-read failed.'],
                    $check['errors']
                ),
            ];
        }

        return ['ok' => true, 'errors' => []];
    }

    /**
     * @return list<string>
     */
    public static function discover_standalone_tests(string $pluginRoot): array
    {
        $dir = rtrim($pluginRoot, '/\\').DIRECTORY_SEPARATOR.'tests';
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob($dir.DIRECTORY_SEPARATOR.'*Test.php') ?: [];
        sort($files);

        return array_values(array_filter(
            $files,
            static fn (string $path): bool => is_file($path)
        ));
    }

    /**
     * Build a release plan (no side effects).
     *
     * @return array<string, mixed>
     */
    public static function plan(string $pluginRoot, string $bump): array
    {
        $errors = [];
        if (! in_array($bump, self::BUMP_LEVELS, true)) {
            $errors[] = 'Invalid bump level "'.$bump.'". Use: '.implode('|', self::BUMP_LEVELS);
        }

        $versionInfo = self::read_canonical_version($pluginRoot);
        if (! $versionInfo['ok']) {
            $errors = array_merge($errors, $versionInfo['errors']);
        }

        $current = $versionInfo['current'];
        $next = ($current !== null && $errors === [])
            ? self::bump_semver($current, $bump)
            : null;
        if ($current !== null && $next === null && $errors === []) {
            $errors[] = 'Failed to compute next version from '.$current.' ('.$bump.').';
        }

        $asset = $next !== null ? Release_Package::expected_asset_name($next) : null;
        $tests = self::discover_standalone_tests($pluginRoot);
        $rootNorm = rtrim(str_replace('\\', '/', $pluginRoot), '/').'/';
        $testCommands = [];
        foreach ($tests as $absolute) {
            $norm = str_replace('\\', '/', $absolute);
            $rel = str_starts_with($norm, $rootNorm)
                ? substr($norm, strlen($rootNorm))
                : $norm;
            $testCommands[] = 'php '.$rel;
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'bump' => $bump,
            'current_version' => $current,
            'next_version' => $next,
            'tag' => $next,
            'asset_name' => $asset,
            'files_to_change' => [self::MAIN_FILE],
            'test_commands' => $testCommands,
            'build_command' => $next !== null
                ? 'php bin/build-plugin-release.php '.$next
                : null,
            'verify_command' => 'php tests/ReleasePackageContractTest.php',
        ];
    }
}

<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

/**
 * Deterministic release packaging + ZIP contract checks.
 * Not loaded by the WordPress runtime bootstrap — used by bin/ and tests only.
 */
final class Release_Package
{
    public const PLUGIN_FOLDER = 'wp-seo-ai';

    public const MAIN_FILE = 'omi-seo-ai-bridge.php';

    /** @var list<string> */
    public const RUNTIME_PATHS = [
        'omi-seo-ai-bridge.php',
        'includes',
        'assets',
        'templates',
        'views',
    ];

    /** @var list<string> */
    public const EXCLUDE_DIR_NAMES = [
        '.git',
        '.github',
        '.agents',
        '.cursor',
        '.idea',
        '.vscode',
        'dist',
        'tests',
        'docs',
        'node_modules',
        'vendor',
    ];

    /** @var list<string> */
    public const EXCLUDE_FILE_NAMES = [
        '.gitattributes',
        '.gitignore',
        '.cursorrules',
        'cmd.md',
        'AGENTS.md',
        '.DS_Store',
        'Thumbs.db',
    ];

    public static function expected_asset_name(string $version): string
    {
        return GitHub_Release_Client::expected_asset_name($version);
    }

    /**
     * Build artifacts live outside the plugin tree (sibling of repos under D:/work).
     */
    public static function default_dist_root(string $pluginRoot): string
    {
        $pluginRoot = rtrim(str_replace('\\', '/', $pluginRoot), '/');
        $workRoot = dirname($pluginRoot);
        if ($workRoot !== '' && $workRoot !== '.' && $workRoot !== $pluginRoot) {
            return $workRoot.'/build';
        }

        return 'D:/work/build';
    }

    public static function parse_version_arg(string $raw): ?string
    {
        $version = ltrim(trim($raw), 'vV');
        if ($version === '' || preg_match('/^\d+\.\d+(\.\d+)?$/', $version) !== 1) {
            return null;
        }

        return $version;
    }

    /**
     * @return array{header:string,constant:string}
     */
    public static function read_source_versions(string $pluginRoot): array
    {
        $main = rtrim($pluginRoot, '/\\').DIRECTORY_SEPARATOR.self::MAIN_FILE;
        $src = is_readable($main) ? (string) file_get_contents($main) : '';
        $header = '';
        $constant = '';
        if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)\s*$/mi', $src, $m)) {
            $header = trim((string) $m[1]);
        }
        if (preg_match("/define\(\s*'OMI_SEO_AI_BRIDGE_VERSION'\s*,\s*'([^']+)'\s*\)/", $src, $m)) {
            $constant = trim((string) $m[1]);
        }

        return ['header' => $header, 'constant' => $constant];
    }

    /**
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,version:?string,zip:?string}
     */
    public static function build(string $pluginRoot, string $versionArg, ?string $distRoot = null): array
    {
        $errors = [];
        $warnings = [];
        $version = self::parse_version_arg($versionArg);
        if ($version === null) {
            return [
                'ok' => false,
                'errors' => ['Version argument must be x.y.z (got: '.$versionArg.').'],
                'warnings' => [],
                'version' => null,
                'zip' => null,
            ];
        }

        $pluginRoot = rtrim(str_replace('\\', '/', $pluginRoot), '/');
        $versions = self::read_source_versions($pluginRoot);
        if ($versions['header'] === '') {
            $errors[] = 'Missing Version header in '.self::MAIN_FILE;
        }
        if ($versions['constant'] === '') {
            $errors[] = 'Missing OMI_SEO_AI_BRIDGE_VERSION constant.';
        }
        if ($versions['header'] !== '' && $versions['constant'] !== '' && $versions['header'] !== $versions['constant']) {
            $errors[] = 'Header Version ('.$versions['header'].') != OMI_SEO_AI_BRIDGE_VERSION ('.$versions['constant'].').';
        }
        if ($versions['header'] !== '' && $versions['header'] !== $version) {
            $errors[] = 'Argument version '.$version.' != plugin header Version '.$versions['header'].'.';
        }

        foreach (self::RUNTIME_PATHS as $rel) {
            $path = $pluginRoot.'/'.$rel;
            if (! file_exists($path)) {
                $errors[] = 'Missing runtime path: '.$rel;
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }

        $distRoot = $distRoot !== null && trim($distRoot) !== ''
            ? rtrim(str_replace('\\', '/', $distRoot), '/')
            : self::default_dist_root($pluginRoot);
        $stageRoot = $distRoot.'/build';
        $stagePlugin = $stageRoot.'/'.self::PLUGIN_FOLDER;
        $zipName = self::expected_asset_name($version);
        $zipPath = $distRoot.'/'.$zipName;

        self::rm_tree($stageRoot);
        self::rm_file($zipPath);
        if (! is_dir($distRoot) && ! mkdir($distRoot, 0775, true) && ! is_dir($distRoot)) {
            return [
                'ok' => false,
                'errors' => ['Cannot create dist directory: '.$distRoot],
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }
        if (! mkdir($stagePlugin, 0775, true) && ! is_dir($stagePlugin)) {
            return [
                'ok' => false,
                'errors' => ['Cannot create staging directory: '.$stagePlugin],
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }

        foreach (self::RUNTIME_PATHS as $rel) {
            $from = $pluginRoot.'/'.$rel;
            $to = $stagePlugin.'/'.$rel;
            if (is_dir($from)) {
                self::copy_tree($from, $to);
            } else {
                $dir = dirname($to);
                if (! is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                if (! copy($from, $to)) {
                    $errors[] = 'Failed to copy '.$rel;
                }
            }
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'errors' => $errors,
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }

        if (! class_exists(\ZipArchive::class)) {
            return [
                'ok' => false,
                'errors' => ['PHP ZipArchive extension is required.'],
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return [
                'ok' => false,
                'errors' => ['Cannot create ZIP: '.$zipPath],
                'warnings' => $warnings,
                'version' => $version,
                'zip' => null,
            ];
        }

        self::add_dir_to_zip($zip, $stagePlugin, self::PLUGIN_FOLDER);
        $zip->close();

        $verify = self::verify_zip($zipPath, $version);
        if (! ($verify['ok'] ?? false)) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, $verify['errors'] ?? ['ZIP verification failed.']),
                'warnings' => array_merge($warnings, $verify['warnings'] ?? []),
                'version' => $version,
                'zip' => $zipPath,
            ];
        }

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => array_merge($warnings, $verify['warnings'] ?? []),
            'version' => $version,
            'zip' => $zipPath,
            'asset_name' => $zipName,
            'plugin_folder' => self::PLUGIN_FOLDER,
            'main_file' => self::PLUGIN_FOLDER.'/'.self::MAIN_FILE,
        ];
    }

    /**
     * @return array{ok:bool,errors:list<string>,warnings:list<string>,entries?:list<string>}
     */
    public static function verify_zip(string $zipPath, string $expectedVersion): array
    {
        $errors = [];
        $warnings = [];
        $expectedVersion = self::parse_version_arg($expectedVersion) ?? '';
        $basename = basename($zipPath);
        $expectedName = self::expected_asset_name($expectedVersion);

        if ($expectedVersion === '') {
            $errors[] = 'Invalid expected version for ZIP verify.';
        }
        if ($basename !== $expectedName) {
            $errors[] = 'ZIP filename must be '.$expectedName.' (got '.$basename.').';
        }
        if ($basename === self::PLUGIN_FOLDER.'.zip') {
            $errors[] = 'Unversioned '.self::PLUGIN_FOLDER.'.zip is not a valid release asset.';
        }
        if (! is_readable($zipPath)) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, ['ZIP not readable: '.$zipPath]),
                'warnings' => $warnings,
            ];
        }
        if (! class_exists(\ZipArchive::class)) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, ['PHP ZipArchive extension is required.']),
                'warnings' => $warnings,
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return [
                'ok' => false,
                'errors' => array_merge($errors, ['Cannot open ZIP: '.$zipPath]),
                'warnings' => $warnings,
            ];
        }

        $entries = [];
        $hasMain = false;
        $hasRootFolder = false;
        $mainContents = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $entries[] = $name;
            if ($name === self::PLUGIN_FOLDER.'/' || str_starts_with($name, self::PLUGIN_FOLDER.'/')) {
                $hasRootFolder = true;
            }
            if ($name === self::PLUGIN_FOLDER.'/'.self::MAIN_FILE) {
                $hasMain = true;
                $mainContents = (string) $zip->getFromIndex($i);
            }
            // Reject accidental nesting like wp-seo-ai-1.0.84/...
            if (preg_match('#^'.preg_quote(self::PLUGIN_FOLDER, '#').'-\d+#', $name) === 1) {
                $errors[] = 'ZIP contains versioned folder entry: '.$name;
            }
            if (str_starts_with($name, '.git/') || str_contains($name, '/.git/')) {
                $errors[] = 'ZIP must not include .git: '.$name;
            }
        }
        $zip->close();

        if (! $hasRootFolder) {
            $errors[] = 'ZIP root must contain folder '.self::PLUGIN_FOLDER.'/';
        }
        if (! $hasMain) {
            $errors[] = 'ZIP must contain '.self::PLUGIN_FOLDER.'/'.self::MAIN_FILE;
        }

        if ($mainContents !== '') {
            $header = '';
            $constant = '';
            if (preg_match('/^\s*\*\s*Version:\s*([0-9.]+)\s*$/mi', $mainContents, $m)) {
                $header = trim((string) $m[1]);
            }
            if (preg_match("/define\(\s*'OMI_SEO_AI_BRIDGE_VERSION'\s*,\s*'([^']+)'\s*\)/", $mainContents, $m)) {
                $constant = trim((string) $m[1]);
            }
            if ($header !== $expectedVersion) {
                $errors[] = 'Packaged header Version '.$header.' != release '.$expectedVersion;
            }
            if ($constant !== $expectedVersion) {
                $errors[] = 'Packaged OMI_SEO_AI_BRIDGE_VERSION '.$constant.' != release '.$expectedVersion;
            }
        }

        // Flat dump of main file at ZIP root is invalid.
        foreach ($entries as $name) {
            if ($name === self::MAIN_FILE) {
                $errors[] = 'Main plugin file must be under '.self::PLUGIN_FOLDER.'/ not ZIP root.';
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
            'entries' => $entries,
        ];
    }

    private static function add_dir_to_zip(\ZipArchive $zip, string $dir, string $prefix): void
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = str_replace('\\', '/', $file->getPathname());
            $rel = substr($path, strlen($dir) + 1);
            $entry = $prefix.'/'.$rel;
            if ($file->isDir()) {
                $zip->addEmptyDir(rtrim($entry, '/').'/');
            } else {
                $zip->addFile($path, $entry);
            }
        }
    }

    private static function copy_tree(string $from, string $to): void
    {
        $from = rtrim(str_replace('\\', '/', $from), '/');
        $to = rtrim(str_replace('\\', '/', $to), '/');
        if (! is_dir($to)) {
            mkdir($to, 0775, true);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = str_replace('\\', '/', $file->getPathname());
            $rel = substr($path, strlen($from) + 1);
            $base = basename($path);
            if ($file->isDir() && in_array($base, self::EXCLUDE_DIR_NAMES, true)) {
                continue;
            }
            if ($file->isFile() && in_array($base, self::EXCLUDE_FILE_NAMES, true)) {
                continue;
            }
            $target = $to.'/'.$rel;
            if ($file->isDir()) {
                if (! is_dir($target)) {
                    mkdir($target, 0775, true);
                }
            } else {
                $parent = dirname($target);
                if (! is_dir($parent)) {
                    mkdir($parent, 0775, true);
                }
                copy($path, $target);
            }
        }
    }

    private static function rm_tree(string $dir): void
    {
        $dir = str_replace('\\', '/', $dir);
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function rm_file(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

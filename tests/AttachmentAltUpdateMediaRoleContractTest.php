<?php

declare(strict_types=1);

/**
 * Contract: attachment ALT update rejects article_content; allows featured/gallery;
 * fill_only_if_empty preserves existing non-empty ALT.
 *
 * Standalone — no WordPress bootstrap.
 */

$root = dirname(__DIR__);
$controller = $root . '/includes/class-rest-controller.php';
if (! is_file($controller)) {
    fwrite(STDERR, "Missing class-rest-controller.php\n");
    exit(1);
}

$source = (string) file_get_contents($controller);
$failures = [];

$required = [
    'media_role article_content cannot mutate WordPress attachment ALT',
    "allowedRoles = ['featured_image', 'product_gallery']",
    'fill_only_if_empty',
    'existing_alt_preserved',
    "desired_alt",
];

foreach ($required as $needle) {
    if (! str_contains($source, $needle)) {
        $failures[] = "Missing contract fragment: {$needle}";
    }
}

$handlerPos = strpos($source, 'function handle_update_attachment_meta');
$articleRejectPos = strpos($source, 'article_content cannot mutate');
if ($handlerPos === false || $articleRejectPos === false || $articleRejectPos < $handlerPos) {
    $failures[] = 'article_content reject must live inside handle_update_attachment_meta';
}

$bridge = (string) file_get_contents($root . '/omi-seo-ai-bridge.php');
if (! defined('ABSPATH')) {
    define('ABSPATH', $root.DIRECTORY_SEPARATOR);
}
require_once $root.'/includes/class-github-release-client.php';
require_once $root.'/includes/class-release-package.php';
$versions = OmiSeoAiBridge\Release_Package::read_source_versions($root);
if (($versions['header'] ?? '') === '' || ($versions['constant'] ?? '') === '') {
    $failures[] = 'Plugin Version header / OMI_SEO_AI_BRIDGE_VERSION missing';
} elseif ($versions['header'] !== $versions['constant']) {
    $failures[] = 'Version SSOT mismatch: header='.$versions['header'].' constant='.$versions['constant'];
} elseif (! preg_match('/Version:\s+'.preg_quote($versions['header'], '/').'/', $bridge)) {
    $failures[] = 'Plugin header Version not found in main file';
}

if ($failures !== []) {
    fwrite(STDERR, "AttachmentAltUpdateMediaRoleContractTest FAILED:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "AttachmentAltUpdateMediaRoleContractTest OK\n");
exit(0);

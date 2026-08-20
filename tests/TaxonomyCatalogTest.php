<?php

declare(strict_types=1);

/**
 * Standalone tests for Taxonomy_Catalog. No WordPress / PHPUnit required.
 *
 * php tests/TaxonomyCatalogTest.php
 */
define('ABSPATH', __DIR__.'/');

$omiActions = [];

function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted = 1): void
{
    unset($hook, $callback, $priority, $accepted);
}

function sanitize_key(string $key): string
{
    return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
}

function taxonomy_exists(string $taxonomy): bool
{
    return in_array($taxonomy, ['category', 'post_tag', 'product_cat', 'product_tag'], true);
}

function wp_mkdir_p(string $directory): bool
{
    return is_dir($directory) || mkdir($directory, 0755, true);
}

function is_wp_error(mixed $value): bool
{
    unset($value);

    return false;
}

require_once dirname(__DIR__).'/includes/class-taxonomy-catalog.php';

use OmiSeoAiBridge\Taxonomy_Catalog;

$failures = 0;

function omi_assert(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "PASS  {$message}\n";

        return;
    }

    $failures++;
    echo "FAIL  {$message}\n";
}

function omi_temp_dir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'omi-tax-'.bin2hex(random_bytes(4));
    mkdir($dir, 0755, true);

    return $dir;
}

function omi_rmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.DIRECTORY_SEPARATOR.$entry;
        is_dir($path) ? omi_rmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

$store = omi_temp_dir();
Taxonomy_Catalog::reset_for_tests();
Taxonomy_Catalog::set_directory_override($store);

$termsByTaxonomy = [
    'category' => [
        ['id' => 1, 'name' => 'Tin tức', 'parent' => 0, 'slug' => 'tin-tuc', 'description' => 'ignore'],
        ['id' => 2, 'name' => 'Trong nước', 'parent' => 1, 'url' => 'https://example.test/cat'],
        ['id' => 3, 'name' => 'Quốc tế', 'parent' => 1],
    ],
    'product_cat' => [
        ['term_id' => 10, 'name' => 'Balo', 'parent' => 0],
        ['term_id' => 11, 'name' => 'Balo laptop', 'parent' => 10],
        ['term_id' => 12, 'name' => 'Phụ kiện', 'parent' => 0, 'seo' => ['title' => 'nope']],
    ],
    'post_tag' => [
        ['id' => 20, 'name' => 'SEO', 'parent' => 9],
        ['id' => 21, 'name' => 'Content', 'parent' => 4],
    ],
    'product_tag' => [
        ['id' => 30, 'name' => 'Sale', 'parent' => 8],
    ],
];

Taxonomy_Catalog::set_terms_provider(static function (string $taxonomy) use (&$termsByTaxonomy): array {
    return $termsByTaxonomy[$taxonomy] ?? [];
});

$category = Taxonomy_Catalog::read('category');
omi_assert(count($category) === 3, 'category hierarchy cache has 3 terms');
omi_assert($category[0]['id'] === 3 && $category[0]['name'] === 'Quốc tế', 'category items are name-sorted');
$byId = [];
foreach ($category as $row) {
    $byId[$row['id']] = $row;
}
omi_assert($byId[1]['parent'] === 0 && $byId[2]['parent'] === 1 && $byId[3]['parent'] === 1, 'category keeps WordPress parent ids');
omi_assert(! isset($category[0]['slug']) && ! isset($category[0]['url']) && ! isset($category[0]['description']), 'category catalog uses minimal contract');

$productCat = Taxonomy_Catalog::read('product_cat');
$pc = [];
foreach ($productCat as $row) {
    $pc[$row['id']] = $row;
}
omi_assert($pc[10]['parent'] === 0 && $pc[11]['parent'] === 10 && $pc[12]['parent'] === 0, 'product_cat hierarchy cache keeps parent ids');

$postTags = Taxonomy_Catalog::read('post_tag');
omi_assert($postTags[0]['parent'] === 0 && $postTags[1]['parent'] === 0, 'post_tag parent is always 0');

$productTags = Taxonomy_Catalog::read('product_tag');
omi_assert(count($productTags) === 1 && $productTags[0]['parent'] === 0, 'product_tag parent is always 0');

$rejected = Taxonomy_Catalog::rest_payload('nav_menu');
omi_assert($rejected['ok'] === false && $rejected['status'] === 400, 'unsupported taxonomy is rejected');
omi_assert(($rejected['body']['success'] ?? true) === false, 'unsupported taxonomy payload marks failure');

$ok = Taxonomy_Catalog::rest_payload('category');
omi_assert($ok['ok'] === true && $ok['status'] === 200, 'supported taxonomy REST is 200');
omi_assert(($ok['body']['schema'] ?? '') === Taxonomy_Catalog::SCHEMA, 'JSON payload includes schema');
omi_assert(($ok['body']['taxonomy'] ?? '') === 'category' && isset($ok['body']['items'][0]['id'], $ok['body']['items'][0]['name'], $ok['body']['items'][0]['parent']), 'JSON payload contract has taxonomy + items');
$keys = array_keys($ok['body']['items'][0]);
sort($keys);
omi_assert($keys === ['id', 'name', 'parent'], 'JSON item keys are only id/name/parent');

$beforeCategory = Taxonomy_Catalog::rebuild_count('category');
$beforeProduct = Taxonomy_Catalog::rebuild_count('product_cat');
$beforeTag = Taxonomy_Catalog::rebuild_count('post_tag');
Taxonomy_Catalog::on_term_changed(2, 0, 'category');
omi_assert(Taxonomy_Catalog::rebuild_count('category') === $beforeCategory + 1, 'edit category rebuilds category cache');
omi_assert(Taxonomy_Catalog::rebuild_count('product_cat') === $beforeProduct, 'edit category does not rebuild product_cat');
omi_assert(Taxonomy_Catalog::rebuild_count('post_tag') === $beforeTag, 'edit category does not rebuild post_tag');

Taxonomy_Catalog::on_term_changed(11, 0, 'product_cat');
omi_assert(Taxonomy_Catalog::rebuild_count('product_cat') === $beforeProduct + 1, 'edit product_cat rebuilds only product_cat');
omi_assert(Taxonomy_Catalog::rebuild_count('category') === $beforeCategory + 1, 'edit product_cat leaves category rebuild count unchanged');

Taxonomy_Catalog::on_term_changed(99, 0, 'nav_menu');
omi_assert(Taxonomy_Catalog::rebuild_count('category') === $beforeCategory + 1, 'unsupported taxonomy change is ignored');

$corruptPath = Taxonomy_Catalog::file_path('post_tag');
file_put_contents($corruptPath, '{not-json');
$repaired = Taxonomy_Catalog::read('post_tag');
omi_assert(count($repaired) === 2 && $repaired[0]['parent'] === 0, 'corrupt cache lazy-rebuilds');

@unlink(Taxonomy_Catalog::file_path('product_tag'));
$lazy = Taxonomy_Catalog::read('product_tag');
omi_assert(count($lazy) === 1 && $lazy[0]['name'] === 'Sale', 'missing cache lazy-rebuilds');

omi_rmdir($store);
Taxonomy_Catalog::reset_for_tests();

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "\nAll Taxonomy_Catalog tests passed.\n";
exit(0);

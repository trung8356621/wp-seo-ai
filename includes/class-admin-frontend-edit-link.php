<?php

declare(strict_types=1);

namespace OmiSeoAiBridge;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Nút «Sửa bài viết» dưới tiêu đề (frontend) → redirect Laravel theo wp_id WordPress.
 */
final class Admin_Frontend_Edit_Link
{
    private static bool $rendered = false;

    public static function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_filter('the_title', [self::class, 'filterSingularTitle'], 99, 2);
        add_filter('get_the_archive_title', [self::class, 'filterArchiveTitle'], 99);

        if (function_exists('woocommerce')) {
            add_action('woocommerce_single_product_summary', [self::class, 'renderAfterProductTitle'], 6);
            add_action('woocommerce_archive_description', [self::class, 'renderStandalone'], 3);
        }

        add_action('flatsome_after_page_title', [self::class, 'renderStandalone'], 10);
        add_action('wp_footer', [self::class, 'renderFooterInjection'], 5);
    }

    public static function enqueueAssets(): void
    {
        if (! self::shouldOfferButton()) {
            return;
        }

        wp_enqueue_style(
            'omi-seo-ai-admin-edit-link',
            OMI_SEO_AI_BRIDGE_URL . 'assets/css/admin-frontend-edit-link.css',
            [],
            OMI_SEO_AI_BRIDGE_VERSION
        );
    }

    /**
     * @param  string  $title
     */
    public static function filterSingularTitle($title, $postId = 0): string
    {
        if (self::$rendered || ! is_string($title) || is_admin() || ! is_singular()) {
            return (string) $title;
        }

        // WooCommerce/Flatsome: tránh gắn nhầm tiêu đề widget sidebar (related products).
        if (function_exists('is_product') && is_product()) {
            return (string) $title;
        }

        if (! self::isMainSingularTitleContext((int) $postId)) {
            return (string) $title;
        }

        $button = self::buildButtonHtml();
        if ($button === '') {
            return (string) $title;
        }

        self::$rendered = true;

        return (string) $title . $button;
    }

    /**
     * @param  string  $title
     */
    public static function filterArchiveTitle($title): string
    {
        if (self::$rendered || ! is_string($title) || is_admin()) {
            return (string) $title;
        }

        if (! self::isSupportedView()) {
            return (string) $title;
        }

        $button = self::buildButtonHtml();
        if ($button === '') {
            return (string) $title;
        }

        self::$rendered = true;

        return (string) $title . $button;
    }

    /**
     * Ngay sau tiêu đề sản phẩm chính (WC priority 5 = title).
     */
    public static function renderAfterProductTitle(): void
    {
        if (! function_exists('is_product') || ! is_product()) {
            return;
        }

        self::renderStandalone();
    }

    public static function renderStandalone(): void
    {
        if (self::$rendered || ! self::shouldOfferButton() || ! self::isSupportedView()) {
            return;
        }

        $button = self::buildButtonHtml();
        if ($button === '') {
            return;
        }

        self::$rendered = true;

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in buildButtonHtml()
        echo $button;
    }

    public static function renderFooterInjection(): void
    {
        if (self::$rendered || ! self::shouldOfferButton() || ! self::isSupportedView()) {
            return;
        }

        $button = self::buildButtonHtml();
        if ($button === '') {
            return;
        }

        wp_enqueue_style(
            'omi-seo-ai-admin-edit-link',
            OMI_SEO_AI_BRIDGE_URL . 'assets/css/admin-frontend-edit-link.css',
            [],
            OMI_SEO_AI_BRIDGE_VERSION
        );

        $mainPostId = 0;
        if (is_singular()) {
            $mainPostId = (int) get_queried_object_id();
        }

        ?>
        <script id="omi-seo-admin-edit-link-js">
        (function () {
            if (document.querySelector('.omi-admin-edit-below-title')) {
                return;
            }
            var html = <?php echo wp_json_encode($button); ?>;
            var mainPostId = <?php echo (int) $mainPostId; ?>;

            var sidebarSelectors = [
                '#sidebar', '.sidebar', '.product-sidebar', '#product-sidebar',
                '.widget-area', 'aside.widget-area', '.widget', '.shop-sidebar',
                '.col-inner .widget', '.ux-recent-products', '.related-products',
                '.upsells', '.cross-sells', '.products.related', '.product-small'
            ];

            function isInSidebar(el) {
                if (!el) return true;
                for (var i = 0; i < sidebarSelectors.length; i++) {
                    if (el.closest(sidebarSelectors[i])) {
                        return true;
                    }
                }
                return false;
            }

            function insertAfterTitle(title) {
                if (!title || isInSidebar(title)) {
                    return false;
                }
                var wrap = document.createElement('div');
                wrap.innerHTML = html;
                var node = wrap.firstElementChild;
                if (!node) {
                    return false;
                }
                title.insertAdjacentElement('afterend', node);
                return true;
            }

            function titleInRoot(root) {
                if (!root || isInSidebar(root)) {
                    return null;
                }
                var candidates = root.querySelectorAll(
                    '.product-title, h1.product-title, .product_title, h1.product_title, h1'
                );
                for (var j = 0; j < candidates.length; j++) {
                    var el = candidates[j];
                    if (!isInSidebar(el) && el.offsetParent !== null) {
                        return el;
                    }
                }
                return null;
            }

            if (mainPostId > 0) {
                var productRoot = document.getElementById('product-' + mainPostId);
                if (!productRoot) {
                    productRoot = document.querySelector(
                        '.single-product div.product.post-' + mainPostId
                        + ', .single-product .product.type-product.post-' + mainPostId
                    );
                }
                var mainTitle = titleInRoot(productRoot);
                if (mainTitle && insertAfterTitle(mainTitle)) {
                    return;
                }
            }

            var scopedRoots = [
                'body.single-product .product-info',
                'body.single-product .product-main .product-info',
                'body.single-product .entry-summary',
                'body.single-product .product-summary',
                'body.single-product #main .product-info',
                'body.single-page .page-title',
                'body.single-page #page-title',
                'body.page .page-title',
                'body.page .featured-title',
                'body.tax-product_cat #shop-page-title',
                'body.tax-product_cat .archive-page-title'
            ];

            for (var r = 0; r < scopedRoots.length; r++) {
                var root = document.querySelector(scopedRoots[r]);
                var title = titleInRoot(root);
                if (title && insertAfterTitle(title)) {
                    return;
                }
            }

            var body = document.body;
            if (body && body.matches('body.single-product, body.single-page, body.page, body.tax-product_cat')) {
                var main = document.querySelector('#main, main#main, .content-area, #content');
                if (main) {
                    var h1s = main.querySelectorAll('h1');
                    for (var k = 0; k < h1s.length; k++) {
                        if (!isInSidebar(h1s[k]) && insertAfterTitle(h1s[k])) {
                            return;
                        }
                    }
                }
            }

            var wrap = document.createElement('div');
            wrap.innerHTML = html;
            var fallback = wrap.firstElementChild;
            if (fallback) {
                fallback.classList.add('omi-admin-edit-below-title--fixed');
                document.body.appendChild(fallback);
            }
        })();
        </script>
        <?php
    }

    private static function shouldOfferButton(): bool
    {
        if (! is_user_logged_in() || is_admin()) {
            return false;
        }

        if (! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return false;
        }

        if (self::laravelAppBaseUrl() === '') {
            return false;
        }

        return self::currentUserCanEditCurrentView();
    }

    private static function isSupportedView(): bool
    {
        if (is_singular(['page', 'product', 'post'])) {
            return true;
        }

        if (is_tax('product_cat') || is_tax()) {
            return true;
        }

        return is_category() || is_tag();
    }

    private static function currentUserCanEditCurrentView(): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        if (is_singular()) {
            $postId = (int) get_queried_object_id();

            return $postId > 0 && current_user_can('edit_post', $postId);
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (! $term instanceof \WP_Term) {
                return false;
            }

            return current_user_can('edit_term', (int) $term->term_id, $term->taxonomy);
        }

        return false;
    }

    private static function isMainSingularTitleContext(int $postId): bool
    {
        if ($postId <= 0) {
            return false;
        }

        return $postId === (int) get_queried_object_id();
    }

    private static function buildButtonHtml(): string
    {
        $context = self::resolveContext();
        if ($context === null) {
            return '';
        }

        $url = self::buildLaravelRedirectUrl($context['wp_id'], $context['type']);
        if ($url === '') {
            return '';
        }

        return self::renderButton($url, self::buttonLabel($context['type']));
    }

    /**
     * @return array{wp_id: int, type: string}|null
     */
    private static function resolveContext(): ?array
    {
        if (is_singular()) {
            $postId = (int) get_queried_object_id();
            if ($postId <= 0) {
                return null;
            }

            return [
                'wp_id' => $postId,
                'type' => self::resolveSingularType((string) get_post_type($postId)),
            ];
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if (! $term instanceof \WP_Term) {
                return null;
            }

            return [
                'wp_id' => (int) $term->term_id,
                'type' => self::resolveTaxonomyType($term->taxonomy),
            ];
        }

        return null;
    }

    private static function resolveSingularType(string $postType): string
    {
        return $postType === 'product' ? 'product' : 'article';
    }

    private static function resolveTaxonomyType(string $taxonomy): string
    {
        if ($taxonomy === 'product_cat') {
            return 'product_category';
        }

        if ($taxonomy === 'category') {
            return 'category';
        }

        return 'category';
    }

    private static function laravelAppBaseUrl(): string
    {
        if (! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return '';
        }

        $base = rtrim((string) omi_seo_ai_bridge_laravel_api_url(), '/');
        if ($base === '') {
            return '';
        }

        if (str_ends_with(strtolower($base), '/api')) {
            $base = substr($base, 0, -4);
        }

        return $base;
    }

    private static function buildLaravelRedirectUrl(int $wpId, string $type): string
    {
        if ($wpId <= 0 || ! function_exists('omi_seo_ai_bridge_laravel_api_url')) {
            return '';
        }

        $base = self::laravelAppBaseUrl();
        if ($base === '') {
            return '';
        }

        return add_query_arg(
            [
                'wp_id' => $wpId,
                'type' => $type,
                'site_url' => home_url('/'),
            ],
            $base . '/seo/articles/wp-edit-redirect'
        );
    }

    private static function buttonLabel(string $type): string
    {
        if ($type === 'product_category' || $type === 'category') {
            return __('Sửa danh mục', 'omi-seo-ai-bridge');
        }

        if ($type === 'product') {
            return __('Sửa sản phẩm', 'omi-seo-ai-bridge');
        }

        return __('Sửa bài viết', 'omi-seo-ai-bridge');
    }

    private static function renderButton(string $url, string $label): string
    {
        return sprintf(
            '<p class="omi-admin-edit-below-title"><a class="omi-admin-edit-below-title__link" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
            esc_url($url),
            esc_html($label)
        );
    }
}

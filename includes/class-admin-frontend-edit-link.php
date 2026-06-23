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
    public const OPTION_ADMIN_BAR_ENABLED = 'omi_seo_ai_admin_bar_edit_enabled';

    private static bool $rendered = false;

    public static function register(): void
    {
        add_action('admin_bar_menu', [self::class, 'addAdminBarEditLink'], 90);
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAdminAssets']);
        add_action('post_submitbox_misc_actions', [self::class, 'renderPostEditScreenActions']);
        add_filter('the_title', [self::class, 'filterSingularTitle'], 99, 2);
        add_filter('get_the_archive_title', [self::class, 'filterArchiveTitle'], 99);

        if (function_exists('woocommerce')) {
            add_action('woocommerce_single_product_summary', [self::class, 'renderAfterProductTitle'], 6);
            add_action('woocommerce_archive_description', [self::class, 'renderStandalone'], 3);
        }

        add_action('flatsome_after_page_title', [self::class, 'renderStandalone'], 10);
        add_action('wp_footer', [self::class, 'renderFooterInjection'], 5);
    }

    public static function adminBarEnabled(): bool
    {
        return (string) get_option(self::OPTION_ADMIN_BAR_ENABLED, '1') === '1';
    }

    public static function addAdminBarEditLink(\WP_Admin_Bar $adminBar): void
    {
        if (! self::adminBarEnabled() || ! is_admin_bar_showing()) {
            return;
        }

        $context = self::resolveAdminBarContext();
        if ($context === null) {
            return;
        }

        $url = self::buildLaravelRedirectUrl($context['wp_id'], $context['type']);
        if ($url === '') {
            return;
        }

        $adminBar->add_node([
            'id' => 'omi-seo-ai-edit-on-laravel',
            'title' => '<span class="ab-icon dashicons dashicons-edit" aria-hidden="true"></span>'
                . '<span class="ab-label">'
                . esc_html__('Sửa trên Laravel', 'omi-seo-ai-bridge')
                . '</span>',
            'href' => $url,
            'meta' => [
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'title' => esc_attr__('Mở nội dung trong trình biên tập Laravel', 'omi-seo-ai-bridge'),
                'class' => 'omi-seo-ai-edit-on-laravel-parent',
            ],
        ]);

        $devUrl = self::buildLaravelRedirectUrl($context['wp_id'], $context['type'], 'dev');
        if ($devUrl !== '' && $devUrl !== $url) {
            $adminBar->add_node([
                'id' => 'omi-seo-ai-edit-on-laravel-dev',
                'parent' => 'omi-seo-ai-edit-on-laravel',
                'title' => esc_html__('LARAVEL API URL (Dev)', 'omi-seo-ai-bridge'),
                'href' => $devUrl,
                'meta' => [
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer',
                    'title' => esc_attr__('Mở editor Laravel qua URL Dev (localhost)', 'omi-seo-ai-bridge'),
                ],
            ]);
        }
    }

    public static function renderPostEditScreenActions(): void
    {
        global $post;

        if (! $post instanceof \WP_Post || ! self::canEditPostInAdmin($post)) {
            return;
        }

        $type = self::resolveSingularType((string) $post->post_type);
        $url = self::buildLaravelRedirectUrl((int) $post->ID, $type);
        if ($url === '') {
            return;
        }

        $devUrl = self::buildLaravelRedirectUrl((int) $post->ID, $type, 'dev');
        $hasDev = $devUrl !== '' && $devUrl !== $url;

        echo '<div class="omi-admin-edit-post-box misc-pub-section">';
        echo '<span class="dashicons dashicons-edit" style="margin-right:4px;color:#2271b1;" aria-hidden="true"></span>';
        echo '<strong>' . esc_html__('TVH SEO AI', 'omi-seo-ai-bridge') . '</strong>';
        echo '<div class="omi-admin-edit-post-box__links">';
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in renderAdminEditLink()
        echo self::renderAdminEditLink($url, __('Sửa trên Laravel', 'omi-seo-ai-bridge'), 'omi-admin-edit-post-box__link');
        if ($hasDev) {
            echo ' <span class="omi-admin-edit-post-box__sep">|</span> ';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo self::renderAdminEditLink($devUrl, __('LARAVEL API URL (Dev)', 'omi-seo-ai-bridge'), 'omi-admin-edit-post-box__link omi-admin-edit-post-box__link--dev');
        }
        echo '</div></div>';
    }

    public static function enqueueAdminAssets(string $hookSuffix): void
    {
        if (! in_array($hookSuffix, ['post.php', 'post-new.php'], true)) {
            return;
        }

        wp_enqueue_style(
            'omi-seo-ai-admin-edit-link',
            OMI_SEO_AI_BRIDGE_URL . 'assets/css/admin-frontend-edit-link.css',
            [],
            OMI_SEO_AI_BRIDGE_VERSION
        );
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

        return self::renderButton($url, self::buttonLabel($context['type']), $context);
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

    private static function laravelAppBaseUrl(?string $mode = null): string
    {
        if (function_exists('omi_seo_ai_bridge_laravel_app_base_url')) {
            return omi_seo_ai_bridge_laravel_app_base_url($mode);
        }

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

    private static function connectionHash(): string
    {
        if (function_exists('omi_seo_ai_bridge_maybe_refresh_connection_hash')) {
            omi_seo_ai_bridge_maybe_refresh_connection_hash(false);
        }

        return function_exists('omi_seo_ai_bridge_connection_hash')
            ? omi_seo_ai_bridge_connection_hash()
            : '';
    }

    private static function wpEditRedirectPath(): string
    {
        $hash = self::connectionHash();
        if ($hash !== '') {
            return '/seo/' . $hash . '/articles/wp-edit-redirect';
        }

        return '/seo/articles/wp-edit-redirect';
    }

    private static function buildLaravelRedirectUrl(int $wpId, string $type, ?string $urlMode = null): string
    {
        if ($wpId <= 0) {
            return '';
        }

        $base = self::laravelAppBaseUrl($urlMode);
        if ($base === '') {
            return '';
        }

        return add_query_arg(
            [
                'wp_id' => $wpId,
                'type' => $type,
                'site_url' => home_url('/'),
            ],
            $base . self::wpEditRedirectPath()
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

    /**
     * @param  array{wp_id: int, type: string}|null  $context
     */
    private static function renderButton(string $url, string $label, ?array $context = null): string
    {
        $context = $context ?? [];
        $wpId = (int) ($context['wp_id'] ?? 0);
        $type = (string) ($context['type'] ?? 'article');
        $devUrl = $wpId > 0 ? self::buildLaravelRedirectUrl($wpId, $type, 'dev') : '';
        $hasDev = $devUrl !== '' && $devUrl !== $url;

        if (! $hasDev) {
            return sprintf(
                '<p class="omi-admin-edit-below-title"><a class="omi-admin-edit-below-title__link" href="%s" target="_blank" rel="noopener noreferrer">%s</a></p>',
                esc_url($url),
                esc_html($label)
            );
        }

        return sprintf(
            '<div class="omi-admin-edit-below-title omi-admin-edit-below-title--dropdown">'
            . '<a class="omi-admin-edit-below-title__link" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>'
            . '<div class="omi-admin-edit-below-title__menu" role="menu">'
            . '<a class="omi-admin-edit-below-title__menu-item" href="%1$s" target="_blank" rel="noopener noreferrer" role="menuitem">%3$s</a>'
            . '<a class="omi-admin-edit-below-title__menu-item omi-admin-edit-below-title__menu-item--dev" href="%4$s" target="_blank" rel="noopener noreferrer" role="menuitem">%5$s</a>'
            . '</div></div>',
            esc_url($url),
            esc_html($label),
            esc_html__('Production', 'omi-seo-ai-bridge'),
            esc_url($devUrl),
            esc_html__('LARAVEL API URL (Dev)', 'omi-seo-ai-bridge')
        );
    }

    private static function renderAdminEditLink(string $url, string $label, string $class = ''): string
    {
        return sprintf(
            '<a href="%s" class="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url($url),
            esc_attr($class),
            esc_html($label)
        );
    }

    /**
     * @return array{wp_id: int, type: string}|null
     */
    private static function resolveAdminBarContext(): ?array
    {
        if (is_admin()) {
            global $post;
            if ($post instanceof \WP_Post && self::canEditPostInAdmin($post)) {
                return [
                    'wp_id' => (int) $post->ID,
                    'type' => self::resolveSingularType((string) $post->post_type),
                ];
            }

            return null;
        }

        if (! self::shouldOfferButton()) {
            return null;
        }

        return self::resolveContext();
    }

    private static function canEditPostInAdmin(\WP_Post $post): bool
    {
        if (! is_user_logged_in() || ! in_array($post->post_type, ['post', 'page', 'product'], true)) {
            return false;
        }

        if (! current_user_can('edit_post', (int) $post->ID)) {
            return false;
        }

        if (self::laravelAppBaseUrl() === '') {
            return false;
        }

        return function_exists('omi_seo_ai_bridge_is_connected') && omi_seo_ai_bridge_is_connected();
    }
}

<?php
/**
 * Reviews từ post meta _omi_seo_virtual_comments (không dùng wp_comments).
 *
 * @package OmiSeoAiBridge
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

global $product;

if (! $product instanceof \WC_Product) {
    return;
}

$postId = (int) $product->get_id();
$virtualItems = \OmiSeoAiBridge\Virtual_Comments::get_virtual_items($postId);
$reviewCount = (int) $product->get_review_count();

?>
<div id="reviews" class="woocommerce-Reviews omi-seo-virtual-reviews">
    <div id="comments">
        <h2 class="woocommerce-Reviews-title">
            <?php
            if ($reviewCount > 0 && wc_review_ratings_enabled()) {
                $reviewsTitle = sprintf(
                    esc_html(_n('%1$s review for %2$s', '%1$s reviews for %2$s', $reviewCount, 'woocommerce')),
                    esc_html((string) $reviewCount),
                    '<span>' . esc_html(get_the_title()) . '</span>',
                );
                echo apply_filters('woocommerce_reviews_title', $reviewsTitle, $reviewCount, $product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                esc_html_e('Reviews', 'woocommerce');
            }
            ?>
        </h2>

        <?php if ($virtualItems !== []) : ?>
            <ol class="commentlist">
                <?php
                foreach (array_values($virtualItems) as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $author = sanitize_text_field((string) ($item['author'] ?? 'Khách mua hàng'));
                    $content = (string) ($item['content'] ?? '');
                    $dateRaw = (string) ($item['date'] ?? '');
                    $rating = isset($item['rating']) ? max(1, min(5, (int) $item['rating'])) : 5;
                    $itemId = 'omi-vreview-' . $postId . '-' . (int) $index;
                    ?>
                    <li id="<?php echo esc_attr($itemId); ?>" class="review omi-seo-virtual-review">
                        <div class="comment_container">
                            <div class="comment-text">
                                <?php if (wc_review_ratings_enabled()) : ?>
                                    <?php echo wc_get_rating_html($rating); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>

                                <p class="meta">
                                    <em class="woocommerce-review__awaiting-approval"></em>
                                    <strong class="woocommerce-review__author"><?php echo esc_html($author); ?></strong>
                                    <?php if ($dateRaw !== '') : ?>
                                        <span class="woocommerce-review__published-date">
                                            — <time datetime="<?php echo esc_attr($dateRaw); ?>"><?php echo esc_html($dateRaw); ?></time>
                                        </span>
                                    <?php endif; ?>
                                </p>

                                <div class="description">
                                    <?php echo wp_kses_post(wpautop($content)); ?>
                                </div>
                            </div>
                        </div>
                    </li>
                    <?php
                }
                ?>
            </ol>
        <?php else : ?>
            <p class="woocommerce-noreviews"><?php esc_html_e('There are no reviews yet.', 'woocommerce'); ?></p>
        <?php endif; ?>
    </div>

    <?php if (get_option('woocommerce_review_rating_verification_required') !== 'yes' || wc_customer_bought_product('', get_current_user_id(), $product->get_id())) : ?>
        <div id="review_form_wrapper">
            <div id="review_form">
                <?php
                $commenter = wp_get_current_commenter();
                $commentForm = [
                    'title_reply'          => $reviewCount > 0
                        ? esc_html__('Add a review', 'woocommerce')
                        : sprintf(esc_html__('Be the first to review “%s”', 'woocommerce'), get_the_title()),
                    'title_reply_to'       => esc_html__('Leave a Reply to %s', 'woocommerce'),
                    'title_reply_before'   => '<span id="reply-title" class="comment-reply-title" role="heading" aria-level="3">',
                    'title_reply_after'    => '</span>',
                    'comment_notes_after'  => '',
                    'label_submit'         => esc_html__('Submit', 'woocommerce'),
                    'logged_in_as'         => '',
                    'comment_field'        => '',
                ];

                $nameEmailRequired = (bool) get_option('require_name_email', 1);
                $fields = [
                    'author' => [
                        'label'        => __('Name', 'woocommerce'),
                        'type'         => 'text',
                        'value'        => $commenter['comment_author'],
                        'required'     => $nameEmailRequired,
                        'autocomplete' => 'name',
                    ],
                    'email'  => [
                        'label'        => __('Email', 'woocommerce'),
                        'type'         => 'email',
                        'value'        => $commenter['comment_author_email'],
                        'required'     => $nameEmailRequired,
                        'autocomplete' => 'email',
                    ],
                ];

                $commentForm['fields'] = [];

                foreach ($fields as $key => $field) {
                    $fieldHtml = '<p class="comment-form-' . esc_attr($key) . '">';
                    $fieldHtml .= '<label for="' . esc_attr($key) . '">' . esc_html((string) $field['label']);
                    if ($field['required']) {
                        $fieldHtml .= '&nbsp;<span class="required">*</span>';
                    }
                    $fieldHtml .= '</label>';
                    $fieldHtml .= '<input id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="' . esc_attr((string) $field['type']) . '" autocomplete="' . esc_attr((string) $field['autocomplete']) . '" value="' . esc_attr((string) $field['value']) . '" size="30" ' . ($field['required'] ? 'required' : '') . ' /></p>';
                    $commentForm['fields'][$key] = $fieldHtml;
                }

                $accountPageUrl = wc_get_page_permalink('myaccount');
                if ($accountPageUrl) {
                    $commentForm['must_log_in'] = '<p class="must-log-in">' . sprintf(
                        esc_html__('You must be %1$slogged in%2$s to post a review.', 'woocommerce'),
                        '<a href="' . esc_url($accountPageUrl) . '">',
                        '</a>',
                    ) . '</p>';
                }

                if (wc_review_ratings_enabled()) {
                    $commentForm['comment_field'] = '<div class="comment-form-rating"><label for="rating">' . esc_html__('Your rating', 'woocommerce') . (wc_review_ratings_required() ? '&nbsp;<span class="required">*</span>' : '') . '</label><select name="rating" id="rating" required>
                        <option value="">' . esc_html__('Rate&hellip;', 'woocommerce') . '</option>
                        <option value="5">' . esc_html__('Perfect', 'woocommerce') . '</option>
                        <option value="4">' . esc_html__('Good', 'woocommerce') . '</option>
                        <option value="3">' . esc_html__('Average', 'woocommerce') . '</option>
                        <option value="2">' . esc_html__('Not that bad', 'woocommerce') . '</option>
                        <option value="1">' . esc_html__('Very poor', 'woocommerce') . '</option>
                    </select></div>';
                }

                $commentForm['comment_field'] .= '<p class="comment-form-comment"><label for="comment">' . esc_html__('Your review', 'woocommerce') . '&nbsp;<span class="required">*</span></label><textarea id="comment" name="comment" cols="45" rows="8" required></textarea></p>';

                comment_form(apply_filters('woocommerce_product_review_comment_form_args', $commentForm));
                ?>
            </div>
        </div>
    <?php else : ?>
        <p class="woocommerce-verification-required"><?php esc_html_e('Only logged in customers who have purchased this product may leave a review.', 'woocommerce'); ?></p>
    <?php endif; ?>

    <div class="clear"></div>
</div>

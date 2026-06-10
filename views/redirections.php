<?php

declare(strict_types=1);

use OmiSeoAiBridge\Redirection_Manager;

if (! defined('ABSPATH')) {
    exit;
}

$items = Redirection_Manager::all();
$message = isset($_GET['message']) ? sanitize_key((string) wp_unslash($_GET['message'])) : '';
$editId = isset($_GET['edit']) ? sanitize_key((string) wp_unslash($_GET['edit'])) : '';
$editing = null;
foreach ($items as $item) {
    if ($editId !== '' && hash_equals((string) $item['id'], $editId)) {
        $editing = $item;
        break;
    }
}
?>

<div class="wrap omi-seo-ai-bridge-wrap omi-seo-ai-redirections-wrap">
    <div class="omi-seo-ai-bridge-actions">
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=settings')); ?>">Cài đặt</a>
        <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai')); ?>">Tổng quan</a>
    </div>

    <?php if ($message !== '') : ?>
        <div class="notice notice-success is-dismissible"><p>Đã cập nhật chuyển hướng.</p></div>
    <?php endif; ?>

    <div class="omi-seo-ai-bridge-card">
        <h2><?php echo $editing !== null ? 'Sửa chuyển hướng' : 'Thêm chuyển hướng'; ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=redirections')); ?>">
            <?php wp_nonce_field('omi_seo_ai_redirections'); ?>
            <input type="hidden" name="omi_redirection_action" value="save" />
            <input type="hidden" name="redirect_id" value="<?php echo esc_attr((string) ($editing['id'] ?? '')); ?>" />

            <div class="omi-seo-redirection-form">
                <div>
                    <label for="omi_redirect_source">URL nguồn</label>
                    <input id="omi_redirect_source" class="regular-text" name="source" required placeholder="/duong-dan-cu" value="<?php echo esc_attr((string) ($editing['source'] ?? '')); ?>" />
                </div>
                <div>
                    <label for="omi_redirect_target">URL đích</label>
                    <input id="omi_redirect_target" class="regular-text" name="target" required placeholder="https://example.com/duong-dan-moi" value="<?php echo esc_attr((string) ($editing['target'] ?? '')); ?>" />
                </div>
                <div>
                    <label for="omi_redirect_status">Mã chuyển hướng</label>
                    <select id="omi_redirect_status" name="status_code">
                        <?php foreach ([301, 302, 307, 308] as $code) : ?>
                            <option value="<?php echo esc_attr((string) $code); ?>" <?php selected((int) ($editing['status_code'] ?? 301), $code); ?>><?php echo esc_html((string) $code); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="omi-seo-redirection-enabled">
                    <input type="checkbox" name="enabled" value="1" <?php checked((bool) ($editing['enabled'] ?? true)); ?> />
                    Bật
                </label>
            </div>

            <p>
                <button type="submit" class="button button-primary"><?php echo $editing !== null ? 'Cập nhật' : 'Thêm chuyển hướng'; ?></button>
                <?php if ($editing !== null) : ?>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=redirections')); ?>">Hủy</a>
                <?php endif; ?>
            </p>
        </form>
    </div>

    <div class="omi-seo-ai-bridge-card omi-seo-redirection-list">
        <h2>Danh sách chuyển hướng</h2>
        <p class="description">Khi bật tự động, plugin sẽ thêm URL cũ vào đây mỗi lần chuyển post ↔ product làm thay đổi permalink.</p>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>URL nguồn</th>
                    <th>URL đích</th>
                    <th>Mã</th>
                    <th>Trạng thái</th>
                    <th>Lượt chuyển</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($items === []) : ?>
                    <tr><td colspan="6">Chưa có chuyển hướng.</td></tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><code><?php echo esc_html((string) $item['source']); ?></code></td>
                            <td><a href="<?php echo esc_url((string) $item['target']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $item['target']); ?></a></td>
                            <td><?php echo esc_html((string) $item['status_code']); ?></td>
                            <td><?php echo $item['enabled'] ? 'Bật' : 'Tắt'; ?></td>
                            <td><?php echo esc_html((string) $item['hits']); ?></td>
                            <td class="omi-seo-redirection-actions">
                                <a class="button button-small" href="<?php echo esc_url(add_query_arg(['page' => 'omi-seo-ai', 'view' => 'redirections', 'edit' => $item['id']], admin_url('admin.php'))); ?>">Sửa</a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=redirections')); ?>">
                                    <?php wp_nonce_field('omi_seo_ai_redirections'); ?>
                                    <input type="hidden" name="omi_redirection_action" value="toggle" />
                                    <input type="hidden" name="redirect_id" value="<?php echo esc_attr((string) $item['id']); ?>" />
                                    <button class="button button-small" type="submit"><?php echo $item['enabled'] ? 'Tắt' : 'Bật'; ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=omi-seo-ai&view=redirections')); ?>" onsubmit="return confirm('Xóa chuyển hướng này?');">
                                    <?php wp_nonce_field('omi_seo_ai_redirections'); ?>
                                    <input type="hidden" name="omi_redirection_action" value="delete" />
                                    <input type="hidden" name="redirect_id" value="<?php echo esc_attr((string) $item['id']); ?>" />
                                    <button class="button button-small button-link-delete" type="submit">Xóa</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

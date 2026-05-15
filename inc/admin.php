<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// ADMIN — Uninstall option (full cleanup toggle)
// ============================================================

add_action('admin_init', function (): void {
    if (! current_user_can('manage_options')) {
        return;
    }
    register_setting('general', 'polyglot_uninstall_delete_shadows', [
        'type' => 'boolean',
        'default' => false,
        'sanitize_callback' => 'rest_sanitize_boolean',
    ]);
    add_settings_field(
        'polyglot_uninstall_delete_shadows',
        __('Polyglot: delete shadows on uninstall', 'piedweb-ai-polyglot'),
        function (): void {
            $checked = get_option('polyglot_uninstall_delete_shadows', false);
            echo '<label><input type="checkbox" name="polyglot_uninstall_delete_shadows" value="1" '.checked($checked, true, false).' /> ';
            echo esc_html__('Delete all shadow posts, terms, and translated comments when this plugin is deleted.', 'piedweb-ai-polyglot');
            echo '</label>';
        },
        'general',
        'default'
    );

    register_setting('general', 'polyglot_footer_switcher', [
        'type' => 'boolean',
        'default' => true,
        'sanitize_callback' => 'intval',
    ]);
    add_settings_field(
        'polyglot_footer_switcher',
        __('Polyglot: footer language switcher', 'piedweb-ai-polyglot'),
        function (): void {
            $checked = get_option('polyglot_footer_switcher', true);
            echo '<input type="hidden" name="polyglot_footer_switcher" value="0" />';
            echo '<label><input type="checkbox" name="polyglot_footer_switcher" value="1" '.checked($checked, true, false).' /> ';
            echo esc_html__('Display a floating language-switcher bar in the site footer.', 'piedweb-ai-polyglot');
            echo '</label>';
        },
        'general',
        'default'
    );
});

// ============================================================
// ADMIN UI — Locale dropdown filter in listings
// ============================================================

add_action('restrict_manage_posts', 'polyglot_admin_locale_dropdown');

function polyglot_admin_locale_dropdown($post_type)
{
    if (! in_array($post_type, polyglot_get_post_types(), true)) {
        return;
    }

    $current = sanitize_text_field($_GET['polyglot_locale'] ?? '');
    $master_hreflang = '';
    foreach (POLYGLOT_LOCALES as $cfg) {
        if (! empty($cfg['master'])) {
            $master_hreflang = strtoupper($cfg['hreflang']);

            break;
        }
    }

    echo '<select name="polyglot_locale">';
    /* translators: %s: master language code (e.g. FR) */
    echo '<option value=""'.selected($current, '', false).'>'.sprintf(esc_html__('Master (%s)', 'piedweb-ai-polyglot'), esc_html($master_hreflang)).'</option>';
    echo '<option value="all"'.selected($current, 'all', false).'>'.esc_html__('All languages', 'piedweb-ai-polyglot').'</option>';

    foreach (POLYGLOT_LOCALES as $cfg) {
        if (! empty($cfg['master'])) {
            continue;
        }
        $locale = $cfg['locale'];
        echo '<option value="'.esc_attr($locale).'"'.selected($current, $locale, false).'>'.esc_html($cfg['label']).'</option>';
    }

    echo '</select>';
}

// ============================================================
// ADMIN UI — "Langue" column in listings
// ============================================================

foreach (polyglot_get_post_types() as $_polyglot_type) {
    add_filter("manage_{$_polyglot_type}_posts_columns", 'polyglot_add_langue_column');
    add_action("manage_{$_polyglot_type}_posts_custom_column", 'polyglot_render_langue_column', 10, 2);
}

function polyglot_add_langue_column($columns)
{
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ('title' === $key) {
            $new['polyglot_langue'] = __('Language', 'piedweb-ai-polyglot');
        }
    }

    return $new;
}

function polyglot_render_langue_column($column, $post_id)
{
    if ('polyglot_langue' !== $column) {
        return;
    }

    $master_id = get_post_meta($post_id, '_master_id', true);

    if ($master_id) {
        // Shadow
        $locale = get_post_meta($post_id, '_locale', true);
        $hreflang = '';
        foreach (POLYGLOT_LOCALES as $cfg) {
            if ($cfg['locale'] === $locale) {
                $hreflang = strtoupper($cfg['hreflang']);

                break;
            }
        }
        $mode = get_post_meta($post_id, '_translation_mode', true);
        echo '<span class="polyglot-badge polyglot-shadow">'
            .esc_html($hreflang)
            .'</span>';
        if ('manual' === $mode) {
            echo ' <span class="polyglot-badge polyglot-manual" title="'.esc_attr__('Manual translation', 'piedweb-ai-polyglot').'">✎</span>';
        }
    } else {
        // Master
        $master_hreflang = '';
        foreach (POLYGLOT_LOCALES as $cfg) {
            if (! empty($cfg['master'])) {
                $master_hreflang = strtoupper($cfg['hreflang']);

                break;
            }
        }

        // Count existing translations
        global $wpdb;
        $shadow_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = '_master_id' AND meta_value = %d",
            $post_id
        ));
        $total = count(polyglot_get_shadow_locales());

        echo '<span class="polyglot-badge polyglot-master">'
            .esc_html($master_hreflang)
            .'</span> '
            .'<span class="polyglot-count">'.esc_html($shadow_count).'/'.esc_html($total).'</span>';
    }
}

// ============================================================
// ADMIN UI — Shadow banner on edit screen
// ============================================================

add_action('admin_notices', 'polyglot_admin_shadow_banner');

function polyglot_admin_shadow_banner()
{
    $screen = get_current_screen();
    if (! $screen || 'post' !== $screen->base) {
        return;
    }
    if (! in_array($screen->post_type, polyglot_get_post_types(), true)) {
        return;
    }

    global $post;
    if (! $post) {
        return;
    }

    $master_id = get_post_meta($post->ID, '_master_id', true);
    if (! $master_id) {
        return;
    }

    $locale = get_post_meta($post->ID, '_locale', true);
    $label = polyglot_locale_to_label($locale);
    $mode = get_post_meta($post->ID, '_translation_mode', true);
    $master_link = get_edit_post_link($master_id);
    $mode_text = 'manual' === $mode ? esc_html__('Manual translation', 'piedweb-ai-polyglot') : esc_html__('Automatic translation', 'piedweb-ai-polyglot');

    echo '<div class="notice notice-warning"><p>';
    echo '<strong>SHADOW ['.esc_html($label).']</strong> — ';
    echo esc_html($mode_text).'. ';
    /* translators: %1$s: edit link URL, %2$s: master post ID */
    echo wp_kses_post(sprintf(__('Master: <a href="%1$s">ID %2$s &rarr;</a>', 'piedweb-ai-polyglot'), esc_url($master_link), esc_html($master_id)));
    echo '</p></div>';
}

// ============================================================
// ADMIN UI — Translation metabox on master edit screen
// ============================================================

add_action('add_meta_boxes', 'polyglot_register_translation_metabox');

function polyglot_register_translation_metabox()
{
    global $post;
    if (! $post) {
        return;
    }

    // Only show on masters (no _master_id)
    if (get_post_meta($post->ID, '_master_id', true)) {
        return;
    }

    $post_type = get_post_type($post);
    if (! in_array($post_type, polyglot_get_post_types(), true)) {
        return;
    }

    add_meta_box(
        'polyglot_translations',
        __('Translations', 'piedweb-ai-polyglot'),
        'polyglot_render_translation_metabox',
        $post_type,
        'side',
        'default'
    );
}

function polyglot_render_translation_metabox($post)
{
    global $wpdb;
    $shadow_locales = polyglot_get_shadow_locales();
    $total = count($shadow_locales);

    // Fetch all shadows for this master
    $shadows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm1.post_id, pm2.meta_value AS locale
         FROM $wpdb->postmeta pm1
         JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
         WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d",
        $post->ID
    ));

    $shadow_map = [];
    foreach ($shadows as $s) {
        $shadow_map[$s->locale] = (int) $s->post_id;
    }

    $existing_count = 0;
    echo '<ul style="margin:0;padding:0;list-style:none">';
    foreach ($shadow_locales as $locale) {
        $label = polyglot_locale_to_label($locale);
        if (isset($shadow_map[$locale])) {
            ++$existing_count;
            $sid = $shadow_map[$locale];
            $mode = get_post_meta($sid, '_translation_mode', true);
            $edit_link = get_edit_post_link($sid);
            echo '<li style="padding:3px 0">';
            echo '<span style="color:#46b450">✓</span> ';
            echo esc_html($label).' — <a href="'.esc_url($edit_link).'">'.esc_html__('Edit', 'piedweb-ai-polyglot').'</a>';
            if ('manual' === $mode) {
                echo ' <span style="color:#f0ad4e" title="'.esc_attr__('Manual translation', 'piedweb-ai-polyglot').'">✎</span>';
            }
            echo '</li>';
        } else {
            echo '<li style="padding:3px 0">';
            echo '<span style="color:#999">✗</span> ';
            echo '<span style="color:#999">'.esc_html($label).'</span>';
            echo '</li>';
        }
    }
    echo '</ul>';
    /* translators: %1$d: number of existing translations, %2$d: total number of shadow locales */
    echo '<p style="margin:8px 0 0;color:#666"><strong>'.esc_html($existing_count).'/'.esc_html($total).'</strong> '.esc_html__('translations', 'piedweb-ai-polyglot').'</p>';
}

// ============================================================
// ADMIN UI — Custom permalink meta box
// ============================================================

add_action('add_meta_boxes', 'polyglot_register_permalink_metabox');

function polyglot_register_permalink_metabox(): void
{
    foreach (polyglot_get_post_types() as $post_type) {
        add_meta_box(
            'polyglot_custom_permalink',
            __('Permalien Polyglot', 'piedweb-ai-polyglot'),
            'polyglot_render_permalink_metabox',
            $post_type,
            'side',
            'high'
        );
    }
}

function polyglot_render_permalink_metabox(WP_Post $post): void
{
    $value = get_post_meta($post->ID, 'custom_permalink', true);
    wp_nonce_field('polyglot_permalink_'.$post->ID, 'polyglot_permalink_nonce');
    ?>
    <p style="margin-bottom:4px">
        <code><?php echo esc_html(home_url('/')); ?></code>
    </p>
    <input
        type="text"
        id="polyglot_custom_permalink"
        name="polyglot_custom_permalink"
        value="<?php echo esc_attr($value ?: $post->post_name); ?>"
        class="widefat"
    />
    <p class="description" style="margin-top:6px">
        <?php esc_html_e('URL personnalisée de cette page. Exemples : blog, promo/black-friday', 'piedweb-ai-polyglot'); ?>
    </p>
    <?php
}

add_action('save_post', 'polyglot_save_permalink_metabox', 10, 2);

function polyglot_save_permalink_metabox(int $post_id, WP_Post $post): void
{
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }

    if (! isset($_POST['polyglot_permalink_nonce'])
        || ! wp_verify_nonce($_POST['polyglot_permalink_nonce'], 'polyglot_permalink_'.$post_id)
    ) {
        return;
    }

    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    if (! in_array($post->post_type, polyglot_get_post_types(), true)) {
        return;
    }

    $value = sanitize_text_field(trim($_POST['polyglot_custom_permalink'] ?? '', '/'));

    if ($value) {
        update_post_meta($post_id, 'custom_permalink', $value);
    } else {
        delete_post_meta($post_id, 'custom_permalink');
    }
}

// ============================================================
// ADMIN UI — CSS for badges
// ============================================================

add_action('admin_head', 'polyglot_admin_css');

function polyglot_admin_css()
{
    $screen = get_current_screen();
    if (! $screen) {
        return;
    }
    $allowed = ['edit', 'post', 'users', 'woocommerce_page_wc-orders'];
    if (! in_array($screen->base, $allowed, true)) {
        return;
    }
    ?>
    <style>
    .column-polyglot_langue { width: 100px; }
    .column-polyglot_locale { width: 80px; }
    .polyglot-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 11px;
        font-weight: 600;
        line-height: 1.4;
    }
    .polyglot-master { background: #0073aa; color: #fff; }
    .polyglot-shadow { background: #f0f0f0; color: #555; }
    .polyglot-manual { background: #f0ad4e; color: #fff; font-size: 10px; }
    .polyglot-count { color: #888; font-size: 12px; }
    </style>
    <?php
}

// ============================================================
// ADMIN UI — "Locale" column on Users listing
// ============================================================

add_filter('manage_users_columns', 'polyglot_add_user_locale_column');
add_filter('manage_users_custom_column', 'polyglot_render_user_locale_column', 10, 3);

function polyglot_add_user_locale_column($columns)
{
    $columns['polyglot_locale'] = __('Registration locale', 'piedweb-ai-polyglot');

    return $columns;
}

function polyglot_render_user_locale_column($output, $column, $user_id)
{
    if ('polyglot_locale' !== $column) {
        return $output;
    }

    $locale = get_user_meta($user_id, '_registration_locale', true);
    if (! $locale) {
        $locale = polyglot_get_master_locale();
    }

    $hreflang = '';
    foreach (POLYGLOT_LOCALES as $cfg) {
        if ($cfg['locale'] === $locale) {
            $hreflang = strtoupper($cfg['hreflang']);

            break;
        }
    }

    return '<span class="polyglot-badge polyglot-shadow">'.esc_html($hreflang ?: $locale).'</span>';
}

// ============================================================
// ADMIN UI — "Locale" column on WooCommerce Orders listing
// ============================================================

add_filter('manage_edit-shop_order_columns', 'polyglot_add_order_locale_column');
add_action('manage_shop_order_posts_custom_column', 'polyglot_render_order_locale_column', 10, 2);

// HPOS support
add_filter('manage_woocommerce_page_wc-orders_columns', 'polyglot_add_order_locale_column');
add_action('manage_woocommerce_page_wc-orders_custom_column', 'polyglot_render_order_locale_column_hpos', 10, 2);

function polyglot_add_order_locale_column($columns)
{
    $new = [];
    foreach ($columns as $key => $label) {
        $new[$key] = $label;
        if ('order_number' === $key || 'order_title' === $key) {
            $new['polyglot_locale'] = 'Locale';
        }
    }

    // Fallback: add at end if key not found
    if (! isset($new['polyglot_locale'])) {
        $new['polyglot_locale'] = 'Locale';
    }

    return $new;
}

function polyglot_render_order_locale_column($column, $post_id)
{
    if ('polyglot_locale' !== $column) {
        return;
    }

    $locale = get_post_meta($post_id, '_order_locale', true);
    echo polyglot_format_locale_badge($locale); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns sanitized HTML built from trusted locale labels
}

function polyglot_render_order_locale_column_hpos($column, $order)
{
    if ('polyglot_locale' !== $column) {
        return;
    }

    $locale = '';
    if (is_object($order) && method_exists($order, 'get_meta')) {
        $locale = $order->get_meta('_order_locale');
    }

    echo polyglot_format_locale_badge($locale); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- returns sanitized HTML built from trusted locale labels
}

function polyglot_format_locale_badge(string $locale): string
{
    if (! $locale) {
        $locale = polyglot_get_master_locale();
    }

    $hreflang = '';
    foreach (POLYGLOT_LOCALES as $cfg) {
        if ($cfg['locale'] === $locale) {
            $hreflang = strtoupper($cfg['hreflang']);

            break;
        }
    }

    return '<span class="polyglot-badge polyglot-shadow">'.esc_html($hreflang ?: $locale).'</span>';
}

// ============================================================
// ADMIN UI — Disable stock fields on Shadow products
// ============================================================

add_action('admin_footer', 'polyglot_lock_shadow_inventory');

function polyglot_lock_shadow_inventory()
{
    global $post;
    if (! $post || 'product' !== get_post_type($post)) {
        return;
    }

    $master_id = get_post_meta($post->ID, '_master_id', true);
    if (! $master_id) {
        return;
    }

    $locale = get_post_meta($post->ID, '_locale', true);
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#_manage_stock, #_stock, #_stock_status').prop('disabled', true);
        var masterUrl = '<?php echo esc_url(get_edit_post_link($master_id)); ?>';
        $('.inventory_options').prepend(
            '<div class="notice notice-warning inline"><p>' +
            '<strong>SHADOW [<?php echo esc_js($locale); ?>]</strong> — ' +
            'Stock is managed by master product (ID <?php echo (int) $master_id; ?>). ' +
            '<a href="' + masterUrl + '">Edit master &rarr;</a>' +
            '</p></div>'
        );
    });
    </script>
    <?php
}

// ============================================================
// HUMAN LOCK — Protect manual edits from AI overwrite
// ============================================================

add_action('save_post_product', 'polyglot_flag_manual_edit', 10, 3);
add_action('save_post_page', 'polyglot_flag_manual_edit', 10, 3);
add_action('save_post_post', 'polyglot_flag_manual_edit', 10, 3);

function polyglot_flag_manual_edit($post_id, $post, $update): void
{
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (! current_user_can('edit_post', $post_id)) {
        return;
    }

    if (get_post_meta($post_id, '_master_id', true)) {
        update_post_meta($post_id, '_translation_mode', 'manual');
    }
}

<?php
/**
 * Plugin Name: PiedWeb AI Polyglot
 * Plugin URI:  https://wap.piedweb.com
 * Description: Master/Shadow i18n architecture. One master language, N shadow languages via domain map.
 * Version:     2.0.0
 * Author:      PiedWeb
 * Author URI:  https://en.piedweb.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: piedweb-ai-polyglot
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// CONFIGURATION — Domain-to-locale map
// ============================================================
//
// POLYGLOT_LOCALES must be defined in wp-config.php (or test bootstrap).
// Each entry: 'authority' => [ locale, hreflang, label, currency ]
// The first entry is always the master language.
//

if (! defined('POLYGLOT_LOCALES')) {
    return;
}

if (! defined('POLYGLOT_TRANSLATIONS_DIR')) {
    define('POLYGLOT_TRANSLATIONS_DIR', 'polyglot-flat');
}

if (is_multisite()) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('PiedWeb AI Polyglot is not compatible with WordPress Multisite.', 'piedweb-ai-polyglot');
        echo '</p></div>';
    });

    return;
}

define('POLYGLOT_VERSION', '2.0.0');
define('POLYGLOT_PLUGIN_FILE', __FILE__);
define('POLYGLOT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POLYGLOT_WC_SLUGS_OPTION', 'polyglot_wc_slugs');

// ============================================================
// ACTIVATION / DEACTIVATION
// ============================================================

register_activation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function (): void {
    flush_rewrite_rules();
});

// ============================================================
// MODULE LOADING
// ============================================================

require_once __DIR__.'/inc/helpers.php';
require_once __DIR__.'/inc/permalink.php';
require_once __DIR__.'/inc/routing.php';
require_once __DIR__.'/inc/wc-product-bridge.php';
require_once __DIR__.'/inc/wc-review-bridge.php';
require_once __DIR__.'/inc/seo.php';
require_once __DIR__.'/inc/admin.php';
require_once __DIR__.'/inc/frontend.php';
require_once __DIR__.'/inc/draft-link-handler.php';
require_once __DIR__.'/inc/wc-i18n.php';
if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__.'/inc/cli.php';
}

// ============================================================
// CACHE INVALIDATION — Flush polyglot object cache on changes
// ============================================================

add_action('save_post', function (int $post_id): void {
    if (! in_array(get_post_type($post_id), polyglot_get_post_types(), true)) {
        return;
    }

    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('polyglot');
    } else {
        wp_cache_delete("hreflang|post_{$post_id}", 'polyglot');
        $master_id = get_post_meta($post_id, '_master_id', true);
        if ($master_id) {
            wp_cache_delete("hreflang|post_{$master_id}", 'polyglot');
        }
    }
});

add_action('comment_post', function (): void {
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('polyglot');
    }
});

add_action('wp_set_comment_status', function (): void {
    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('polyglot');
    }
});

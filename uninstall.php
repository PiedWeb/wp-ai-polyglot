<?php
/**
 * PiedWeb AI Polyglot uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted via wp-admin.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Uninstall runs once and must delete canonical rows immediately. Persistent
// caching and higher-level metadata APIs would add work without improving safety.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Always clean plugin options
delete_option('polyglot_wc_slugs');

// Check if the user opted for full cleanup (shadow deletion)
$polyglot_full_cleanup = get_option('polyglot_uninstall_delete_shadows', false);

if ($polyglot_full_cleanup) {
    global $wpdb;

    // Delete all shadow posts (posts with _master_id meta)
    $polyglot_shadow_ids = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_master_id'"
    );
    foreach ($polyglot_shadow_ids as $polyglot_shadow_id) {
        wp_delete_post((int) $polyglot_shadow_id, true);
    }

    // Delete all shadow terms (terms with _master_term_id meta)
    $polyglot_shadow_term_ids = $wpdb->get_col(
        "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_master_term_id'"
    );
    foreach ($polyglot_shadow_term_ids as $polyglot_term_id) {
        $term = get_term((int) $polyglot_term_id);
        if ($term && ! is_wp_error($term)) {
            wp_delete_term((int) $polyglot_term_id, $term->taxonomy);
        }
    }

    // Delete translated comments (comments with _master_comment_id meta)
    $polyglot_shadow_comment_ids = $wpdb->get_col(
        "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_master_comment_id'"
    );
    foreach ($polyglot_shadow_comment_ids as $polyglot_comment_id) {
        wp_delete_comment((int) $polyglot_comment_id, true);
    }

    // Clean remaining meta keys
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_locale']);
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_master_id']);
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_translation_mode']);
    $wpdb->delete($wpdb->postmeta, ['meta_key' => '_order_locale']);
    $wpdb->delete($wpdb->termmeta, ['meta_key' => '_locale']);
    $wpdb->delete($wpdb->termmeta, ['meta_key' => '_master_term_id']);
    $wpdb->delete($wpdb->commentmeta, ['meta_key' => '_source_locale']);
    $wpdb->delete($wpdb->commentmeta, ['meta_key' => '_master_comment_id']);
    $wpdb->delete($wpdb->commentmeta, ['meta_key' => '_locale']);
    $wpdb->delete($wpdb->commentmeta, ['meta_key' => '_translation_mode']);
    $wpdb->delete($wpdb->usermeta, ['meta_key' => '_registration_locale']);
}

delete_option('polyglot_uninstall_delete_shadows');

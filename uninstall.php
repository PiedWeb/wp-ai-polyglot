<?php
/**
 * AI Polyglot uninstall handler.
 *
 * Cleans up plugin data when the plugin is deleted via wp-admin.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Always clean plugin options
delete_option('polyglot_wc_slugs');

// Check if the user opted for full cleanup (shadow deletion)
$full_cleanup = get_option('polyglot_uninstall_delete_shadows', false);

if ($full_cleanup) {
    global $wpdb;

    // Delete all shadow posts (posts with _master_id meta)
    $shadow_ids = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_master_id'"
    );
    foreach ($shadow_ids as $shadow_id) {
        wp_delete_post((int) $shadow_id, true);
    }

    // Delete all shadow terms (terms with _master_term_id meta)
    $shadow_term_ids = $wpdb->get_col(
        "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = '_master_term_id'"
    );
    foreach ($shadow_term_ids as $term_id) {
        $term = get_term((int) $term_id);
        if ($term && ! is_wp_error($term)) {
            wp_delete_term((int) $term_id, $term->taxonomy);
        }
    }

    // Delete translated comments (comments with _master_comment_id meta)
    $shadow_comment_ids = $wpdb->get_col(
        "SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = '_master_comment_id'"
    );
    foreach ($shadow_comment_ids as $comment_id) {
        wp_delete_comment((int) $comment_id, true);
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

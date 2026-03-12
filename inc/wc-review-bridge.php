<?php

// ============================================================
// REVIEW BRIDGE — Shadow delegates reviews to Master
// ============================================================

add_filter('woocommerce_product_get_review_count', 'polyglot_virtual_review_count', 10, 2);
add_filter('woocommerce_product_get_average_rating', 'polyglot_virtual_average_rating', 10, 2);
add_filter('woocommerce_product_get_rating_counts', 'polyglot_virtual_rating_counts', 10, 2);

function polyglot_virtual_review_count($value, $product)
{
    $master = polyglot_get_master($product);
    $target_id = $master ? $master->get_id() : $product->get_id();

    return polyglot_count_locale_reviews($target_id);
}

function polyglot_count_locale_reviews($post_id)
{
    return array_sum(polyglot_locale_rating_counts($post_id));
}

function polyglot_virtual_average_rating($value, $product)
{
    $master = polyglot_get_master($product);
    $target_id = $master ? $master->get_id() : $product->get_id();
    $counts = polyglot_locale_rating_counts($target_id);
    $total = array_sum($counts);

    if (! $total) {
        return 0;
    }

    $sum = 0;
    foreach ($counts as $star => $count) {
        $sum += $star * $count;
    }

    return round($sum / $total, 2);
}

function polyglot_virtual_rating_counts($value, $product)
{
    $master = polyglot_get_master($product);
    $target_id = $master ? $master->get_id() : $product->get_id();

    return polyglot_locale_rating_counts($target_id);
}

function polyglot_locale_rating_counts($post_id)
{
    static $cache = [];
    $locale = polyglot_get_current_locale();
    $key = "{$post_id}|{$locale}";

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $wp_cache_key = "ratings|{$post_id}|{$locale}";
    $wp_cached = wp_cache_get($wp_cache_key, 'polyglot');
    if (false !== $wp_cached) {
        return $cache[$key] = $wp_cached;
    }

    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT cm_rating.meta_value AS rating, COUNT(*) AS cnt
         FROM {$wpdb->comments} c
         INNER JOIN {$wpdb->commentmeta} cm_rating ON (c.comment_ID = cm_rating.comment_id AND cm_rating.meta_key = 'rating')
         LEFT JOIN {$wpdb->commentmeta} pcm_source ON (c.comment_ID = pcm_source.comment_id AND pcm_source.meta_key = '_source_locale')
         LEFT JOIN {$wpdb->commentmeta} pcm_master ON (c.comment_ID = pcm_master.comment_id AND pcm_master.meta_key = '_master_comment_id')
         LEFT JOIN {$wpdb->commentmeta} pcm_locale ON (c.comment_ID = pcm_locale.comment_id AND pcm_locale.meta_key = '_locale')
         WHERE c.comment_post_ID = %d
           AND c.comment_approved = '1'
           AND c.comment_type IN ('review', '')
           AND (
               (pcm_source.meta_value = %s AND pcm_master.comment_id IS NULL)
               OR (pcm_locale.meta_value = %s AND pcm_master.comment_id IS NOT NULL)
           )
         GROUP BY cm_rating.meta_value",
        $post_id,
        $locale,
        $locale
    ));

    $counts = [];
    foreach ($rows as $row) {
        $counts[(int) $row->rating] = (int) $row->cnt;
    }

    wp_cache_set($wp_cache_key, $counts, 'polyglot', 1800);

    return $cache[$key] = $counts;
}

add_filter('preprocess_comment', 'polyglot_redirect_review_submission');

function polyglot_redirect_review_submission($commentdata)
{
    $post_id = $commentdata['comment_post_ID'] ?? 0;
    if (! $post_id) {
        return $commentdata;
    }

    $post = get_post($post_id);
    if (! $post || 'product' !== $post->post_type) {
        return $commentdata;
    }

    $master_id = get_post_meta($post_id, '_master_id', true);
    if ($master_id) {
        $commentdata['comment_post_ID'] = (int) $master_id;
    }

    return $commentdata;
}

add_action('comment_post', 'polyglot_tag_review_source_locale', 10, 2);

function polyglot_tag_review_source_locale($comment_id, $comment_approved)
{
    $comment = get_comment($comment_id);
    $post = get_post($comment->comment_post_ID);

    if (! $post || 'product' !== $post->post_type) {
        return;
    }

    // Only tag original reviews (not shadow translations)
    if (get_comment_meta($comment_id, '_master_comment_id', true)) {
        return;
    }

    update_comment_meta($comment_id, '_source_locale', polyglot_get_current_locale());
}

add_filter('comments_clauses', 'polyglot_virtual_review_comments_clauses', 10, 2);

function polyglot_virtual_review_comments_clauses($clauses, $query)
{
    $post_id = $query->query_vars['post_id'] ?? 0;
    if (! $post_id) {
        return $clauses;
    }

    $post = get_post($post_id);
    if (! $post || 'product' !== $post->post_type) {
        return $clauses;
    }

    // If this is a shadow product, rewrite to query master's comments
    $master_id = get_post_meta($post_id, '_master_id', true);
    $target_post_id = $master_id ? (int) $master_id : $post_id;

    global $wpdb;
    $locale = polyglot_get_current_locale();

    // Rewrite WHERE to target master product
    $clauses['where'] = $wpdb->prepare(
        preg_replace('/comment_post_ID\s*=\s*\d+/', 'comment_post_ID = %d', $clauses['where']),
        $target_post_id
    );

    // Join commentmeta for locale filtering
    $clauses['join'] .= " LEFT JOIN {$wpdb->commentmeta} AS pcm_source ON ({$wpdb->comments}.comment_ID = pcm_source.comment_id AND pcm_source.meta_key = '_source_locale')";
    $clauses['join'] .= " LEFT JOIN {$wpdb->commentmeta} AS pcm_master ON ({$wpdb->comments}.comment_ID = pcm_master.comment_id AND pcm_master.meta_key = '_master_comment_id')";
    $clauses['join'] .= " LEFT JOIN {$wpdb->commentmeta} AS pcm_locale ON ({$wpdb->comments}.comment_ID = pcm_locale.comment_id AND pcm_locale.meta_key = '_locale')";

    // Filter: originals in current locale OR translations targeting current locale
    $clauses['where'] .= $wpdb->prepare(
        ' AND ((pcm_source.meta_value = %s AND pcm_master.comment_id IS NULL) OR (pcm_locale.meta_value = %s AND pcm_master.comment_id IS NOT NULL))',
        $locale,
        $locale
    );

    return $clauses;
}

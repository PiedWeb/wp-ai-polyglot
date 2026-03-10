<?php

// ============================================================
// ROUTING — Each domain sees only its locale
// ============================================================

add_action('pre_get_posts', 'polyglot_filter_by_domain');

/**
 * On shadow domains, swap page_on_front / page_for_posts to their shadow IDs
 * so that WordPress resolves "/" to the correct translated homepage.
 */
add_filter('pre_option_page_on_front', 'polyglot_swap_static_page');
add_filter('pre_option_page_for_posts', 'polyglot_swap_static_page');

function polyglot_swap_static_page($pre)
{
    if (is_admin() || polyglot_is_master()) {
        return $pre; // false = let WP use the real option
    }

    // Remove filter temporarily to read the real option without recursion
    $filter = current_filter();
    $option = str_replace('pre_option_', '', $filter);
    remove_filter($filter, 'polyglot_swap_static_page');
    $master_id = (int) get_option($option);
    add_filter($filter, 'polyglot_swap_static_page');

    if (! $master_id) {
        return $pre;
    }

    $shadow_id = polyglot_wc_page_id($master_id);

    return $shadow_id !== $master_id ? $shadow_id : $pre;
}

function polyglot_filter_by_domain($query): void
{
    if (is_admin() && ! (defined('REST_REQUEST') && REST_REQUEST)) {
        if ($query->is_main_query()) {
            polyglot_admin_filter_shadows($query);
        }

        return;
    }

    // For secondary queries, only filter managed post types
    if (! $query->is_main_query()) {
        $types = (array) $query->get('post_type');
        if (empty($types) || '' === $types[0] || array_diff($types, polyglot_get_post_types())) {
            return;
        }
    }

    $locale = polyglot_get_current_locale();
    $is_master = polyglot_is_master();
    $meta_query = $query->get('meta_query') ?: [];

    if ($is_master) {
        // Master domain: show posts WITHOUT _master_id
        $meta_query[] = [
            'key' => '_master_id',
            'compare' => 'NOT EXISTS',
        ];
    } else {
        // Shadow domain: show posts matching this locale
        $meta_query[] = [
            'key' => '_locale',
            'value' => $locale,
        ];
    }

    $query->set('meta_query', $meta_query);
}

function polyglot_admin_filter_shadows($query)
{
    $post_type = $query->get('post_type');
    if (! in_array($post_type, polyglot_get_post_types(), true)) {
        return;
    }

    $filter = sanitize_text_field($_GET['polyglot_locale'] ?? '');
    $meta_query = $query->get('meta_query') ?: [];

    if ('all' === $filter) {
        // Show everything — no filter
        return;
    }

    if ('' !== $filter) {
        // Show only shadows for this locale
        $meta_query[] = [
            'key' => '_locale',
            'value' => $filter,
        ];
    } else {
        // Default: hide shadows, show only masters
        $meta_query[] = [
            'key' => '_master_id',
            'compare' => 'NOT EXISTS',
        ];
    }

    $query->set('meta_query', $meta_query);
}

// ============================================================
// WOOCOMMERCE PAGE ROUTING — Map cart/checkout to shadow pages
// ============================================================

add_filter('woocommerce_get_cart_page_id', 'polyglot_wc_page_id');
add_filter('woocommerce_get_checkout_page_id', 'polyglot_wc_page_id');

function polyglot_wc_page_id(int $page_id): int
{
    static $cache = [];

    if (polyglot_is_master()) {
        return $page_id;
    }

    $locale = polyglot_get_current_locale();
    $key = "{$page_id}|{$locale}";

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    global $wpdb;
    $shadow_id = $wpdb->get_var($wpdb->prepare(
        "SELECT pm1.post_id FROM $wpdb->postmeta pm1
         JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
         WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d
         AND pm2.meta_value = %s LIMIT 1",
        $page_id,
        $locale
    ));

    return $cache[$key] = $shadow_id ? (int) $shadow_id : $page_id;
}

// ============================================================
// BLOCK BRIDGE — Swap product IDs in WC blocks on shadow domains
// ============================================================

add_filter('render_block_data', 'polyglot_swap_block_product_ids');

function polyglot_swap_block_product_ids(array $block): array
{
    if (polyglot_is_master()
        || 'woocommerce/handpicked-products' !== $block['blockName']
        || empty($block['attrs']['products'])
        || ! is_array($block['attrs']['products'])
    ) {
        return $block;
    }

    $block['attrs']['products'] = array_map('polyglot_wc_page_id', $block['attrs']['products']);

    return $block;
}

// ============================================================
// SESSION BRIDGE — Restore WooCommerce session across domains
// ============================================================

add_action('init', 'polyglot_restore_session_from_url', 1);

function polyglot_restore_session_from_url(): void
{
    if (empty($_GET['wc_session']) || ! function_exists('WC')) {
        return;
    }

    $parts = explode('|', sanitize_text_field($_GET['wc_session']));
    if (3 !== count($parts)) {
        return;
    }

    [$session_key, $expires, $sig] = $parts;
    $token = $session_key.'|'.$expires;

    // Verify HMAC signature
    if (! hash_equals(hash_hmac('sha256', $token, wp_salt('auth')), $sig)) {
        return;
    }

    // Verify not expired
    if (time() > (int) $expires) {
        return;
    }

    wc_setcookie('wp_woocommerce_session_'.COOKIEHASH, $session_key, time() + 86400, '/');
    wp_safe_redirect(remove_query_arg('wc_session'));
    exit;
}

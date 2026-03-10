<?php

// ============================================================
// WC PERMALINK SLUGS — Locale-aware product/category/tag bases
// ============================================================
//
// Stored in option 'polyglot_wc_slugs' as:
//   [ 'en_IE' => ['product_base'=>'hangboard', 'category_base'=>'product-category', 'tag_base'=>'product-tag'], ... ]
//
// Master locale slugs come from the 'woocommerce_permalinks' option (no override needed).
// Manage via:  wp polyglot translate-slugs [--target=<locale>]
//

/**
 * Allow identical slugs across different locales.
 *
 * WordPress suffixes duplicate slugs with -2, -3, etc. via wp_unique_post_slug().
 * Since Polyglot routes by domain, identical slugs in different locales are fine.
 * This filter short-circuits the uniqueness check when no same-locale conflict exists.
 */
add_filter('pre_wp_unique_post_slug', function ($override, $slug, $post_id, $post_status, $post_type, $post_parent) {
    global $wp_rewrite, $wpdb;

    if (! in_array($post_type, polyglot_get_post_types(), true)) {
        return $override;
    }

    // Skip reserved slugs (feeds, embed, etc.)
    $feeds = is_array($wp_rewrite?->feeds) ? $wp_rewrite->feeds : [];
    if (in_array($slug, [...$feeds, 'embed'], true)) {
        return $override;
    }

    // Determine the locale of the post being saved
    $locale = $GLOBALS['polyglot_pending_locale']
        ?? ($post_id ? get_post_meta($post_id, '_locale', true) ?: null : null);

    if (! $locale) {
        return $override; // Master post or non-polyglot — let WP handle it
    }

    // Check for a same-locale slug conflict
    $conflict = $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_locale' AND pm.meta_value = %s
         WHERE p.post_name = %s AND p.post_type = %s AND p.ID != %d
         LIMIT 1",
        $locale,
        $slug,
        $post_type,
        $post_id
    ));

    if ($conflict) {
        return $override;
    }

    // For master-locale posts, also check against other master posts (no _master_id meta)
    if ($locale === polyglot_get_master_locale()) {
        $conflict = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_master_id'
             WHERE p.post_name = %s AND p.post_type = %s AND p.ID != %d AND pm.meta_value IS NULL
             LIMIT 1",
            $slug,
            $post_type,
            $post_id
        ));

        if ($conflict) {
            return $override;
        }
    }

    return $slug;
}, 10, 6);

// ============================================================
// PERMALINK RESOLUTION — Locale-aware URL resolution
// ============================================================

/**
 * Resolve a slug to a post ID based on locale and post type.
 *
 * On master domain: finds the post with no _master_id (i.e. the master post),
 * matching by custom_permalink (priority) or post_name (fallback).
 * On shadow domains: finds the post with the correct _locale meta,
 * matching by custom_permalink (priority) or post_name (fallback).
 */
function polyglot_resolve_by_slug(string $slug, string $post_type, string $locale, bool $is_master): ?int
{
    global $wpdb;

    if ($is_master) {
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} mid ON mid.post_id = p.ID AND mid.meta_key = '_master_id'
            LEFT JOIN {$wpdb->postmeta} cp ON cp.post_id = p.ID AND cp.meta_key = 'custom_permalink'
            WHERE (TRIM(TRAILING '/' FROM cp.meta_value) = %s OR (cp.meta_value IS NULL AND p.post_name = %s))
              AND p.post_type = %s
              AND p.post_status = 'publish'
              AND mid.meta_value IS NULL
            LIMIT 1",
            $slug,
            $slug,
            $post_type
        ));

        return $id ?: null;
    }

    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT p.ID FROM {$wpdb->posts} p
        INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID
            AND loc.meta_key = '_locale' AND loc.meta_value = %s
        LEFT JOIN {$wpdb->postmeta} cp ON cp.post_id = p.ID AND cp.meta_key = 'custom_permalink'
        WHERE (TRIM(TRAILING '/' FROM cp.meta_value) = %s OR (cp.meta_value IS NULL AND p.post_name = %s))
          AND p.post_type = %s
          AND p.post_status = 'publish'
        LIMIT 1",
        $locale,
        $slug,
        $slug,
        $post_type
    ));

    return $id ?: null;
}

/**
 * Locale-aware request filter: resolve slugs to the correct post for the current domain.
 *
 * Handles both pages (including master pages with custom_permalink for flat URLs)
 * and products (flat URLs on all domains).
 */
add_filter('request', function ($query_vars) {
    if (is_admin()) {
        return $query_vars;
    }

    $slug = $query_vars['pagename'] ?? $query_vars['name'] ?? null;
    if (! $slug || isset($query_vars['page_id']) || isset($query_vars['p'])) {
        return $query_vars;
    }

    $locale = polyglot_get_current_locale();
    $is_master = polyglot_is_master();

    // Try page resolution first
    $post_id = polyglot_resolve_by_slug($slug, 'page', $locale, $is_master);
    if ($post_id) {
        return ['page_id' => $post_id];
    }

    // Try product resolution (flat URLs on all domains)
    $post_id = polyglot_resolve_by_slug($slug, 'product', $locale, $is_master);
    if ($post_id) {
        return ['p' => $post_id, 'post_type' => 'product'];
    }

    return $query_vars;
}, 1);

function polyglot_get_wc_slugs(?string $locale = null): ?array
{
    $locale = $locale ?? polyglot_get_current_locale();
    if ($locale === polyglot_get_master_locale()) {
        return null; // Master uses the default woocommerce_permalinks option
    }

    $all = get_option(POLYGLOT_WC_SLUGS_OPTION, []);

    return $all[$locale] ?? null;
}

/**
 * Filter the woocommerce_permalinks option to return locale-specific slugs.
 * This affects both permalink generation (get_permalink) and post type registration (rewrite slug).
 *
 * Shadow locales without explicit slug translations get flat product URLs (no base prefix).
 */
add_filter('option_woocommerce_permalinks', function ($value) {
    if (! is_array($value)) {
        return $value;
    }

    $slugs = polyglot_get_wc_slugs();

    // Shadow locale with explicit slug translations — apply them
    if ($slugs) {
        foreach (['product_base', 'category_base', 'tag_base'] as $key) {
            if (! empty($slugs[$key])) {
                $value[$key] = $slugs[$key];
            }
        }

        return $value;
    }

    // Shadow locale without explicit slugs — flat product URLs (no base)
    if (! polyglot_is_master()) {
        $value['product_base'] = '/';
    }

    return $value;
});

/**
 * Flat product permalinks on ALL domains: generate /{slug}/ URLs.
 *
 * Uses custom_permalink meta if set, otherwise falls back to post_name.
 * On shadow locales with explicit product_base translations, defer to WooCommerce.
 */
add_filter('post_type_link', function ($link, $post) {
    if ('product' !== $post->post_type) {
        return $link;
    }

    // Shadow locale with explicit product_base translation — let WC handle it
    $slugs = polyglot_get_wc_slugs();
    if ($slugs && ! empty($slugs['product_base'])) {
        return $link;
    }

    $cp = get_post_meta($post->ID, 'custom_permalink', true);
    if ($cp) {
        return home_url('/'.trim($cp, '/').'/');
    }

    return home_url('/'.$post->post_name.'/');
}, 10, 2);

/**
 * Flat page URLs: use custom_permalink meta when set (all domains).
 */
add_filter('page_link', function ($link, $post_id) {
    $cp = get_post_meta($post_id, 'custom_permalink', true);
    if ($cp) {
        return home_url('/'.trim($cp, '/').'/');
    }

    return $link;
}, 10, 2);

/**
 * Redirect old hierarchical/prefixed URLs to flat ones.
 *
 * /accueil/blog → /blog (pages with custom_permalink, all domains)
 * /poutre/origin → /origin (products on all domains)
 */
add_action('template_redirect', function () {
    if (! is_singular() || is_admin()) {
        return;
    }

    $post = get_queried_object();
    if (! $post) {
        return;
    }

    $cp = get_post_meta($post->ID, 'custom_permalink', true);
    $request = trim(parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH), '/');

    if ('page' === $post->post_type && $cp && trim($cp, '/') !== $request) {
        wp_redirect(home_url('/'.trim($cp, '/').'/'), 301);
        exit;
    }

    if ('product' === $post->post_type) {
        $expected = $cp ? trim($cp, '/') : $post->post_name;
        if ($expected !== $request) {
            wp_redirect(home_url('/'.$expected.'/'), 301);
            exit;
        }
    }
}, 5);

/**
 * Add rewrite rules for ALL locale product/category/tag bases so incoming URLs resolve
 * regardless of which locale flushed rewrite rules last.
 */
add_action('init', function () {
    $all_slugs = get_option(POLYGLOT_WC_SLUGS_OPTION, []);
    if (empty($all_slugs)) {
        return;
    }

    $product_bases = [];
    $cat_bases = [];
    $tag_bases = [];

    foreach ($all_slugs as $slugs) {
        if (! empty($slugs['product_base'])) {
            $product_bases[] = $slugs['product_base'];
        }
        if (! empty($slugs['category_base'])) {
            $cat_bases[] = $slugs['category_base'];
        }
        if (! empty($slugs['tag_base'])) {
            $tag_bases[] = $slugs['tag_base'];
        }
    }

    foreach (array_unique($product_bases) as $base) {
        add_rewrite_rule('^'.$base.'/([^/]+)/?$', 'index.php?product=$matches[1]', 'top');
        add_rewrite_rule('^'.$base.'/([^/]+)/page/([0-9]+)/?$', 'index.php?product=$matches[1]&paged=$matches[2]', 'top');
    }

    foreach (array_unique($cat_bases) as $base) {
        add_rewrite_rule('^'.$base.'/(.+?)/page/([0-9]+)/?$', 'index.php?product_cat=$matches[1]&paged=$matches[2]', 'top');
        add_rewrite_rule('^'.$base.'/(.+?)/?$', 'index.php?product_cat=$matches[1]', 'top');
    }

    foreach (array_unique($tag_bases) as $base) {
        add_rewrite_rule('^'.$base.'/([^/]+)/page/([0-9]+)/?$', 'index.php?product_tag=$matches[1]&paged=$matches[2]', 'top');
        add_rewrite_rule('^'.$base.'/([^/]+)/?$', 'index.php?product_tag=$matches[1]', 'top');
    }
}, 5); // Priority 5 = before WooCommerce registers its post types at 10

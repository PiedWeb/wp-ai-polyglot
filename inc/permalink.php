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
 * Check whether a post ID is the static front page or a shadow of it.
 *
 * Reads the real (unswapped) page_on_front so this works cross-domain
 * (e.g. when the FR master sitemap generates hreflang for the EN shadow homepage).
 */
function polyglot_is_front_page(int $post_id): bool
{
    // Read the real (unswapped) page_on_front option
    remove_filter('pre_option_page_on_front', 'polyglot_swap_static_page');
    $master_front_id = (int) get_option('page_on_front');
    add_filter('pre_option_page_on_front', 'polyglot_swap_static_page');

    if (! $master_front_id) {
        return false;
    }

    // Direct match (master front page or swapped front page on shadow domain)
    if ($post_id === $master_front_id) {
        return true;
    }

    // Shadow of the front page
    return (int) get_post_meta($post_id, '_master_id', true) === $master_front_id;
}

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
    $cache_key = "slug|{$slug}|{$post_type}|{$locale}|".($is_master ? '1' : '0');
    $cached = wp_cache_get($cache_key, 'polyglot');
    if (false !== $cached) {
        return $cached ?: null;
    }

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
    } else {
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
    }

    wp_cache_set($cache_key, $id ?: 0, 'polyglot', 3600);

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
 * Flat product permalinks on ALL domains: generate /{slug} URLs (no trailing slash).
 *
 * Uses custom_permalink meta if set (ltrim only — user-defined trailing slash preserved),
 * otherwise falls back to post_name.
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
        return home_url('/'.ltrim($cp, '/'));
    }

    return home_url('/'.$post->post_name);
}, 10, 2);

/**
 * Flat page URLs: use custom_permalink meta when set (all domains).
 */
add_filter('page_link', function ($link, $post_id) {
    // Don't override the static front page or its shadows — homepage is always /
    if ('page' === get_option('show_on_front') && polyglot_is_front_page((int) $post_id)) {
        return home_url('/');
    }

    $cp = get_post_meta($post_id, 'custom_permalink', true);
    if ($cp) {
        return home_url('/'.ltrim($cp, '/'));
    }

    // Flatten hierarchical URLs for child pages (polyglot resolves all pages by flat slug)
    $post = get_post($post_id);
    if ($post && $post->post_parent) {
        return home_url('/'.$post->post_name);
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

    // Never redirect the static front page — it's served at /
    if ('page' === get_option('show_on_front') && polyglot_is_front_page($post->ID)) {
        return;
    }

    $canonical = get_permalink($post->ID);
    $request_path = parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH);
    $canonical_path = parse_url($canonical, \PHP_URL_PATH);

    if (in_array($post->post_type, ['page', 'product'], true) && $request_path !== $canonical_path) {
        wp_redirect($canonical, 301);
        exit;
    }
}, 5);

/**
 * Cross-locale slug redirect: when a slug from another locale is requested on a shadow domain,
 * find the master post and 301-redirect to the correct shadow URL.
 *
 * Checks the master locale first, then all other shadow locales as fallback.
 * Handles both bare slugs (/poutre-portable) and prefixed product URLs (/hangboard/origin/).
 */
add_action('template_redirect', function () {
    if (! is_404() || is_admin() || polyglot_is_master()) {
        return;
    }

    $request = trim(parse_url($_SERVER['REQUEST_URI'], \PHP_URL_PATH), '/');
    if (! $request) {
        return;
    }

    $locale = polyglot_get_current_locale();
    $slugs_to_try = [$request];

    // Also try stripping the locale's product base prefix (e.g. hangboard/origin → origin)
    $wc_slugs = polyglot_get_wc_slugs($locale);
    if (! empty($wc_slugs['product_base'])) {
        $prefix = trim($wc_slugs['product_base'], '/').'/';
        if (str_starts_with($request, $prefix)) {
            $slugs_to_try[] = substr($request, strlen($prefix));
        }
    }

    // Also try stripping ALL locale product base prefixes
    $all_slugs = get_option(POLYGLOT_WC_SLUGS_OPTION, []);
    foreach ($all_slugs as $slugs) {
        if (! empty($slugs['product_base'])) {
            $prefix = trim($slugs['product_base'], '/').'/';
            if (str_starts_with($request, $prefix)) {
                $slugs_to_try[] = substr($request, strlen($prefix));
            }
        }
    }
    $slugs_to_try = array_unique($slugs_to_try);

    foreach ($slugs_to_try as $slug) {
        foreach (['page', 'product'] as $post_type) {
            // Try master locale first
            $master_id = polyglot_resolve_by_slug($slug, $post_type, polyglot_get_master_locale(), true);

            // Fallback: try all other shadow locales to find the master_id
            if (! $master_id) {
                $master_id = polyglot_find_master_from_shadow_slug($slug, $post_type);
            }

            if (! $master_id) {
                continue;
            }

            $shadow_id = polyglot_wc_page_id($master_id);
            if ($shadow_id && $shadow_id !== $master_id) {
                wp_redirect(get_permalink($shadow_id), 301);
                exit;
            }
        }
    }
}, 1);

/**
 * Find a master post ID by looking up a slug across all shadow locales.
 */
function polyglot_find_master_from_shadow_slug(string $slug, string $post_type): ?int
{
    global $wpdb;

    $master_id = $wpdb->get_var($wpdb->prepare(
        "SELECT mid.meta_value FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} mid ON p.ID = mid.post_id AND mid.meta_key = '_master_id'
         LEFT JOIN {$wpdb->postmeta} cp ON p.ID = cp.post_id AND cp.meta_key = 'custom_permalink'
         WHERE (TRIM(TRAILING '/' FROM cp.meta_value) = %s OR (cp.meta_value IS NULL AND p.post_name = %s))
           AND p.post_type = %s
           AND p.post_status = 'publish'
         LIMIT 1",
        $slug,
        $slug,
        $post_type
    ));

    return $master_id ? (int) $master_id : null;
}

/**
 * Prevent WordPress redirect_canonical() from redirecting polyglot-resolved flat URLs
 * to hierarchical ones (e.g. /faq/ → /accueil/faq/) or adding a trailing slash.
 *
 * Handles two cases:
 * 1. is_singular() — post already resolved by our request filter: compare against get_permalink().
 * 2. Not yet singular — WP may try to redirect /slug → /slug/ because permalink_structure ends
 *    with '/'. If the slug resolves to a polyglot post, block the redirect.
 */
add_filter('redirect_canonical', function ($redirect_url, $requested_url) {
    $requested_path = parse_url($requested_url, \PHP_URL_PATH);

    if (is_singular()) {
        $post = get_queried_object();
        if (! $post || ! in_array($post->post_type, polyglot_get_post_types(), true)) {
            return $redirect_url;
        }

        $canonical      = get_permalink($post->ID);
        $canonical_path = parse_url($canonical, \PHP_URL_PATH);

        // Exact match — no redirect needed
        if ($requested_path === $canonical_path) {
            return false;
        }

        // Redirect to polyglot canonical (handles hierarchical rewrites)
        return $canonical;
    }

    // Non-singular: block WP from adding a trailing slash to a polyglot slug.
    // Happens when permalink_structure ends with '/' and WP doesn't recognise the flat URL.
    $redirect_path = parse_url($redirect_url, \PHP_URL_PATH);
    if ($redirect_path === rtrim($requested_path, '/').'/') {
        $slug      = trim($requested_path, '/');
        $locale    = polyglot_get_current_locale();
        $is_master = polyglot_is_master();

        foreach (polyglot_get_post_types() as $post_type) {
            if (polyglot_resolve_by_slug($slug, $post_type, $locale, $is_master)) {
                return false; // Known polyglot slug — block the trailing-slash redirect
            }
        }
    }

    return $redirect_url;
}, 10, 2);

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

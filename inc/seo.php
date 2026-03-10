<?php

// ============================================================
// SEO — Automatic hreflang tags (all locales)
// ============================================================

/**
 * Resolve translated paths for the current page context.
 *
 * @return array<string, string> Map of authority => path
 */
function polyglot_get_locale_paths(): array
{
    // Build a context key for memoization
    static $cache = [];

    if (is_front_page()) {
        $context = 'front';
    } elseif (is_singular()) {
        $context = 'post_'.get_queried_object_id();
    } elseif (is_category() || is_tag() || is_tax()) {
        $context = 'term_'.get_queried_object_id();
    } else {
        $context = 'none';
    }

    if (isset($cache[$context])) {
        return $cache[$context];
    }

    $paths = [];

    // --- HOMEPAGE ---
    if (is_front_page()) {
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            $paths[$authority] = '/';
        }

        return $cache[$context] = $paths;
    }

    // --- SINGLE POSTS / PAGES / PRODUCTS ---
    if (is_singular()) {
        global $post, $wpdb;
        $master_id = get_post_meta($post->ID, '_master_id', true);
        $real_master_id = $master_id ? (int) $master_id : $post->ID;

        // Master path
        $master_authority = polyglot_get_master_authority();
        $paths[$master_authority] = parse_url(get_permalink($real_master_id), \PHP_URL_PATH);

        // All shadows for this master
        $shadows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm1.post_id, pm2.meta_value AS locale
             FROM $wpdb->postmeta pm1
             JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
             WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d",
            $real_master_id
        ));

        foreach ($shadows as $shadow) {
            foreach (POLYGLOT_LOCALES as $authority => $cfg) {
                if ($cfg['locale'] === $shadow->locale) {
                    $paths[$authority] = parse_url(get_permalink((int) $shadow->post_id), \PHP_URL_PATH);

                    break;
                }
            }
        }

        return $cache[$context] = $paths;
    }

    // --- CATEGORIES / TAGS ---
    if (is_category() || is_tag() || is_tax()) {
        global $wpdb;
        $term = get_queried_object();
        $term_id = $term->term_id;
        $master_term_id = get_term_meta($term_id, '_master_term_id', true);
        $real_master_term_id = $master_term_id ? (int) $master_term_id : $term_id;

        // Master path
        $master_authority = polyglot_get_master_authority();
        $master_link = get_term_link($real_master_term_id);
        if (! is_wp_error($master_link)) {
            $paths[$master_authority] = parse_url($master_link, \PHP_URL_PATH);
        }

        // All shadow terms
        $shadow_terms = $wpdb->get_results($wpdb->prepare(
            "SELECT tm1.term_id, tm2.meta_value AS locale
             FROM $wpdb->termmeta tm1
             JOIN $wpdb->termmeta tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = '_locale'
             WHERE tm1.meta_key = '_master_term_id' AND tm1.meta_value = %d",
            $real_master_term_id
        ));

        foreach ($shadow_terms as $st) {
            foreach (POLYGLOT_LOCALES as $authority => $cfg) {
                if ($cfg['locale'] === $st->locale) {
                    $link = get_term_link((int) $st->term_id);
                    if (! is_wp_error($link)) {
                        $paths[$authority] = parse_url($link, \PHP_URL_PATH);
                    }

                    break;
                }
            }
        }

        return $cache[$context] = $paths;
    }

    return $cache[$context] = [];
}

add_action('wp_head', 'polyglot_inject_hreflang');

function polyglot_inject_hreflang()
{
    $paths = polyglot_get_locale_paths();

    if (count($paths) < 2) {
        return;
    }

    echo "\n<!-- WP-AI-Polyglot Hreflang -->\n";
    foreach ($paths as $authority => $path) {
        $cfg = POLYGLOT_LOCALES[$authority];
        $url = polyglot_authority_to_url($authority).$path;
        echo '<link rel="alternate" hreflang="'.esc_attr($cfg['hreflang']).'" href="'.esc_url($url).'" />'."\n";
    }
    echo "<!-- /WP-AI-Polyglot Hreflang -->\n";
}

// SITEMAP — Locale filtering
// ============================================================

add_filter('wp_sitemaps_posts_query_args', 'polyglot_sitemap_posts_filter', 20);

function polyglot_sitemap_posts_filter(array $args): array
{
    $locale = polyglot_get_current_locale();
    $is_master = polyglot_is_master();
    $meta_query = $args['meta_query'] ?? [];

    if ($is_master) {
        $meta_query[] = [
            'key' => '_master_id',
            'compare' => 'NOT EXISTS',
        ];
    } else {
        $meta_query[] = [
            'key' => '_locale',
            'value' => $locale,
        ];
    }

    $args['meta_query'] = $meta_query;

    return $args;
}

add_filter('wp_sitemaps_taxonomies_query_args', 'polyglot_sitemap_taxonomies_filter', 20);

function polyglot_sitemap_taxonomies_filter(array $args): array
{
    $locale = polyglot_get_current_locale();
    $is_master = polyglot_is_master();
    $meta_query = $args['meta_query'] ?? [];

    if ($is_master) {
        $meta_query[] = [
            'relation' => 'OR',
            [
                'key' => '_master_term_id',
                'compare' => 'NOT EXISTS',
            ],
        ];
    } else {
        $meta_query[] = [
            'key' => '_locale',
            'value' => $locale,
        ];
    }

    $args['meta_query'] = $meta_query;

    return $args;
}

// ============================================================
// SITEMAP — Hreflang injection via output buffering
// ============================================================

add_action('template_redirect', 'polyglot_sitemap_hreflang_ob_start', 0);

function polyglot_sitemap_hreflang_ob_start()
{
    if (! get_query_var('sitemap')) {
        return;
    }

    ob_start('polyglot_sitemap_hreflang_ob_callback');
}

add_filter('wp_sitemaps_posts_entry', 'polyglot_sitemap_track_entry', 10, 3);

function polyglot_sitemap_track_entry(array $entry, $post, string $post_type): array
{
    global $polyglot_sitemap_url_map;
    if (! isset($polyglot_sitemap_url_map)) {
        $polyglot_sitemap_url_map = [];
    }

    $polyglot_sitemap_url_map[$entry['loc']] = $post->ID;

    return $entry;
}

function polyglot_sitemap_hreflang_ob_callback(string $output): string
{
    global $polyglot_sitemap_url_map;

    if (empty($polyglot_sitemap_url_map) || ! str_contains($output, '<urlset')) {
        return $output;
    }

    // Batch-load all translations for tracked post IDs
    global $wpdb;
    $post_ids = array_values($polyglot_sitemap_url_map);
    $is_master = polyglot_is_master();

    // Determine master IDs
    $master_ids = [];
    if ($is_master) {
        // Posts in the sitemap ARE masters
        $master_ids = $post_ids;
    } else {
        // Posts are shadows — get their master IDs
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value AS master_id FROM $wpdb->postmeta
             WHERE meta_key = '_master_id' AND post_id IN ($placeholders)",
            ...$post_ids
        ));
        foreach ($rows as $row) {
            $master_ids[(int) $row->post_id] = (int) $row->master_id;
        }
    }

    if (empty($master_ids)) {
        return $output;
    }

    // Get all master IDs as a flat list
    $all_master_ids = $is_master ? $master_ids : array_values($master_ids);
    $all_master_ids = array_unique($all_master_ids);
    $placeholders = implode(',', array_fill(0, count($all_master_ids), '%d'));

    // Fetch all shadows for these masters
    $shadows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm1.post_id, pm1.meta_value AS master_id, pm2.meta_value AS locale
         FROM $wpdb->postmeta pm1
         JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
         WHERE pm1.meta_key = '_master_id' AND pm1.meta_value IN ($placeholders)",
        ...$all_master_ids
    ));

    // Build map: master_id => [ locale => shadow_post_id ]
    $shadow_map = [];
    foreach ($shadows as $s) {
        $shadow_map[(int) $s->master_id][$s->locale] = (int) $s->post_id;
    }

    // Build hreflang entries per URL
    $hreflang_map = []; // url => [ [hreflang, href], ... ]
    $master_authority = polyglot_get_master_authority();

    foreach ($polyglot_sitemap_url_map as $url => $post_id) {
        $real_master_id = $is_master ? $post_id : ($master_ids[$post_id] ?? null);
        if (! $real_master_id) {
            continue;
        }

        $links = [];

        // Master link
        $master_permalink = get_permalink($real_master_id);
        $master_path = parse_url($master_permalink, \PHP_URL_PATH);
        $master_cfg = POLYGLOT_LOCALES[$master_authority];
        $links[] = [
            'hreflang' => $master_cfg['hreflang'],
            'href' => polyglot_authority_to_url($master_authority).$master_path,
        ];
        // Shadow links
        if (isset($shadow_map[$real_master_id])) {
            foreach ($shadow_map[$real_master_id] as $locale => $shadow_id) {
                foreach (POLYGLOT_LOCALES as $authority => $cfg) {
                    if ($cfg['locale'] === $locale) {
                        $shadow_path = parse_url(get_permalink($shadow_id), \PHP_URL_PATH);
                        $links[] = [
                            'hreflang' => $cfg['hreflang'],
                            'href' => polyglot_authority_to_url($authority).$shadow_path,
                        ];

                        break;
                    }
                }
            }
        }

        // Only add hreflang if there are at least 2 locales (master + 1 shadow)
        if (count($links) > 1) {
            $hreflang_map[$url] = $links;
        }
    }

    if (empty($hreflang_map)) {
        return $output;
    }

    // Add xmlns:xhtml to <urlset>
    $output = str_replace(
        '<urlset',
        '<urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"',
        $output
    );

    // Inject <xhtml:link> elements after each <loc>
    foreach ($hreflang_map as $url => $links) {
        $xhtml_links = '';
        foreach ($links as $link) {
            $xhtml_links .= "\t\t".'<xhtml:link rel="alternate" hreflang="'
                .esc_attr($link['hreflang']).'" href="'
                .esc_url($link['href']).'" />'."\n";
        }

        $output = str_replace(
            '<loc>'.$url.'</loc>',
            '<loc>'.$url.'</loc>'."\n".$xhtml_links,
            $output
        );
    }

    // Reset the global map
    $polyglot_sitemap_url_map = [];

    return $output;
}

<?php

// ============================================================
// DRAFT LINK HANDLER
// ============================================================
//
// Resolves internal links in post content at render time:
// if the target post is not in 'publish' status, the <a> is
// replaced with <span data-draft="title">text</span> for public
// visitors. Editors keep the active link so they can navigate.
//
// When the target is published later, no content rewrite is
// needed — resolution happens on every render.
//

/**
 * Resolve a URL to a post ID, ignoring post_status.
 *
 * Returns null if the URL is not internal or matches no post.
 * Locale is inferred from the URL host (or current locale for relative URLs).
 */
function polyglot_resolve_url_any_status(string $url): ?int
{
    $parts = wp_parse_url($url);
    if (! $parts || ! empty($parts['scheme']) && ! in_array($parts['scheme'], ['http', 'https'], true)) {
        return null;
    }

    // Determine target locale from host
    $host = $parts['host'] ?? '';
    if ($host && ! isset(POLYGLOT_LOCALES[$host])) {
        return null; // External link
    }

    $entry = $host ? POLYGLOT_LOCALES[$host] : polyglot_get_current_entry();
    if (! $entry) {
        return null;
    }
    $locale = $entry['locale'];
    $is_master = ! empty($entry['master']);

    $path = trim($parts['path'] ?? '', '/');
    if ('' === $path) {
        return null; // Homepage
    }

    // Try last segment first (flat URLs: products and most pages use custom_permalink)
    $segments = explode('/', $path);
    $slug = end($segments);

    $key = "draft_resolve|{$locale}|{$path}";
    $cached = wp_cache_get($key, 'polyglot');
    if (false !== $cached) {
        return $cached ?: null;
    }

    global $wpdb;

    $where_locale = $is_master
        ? "LEFT JOIN {$wpdb->postmeta} mid ON mid.post_id = p.ID AND mid.meta_key = '_master_id'"
        : "INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID AND loc.meta_key = '_locale' AND loc.meta_value = %s";

    $locale_clause = $is_master ? 'AND mid.meta_value IS NULL' : '';

    // Match by full path (custom_permalink) or by trailing slug (post_name)
    $sql = "SELECT p.ID, p.post_status FROM {$wpdb->posts} p
        {$where_locale}
        LEFT JOIN {$wpdb->postmeta} cp ON cp.post_id = p.ID AND cp.meta_key = 'custom_permalink'
        WHERE (TRIM(TRAILING '/' FROM cp.meta_value) = %s
               OR TRIM(TRAILING '/' FROM cp.meta_value) = %s
               OR (cp.meta_value IS NULL AND p.post_name = %s))
          AND p.post_type IN ('page', 'product', 'post')
          AND p.post_status NOT IN ('auto-draft', 'inherit')
          {$locale_clause}
        LIMIT 1";

    $args = $is_master
        ? [$path, $slug, $slug]
        : [$locale, $path, $slug, $slug];

    $row = $wpdb->get_row($wpdb->prepare($sql, ...$args));
    wp_cache_set($key, $row ? (int) $row->ID : 0, 'polyglot', 300);

    return $row ? (int) $row->ID : null;
}

/**
 * Filter post content: hide links to non-published targets from public visitors.
 */
function polyglot_handle_draft_links(string $content): string
{
    if ('' === trim($content) || false === stripos($content, '<a ')) {
        return $content;
    }

    $can_edit = is_user_logged_in() && current_user_can('edit_posts');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="UTF-8"?><div id="polyglot-wrap">'.$content.'</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $links = iterator_to_array($dom->getElementsByTagName('a'));
    $modified = false;

    foreach ($links as $link) {
        $href = $link->getAttribute('href');
        if ('' === $href || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            continue;
        }

        $post_id = polyglot_resolve_url_any_status($href);
        if (! $post_id) {
            continue; // External or unresolvable — leave as-is
        }

        $status = get_post_status($post_id);
        if ('publish' === $status || $can_edit) {
            continue;
        }

        // Replace <a> with <span data-draft="{title}">{children}</span>
        $title = get_the_title($post_id);
        $span = $dom->createElement('span');
        $span->setAttribute('data-draft', $title);
        $span->setAttribute('data-status', $status);
        while ($link->firstChild) {
            $span->appendChild($link->firstChild);
        }
        $link->parentNode->replaceChild($span, $link);
        $modified = true;
    }

    if (! $modified) {
        return $content;
    }

    $wrap = $dom->getElementById('polyglot-wrap');
    $out = '';
    foreach ($wrap->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }

    return $out;
}
add_filter('the_content', 'polyglot_handle_draft_links', 20);

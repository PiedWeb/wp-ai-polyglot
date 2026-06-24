<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// GOOGLE PRODUCT FEED — One endpoint, locale-resolved per domain
// ============================================================
//
// `/polyglot-feed/google.xml` produces a Google Merchant Center RSS 2.0 feed
// for the CURRENT domain's locale. Because the plugin already routes products,
// prices, stock, images and URLs by domain, the same endpoint yields fr_FR/EUR
// on the master and e.g. da_DK/DKK on the Danish shadow with no extra filtering.
//
// Wire each domain's feed URL into Merchant Center as a scheduled-fetch primary
// feed (1 per country/language/currency). Replaces google-listings-and-ads,
// which cannot express the Master/Shadow + multi-currency model.
//
// Disable with: define('POLYGLOT_FEED', false); in wp-config.php.

// ------------------------------------------------------------
// Endpoint registration
// ------------------------------------------------------------

add_action('init', function (): void {
    add_rewrite_rule('^polyglot-feed/google\.xml$', 'index.php?polyglot_feed=google', 'top');
}, 5);

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'polyglot_feed';

    return $vars;
});

// Priority 0 so we render and exit BEFORE the canonical / 404 redirects in
// inc/permalink.php (template_redirect priorities 1 and 5).
add_action('template_redirect', 'polyglot_feed_render', 0);

function polyglot_feed_enabled(): bool
{
    return ! (defined('POLYGLOT_FEED') && ! POLYGLOT_FEED);
}

function polyglot_feed_render(): void
{
    if ('google' !== get_query_var('polyglot_feed')) {
        return;
    }

    if (! polyglot_feed_enabled()) {
        status_header(404);
        exit;
    }

    $locale = polyglot_get_current_locale();
    $cache_key = 'polyglot_feed_google_'.$locale;

    $xml = get_transient($cache_key);
    $bypass = defined('WP_DEBUG') && WP_DEBUG && isset($_GET['nocache']);
    if (false === $xml || $bypass) {
        $xml = polyglot_feed_build_xml();
        set_transient($cache_key, $xml, polyglot_feed_cache_ttl());
    }

    if (! headers_sent()) {
        status_header(200);
        header('Content-Type: application/xml; charset=utf-8');
        header('X-Robots-Tag: noindex, follow');
    }

    echo $xml;
    exit;
}

// ------------------------------------------------------------
// Cache
// ------------------------------------------------------------

function polyglot_feed_cache_ttl(): int
{
    return (int) apply_filters('polyglot_feed_cache_ttl', 6 * HOUR_IN_SECONDS);
}

function polyglot_feed_flush_cache(): void
{
    foreach (POLYGLOT_LOCALES as $cfg) {
        delete_transient('polyglot_feed_google_'.$cfg['locale']);
    }
}

add_action('save_post_product', 'polyglot_feed_flush_cache');
add_action('woocommerce_update_product', 'polyglot_feed_flush_cache');
add_action('woocommerce_product_set_stock', 'polyglot_feed_flush_cache');
add_action('woocommerce_variation_set_stock', 'polyglot_feed_flush_cache');

// ------------------------------------------------------------
// XML building
// ------------------------------------------------------------

function polyglot_feed_build_xml(): string
{
    $items = [];
    if (function_exists('wc_get_products')) {
        foreach (polyglot_feed_get_products() as $product) {
            if ($product->is_type('variable')) {
                foreach (polyglot_feed_map_variable($product) as $item) {
                    $items[] = $item;
                }
            } else {
                $item = polyglot_feed_map_product($product);
                if (null !== $item) {
                    $items[] = $item;
                }
            }
        }
    }

    $title = htmlspecialchars(get_bloginfo('name'), \ENT_XML1, 'UTF-8');
    $link = htmlspecialchars(home_url('/'), \ENT_XML1, 'UTF-8');

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">'."\n";
    $xml .= '<channel>'."\n";
    $xml .= '<title>'.$title.'</title>'."\n";
    $xml .= '<link>'.$link.'</link>'."\n";
    $xml .= '<description>'.$title.'</description>'."\n";
    $xml .= polyglot_feed_render_items($items);
    $xml .= '</channel>'."\n".'</rss>'."\n";

    return $xml;
}

/**
 * @return WC_Product[]
 */
function polyglot_feed_get_products(): array
{
    $args = apply_filters('polyglot_feed_query_args', [
        'status' => 'publish',
        'limit' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'return' => 'objects',
    ]);

    // wc_get_products runs a secondary product WP_Query, so polyglot_filter_by_domain
    // (inc/routing.php) restricts results to the current domain's locale automatically.
    $products = wc_get_products($args);

    return is_array($products) ? $products : [];
}

/**
 * @param array<int, array<string, string|string[]>> $items
 */
function polyglot_feed_render_items(array $items): string
{
    $out = '';
    foreach ($items as $item) {
        $out .= "<item>\n";
        foreach ($item as $tag => $value) {
            if (is_array($value)) {
                foreach ($value as $single) {
                    $out .= polyglot_feed_tag($tag, (string) $single);
                }
            } else {
                $out .= polyglot_feed_tag($tag, (string) $value);
            }
        }
        $out .= "</item>\n";
    }

    return $out;
}

function polyglot_feed_tag(string $tag, string $value): string
{
    if ('' === $value) {
        return '';
    }

    return '  <'.$tag.'>'.htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8').'</'.$tag.'>'."\n";
}

// ------------------------------------------------------------
// Item mapping — simple products
// ------------------------------------------------------------

/**
 * @return array<string, string|string[]>|null
 */
function polyglot_feed_map_product(WC_Product $product): ?array
{
    if (! polyglot_feed_is_includable($product)) {
        return null;
    }

    $currency = polyglot_get_current_currency();

    $item = [
        'g:id' => polyglot_feed_offer_id($product),
        'title' => polyglot_feed_clean_text($product->get_name()),
        'description' => polyglot_feed_description($product),
        'link' => $product->get_permalink(),
        'g:condition' => 'new',
        'g:availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
    ];

    $item += polyglot_feed_price_fields($product, $currency);
    $item += polyglot_feed_image_fields($product);
    $item += polyglot_feed_brand_identifier_fields($product);
    $item += polyglot_feed_taxonomy_fields($product);

    return apply_filters('polyglot_feed_item', $item, $product, null);
}

function polyglot_feed_is_includable(WC_Product $product): bool
{
    if (in_array($product->get_catalog_visibility(), ['hidden', 'search'], true)) {
        return false;
    }
    if ('' === (string) $product->get_price()) {
        return false;
    }

    return ! apply_filters('polyglot_feed_exclude_product', false, $product);
}

// ------------------------------------------------------------
// Item mapping — variable products (variations virtualized from master on shadows)
// ------------------------------------------------------------

/**
 * @return array<int, array<string, string|string[]>>
 */
function polyglot_feed_map_variable(WC_Product $product): array
{
    if (in_array($product->get_catalog_visibility(), ['hidden', 'search'], true)) {
        return [];
    }
    if (apply_filters('polyglot_feed_exclude_product', false, $product)) {
        return [];
    }

    $currency = polyglot_get_current_currency();
    $group_id = polyglot_feed_offer_id($product);

    // Shadow variable products have no variation children of their own — the
    // variations live only on the master. Virtualize them: read the master's
    // variations and convert their (base-currency) prices to the local currency.
    $children = $product->get_children();
    $virtualized = false;
    if (empty($children)) {
        $master = polyglot_feed_master_product($product);
        if ($master) {
            $children = $master->get_children();
            $virtualized = true;
        }
    }

    $items = [];
    foreach ($children as $variation_id) {
        $variation = wc_get_product($variation_id);
        if (! $variation instanceof WC_Product_Variation) {
            continue;
        }
        if ('' === (string) $variation->get_price()) {
            continue;
        }

        $item = [
            'g:id' => $group_id.'_'.$variation_id,
            'g:item_group_id' => $group_id,
            'title' => polyglot_feed_variation_title($product, $variation),
            'description' => polyglot_feed_description($product),
            'link' => polyglot_feed_variation_link($product, $variation),
            'g:condition' => 'new',
            'g:availability' => $variation->is_in_stock() ? 'in_stock' : 'out_of_stock',
        ];

        $item += $virtualized
            ? polyglot_feed_price_fields_converted($variation, $currency)
            : polyglot_feed_price_fields($variation, $currency);
        $item += polyglot_feed_image_fields($variation->get_image_id() ? $variation : $product);
        $item += polyglot_feed_brand_identifier_fields($product);
        $item += polyglot_feed_taxonomy_fields($product);

        $items[] = apply_filters('polyglot_feed_item', $item, $product, $variation);
    }

    return $items;
}

function polyglot_feed_variation_title(WC_Product $parent, WC_Product_Variation $variation): string
{
    $name = polyglot_feed_clean_text($parent->get_name());
    $summary = polyglot_feed_clean_text(wc_get_formatted_variation($variation, true, false, false));

    return '' !== $summary ? $name.' – '.$summary : $name;
}

function polyglot_feed_variation_link(WC_Product $parent, WC_Product_Variation $variation): string
{
    $url = $parent->get_permalink();
    $args = [];
    foreach ($variation->get_variation_attributes() as $key => $value) {
        if ('' !== $value) {
            $args[$key] = rawurlencode($value);
        }
    }

    return $args ? add_query_arg($args, $url) : $url;
}

// ------------------------------------------------------------
// Field builders
// ------------------------------------------------------------

/**
 * Price fields for a product whose price is already expressed in the DISPLAY
 * currency (simple shadow products go through the FX-converting price bridge;
 * master objects are in the base currency which equals the display currency on
 * the master domain).
 *
 * @return array<string, string>
 */
function polyglot_feed_price_fields(WC_Product $product, string $currency): array
{
    return polyglot_feed_price_tags(
        (float) wc_get_price_to_display($product, ['price' => $product->get_regular_price()]),
        (float) wc_get_price_to_display($product),
        $product->is_on_sale(),
        $currency
    );
}

/**
 * Price fields for a MASTER variation surfaced on a shadow domain: its price is
 * in the base currency and must be converted explicitly (the price bridge does
 * not convert a variation that carries its own price).
 *
 * @return array<string, string>
 */
function polyglot_feed_price_fields_converted(WC_Product $variation, string $currency): array
{
    return polyglot_feed_price_tags(
        polyglot_convert_price((float) wc_get_price_to_display($variation, ['price' => $variation->get_regular_price()]), $currency),
        polyglot_convert_price((float) wc_get_price_to_display($variation), $currency),
        $variation->is_on_sale(),
        $currency
    );
}

/**
 * Assemble g:price (+ g:sale_price) from already-final amounts in $currency.
 *
 * @return array<string, string>
 */
function polyglot_feed_price_tags(float $regular, float $active, bool $on_sale, string $currency): array
{
    if ($on_sale && $regular > $active) {
        return [
            'g:price' => polyglot_feed_format_amount($regular, $currency),
            'g:sale_price' => polyglot_feed_format_amount($active, $currency),
        ];
    }

    return ['g:price' => polyglot_feed_format_amount($active, $currency)];
}

function polyglot_feed_format_amount(float $amount, string $currency): string
{
    return number_format($amount, 2, '.', '').' '.$currency;
}

/**
 * @return array<string, string|string[]>
 */
function polyglot_feed_image_fields(WC_Product $product): array
{
    $fields = [];

    $image_id = $product->get_image_id();
    $main = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    if (! $main && function_exists('wc_placeholder_img_src')) {
        $main = wc_placeholder_img_src('full');
    }
    if ($main) {
        $fields['g:image_link'] = $main;
    }

    $gallery = [];
    foreach ($product->get_gallery_image_ids() as $gid) {
        $url = wp_get_attachment_image_url($gid, 'full');
        if ($url) {
            $gallery[] = $url;
        }
    }
    if ($gallery) {
        $fields['g:additional_image_link'] = array_slice($gallery, 0, 10);
    }

    return $fields;
}

/**
 * @return array<string, string>
 */
function polyglot_feed_brand_identifier_fields(WC_Product $product): array
{
    $fields = [];

    $brand = (string) apply_filters('polyglot_feed_brand', polyglot_feed_default_brand(), $product);
    if ('' !== $brand) {
        $fields['g:brand'] = $brand;
    }

    $ref = polyglot_feed_master_product($product) ?: $product;
    $gtin = (string) ($ref->get_meta('_gtin', true) ?: $ref->get_meta('gtin', true));
    $mpn = (string) $ref->get_sku();

    if ('' !== $gtin) {
        $fields['g:gtin'] = $gtin;
    }
    if ('' !== $mpn) {
        $fields['g:mpn'] = $mpn;
    }
    // Handmade goods with no GTIN/MPN: tell Google identifiers don't exist.
    if ('' === $gtin && '' === $mpn) {
        $fields['g:identifier_exists'] = 'no';
    }

    return $fields;
}

function polyglot_feed_default_brand(): string
{
    if (defined('POLYGLOT_FEED_BRAND')) {
        return (string) POLYGLOT_FEED_BRAND;
    }

    return (string) get_bloginfo('name');
}

/**
 * @return array<string, string>
 */
function polyglot_feed_taxonomy_fields(WC_Product $product): array
{
    $fields = [];

    $path = polyglot_feed_category_path($product);
    if ('' !== $path) {
        $fields['g:product_type'] = $path;
    }

    $gpc = polyglot_feed_google_product_category($product);
    if ('' !== $gpc) {
        $fields['g:google_product_category'] = $gpc;
    }

    return $fields;
}

function polyglot_feed_category_path(WC_Product $product): string
{
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if (! $terms || is_wp_error($terms)) {
        return '';
    }

    $term = $terms[0];
    $names = [];
    foreach (array_reverse(get_ancestors($term->term_id, 'product_cat')) as $ancestor_id) {
        $ancestor = get_term($ancestor_id, 'product_cat');
        if ($ancestor && ! is_wp_error($ancestor)) {
            $names[] = $ancestor->name;
        }
    }
    $names[] = $term->name;

    return implode(' > ', $names);
}

function polyglot_feed_google_product_category(WC_Product $product): string
{
    $map = (array) get_option('polyglot_feed_gpc', []);
    $terms = get_the_terms($product->get_id(), 'product_cat');
    if ($terms && ! is_wp_error($terms)) {
        foreach ($terms as $term) {
            if (isset($map[$term->slug])) {
                return (string) $map[$term->slug];
            }
        }
    }

    $default = defined('POLYGLOT_FEED_GPC_DEFAULT') ? (string) POLYGLOT_FEED_GPC_DEFAULT : '';

    return (string) apply_filters('polyglot_feed_gpc', $default, $product);
}

function polyglot_feed_description(WC_Product $product): string
{
    $text = $product->get_short_description() ?: $product->get_description();
    $text = wp_strip_all_tags($text, true);
    $text = polyglot_feed_clean_text($text);
    if ('' === $text) {
        $text = polyglot_feed_clean_text($product->get_name());
    }
    if (mb_strlen($text) > 5000) {
        $text = mb_substr($text, 0, 4997).'…';
    }

    return $text;
}

/**
 * Decode HTML entities (e.g. &nbsp; baked into titles) and collapse whitespace
 * so values are clean text before XML escaping — avoids double-escaped entities.
 */
function polyglot_feed_clean_text(string $text): string
{
    $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text); // non-breaking space → normal space

    return trim((string) preg_replace('/\s+/', ' ', $text));
}

// ------------------------------------------------------------
// Identity helpers — offer IDs anchored on the master for cross-feed stability
// ------------------------------------------------------------

function polyglot_feed_master_product(WC_Product $product): ?WC_Product
{
    $master_id = (int) $product->get_meta('_master_id');
    if (! $master_id) {
        return null;
    }

    $master = wc_get_product($master_id);

    return $master ?: null;
}

function polyglot_feed_offer_id(WC_Product $product): string
{
    $ref = polyglot_feed_master_product($product) ?: $product;
    $sku = (string) $ref->get_sku();

    return '' !== $sku ? $sku : 'wc_'.$ref->get_id();
}

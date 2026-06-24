<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// FACEBOOK / META CATALOG — Keep shadow products out of the feed
// ============================================================
//
// facebook-for-woocommerce builds ONE domain-agnostic catalog feed from every
// published product. Its product query asks for the 'product_variation' post
// type, which slips past polyglot_filter_by_domain() (that guard bails as soon
// as a non-managed post type is requested). Without this filter the feed emits
// each shadow as a duplicate catalog item — translated title, a master-domain
// URL that 404s (the translated slug only resolves on the shadow domain) and
// its own retailer id (the shadow post id), inflating the catalog ~8×.
//
// Both the feed writer (WC_Facebook_Product_Feed::write_products_feed_to_temp_file)
// and the real-time single-product sync gate on the product sync validator,
// which exposes the `wc_facebook_should_sync_product` filter. Excluding shadows
// there removes them from the catalog entirely while leaving the master (FR)
// products untouched. No-op when facebook-for-woocommerce is inactive (the
// filter simply never fires).

add_filter('wc_facebook_should_sync_product', 'polyglot_fb_exclude_shadows', 10, 2);

/**
 * Exclude shadow products (and their variations) from the Facebook catalog.
 *
 * @param bool $should  Whether Facebook should sync the product so far.
 * @param mixed $product The WC_Product under evaluation.
 *
 * @return bool
 */
function polyglot_fb_exclude_shadows($should, $product)
{
    // Respect any earlier exclusion; only ever narrow, never re-enable.
    if (! $should || ! $product instanceof WC_Product) {
        return $should;
    }

    // Variations carry no _master_id of their own — resolve to the parent.
    $product_id = $product->get_parent_id() ?: $product->get_id();

    return get_post_meta($product_id, '_master_id', true) ? false : $should;
}

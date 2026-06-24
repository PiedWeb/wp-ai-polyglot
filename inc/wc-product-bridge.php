<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// INVENTORY BRIDGE — Shadow reads stock from Master
// ============================================================

add_filter('woocommerce_product_get_stock_quantity', 'polyglot_virtual_stock', 10, 2);
add_filter('woocommerce_product_get_stock_status', 'polyglot_virtual_stock_status', 10, 2);
add_filter('woocommerce_product_is_in_stock', 'polyglot_virtual_in_stock', 10, 2);

function polyglot_get_master($product)
{
    if (polyglot_is_master()) {
        return null;
    }

    static $cache = [];

    $product_id = $product->get_id();
    if (array_key_exists($product_id, $cache)) {
        return $cache[$product_id];
    }

    $master_id = $product->get_meta('_master_id');
    if (! $master_id) {
        return $cache[$product_id] = null;
    }

    return $cache[$product_id] = wc_get_product($master_id);
}

function polyglot_virtual_stock($value, $product)
{
    $master = polyglot_get_master($product);

    return $master ? $master->get_stock_quantity() : $value;
}

function polyglot_virtual_stock_status($value, $product)
{
    $master = polyglot_get_master($product);

    return $master ? $master->get_stock_status() : $value;
}

function polyglot_virtual_in_stock($value, $product)
{
    $master = polyglot_get_master($product);

    return $master ? $master->is_in_stock() : $value;
}

// Prevent WC from reducing stock on shadow products (they don't manage stock).
// Instead, reduce stock on the master product directly.
add_filter('woocommerce_order_item_quantity', 'polyglot_intercept_shadow_stock_reduction', 10, 3);

function polyglot_intercept_shadow_stock_reduction(int $quantity, $order, $item)
{
    $product = $item->get_product();
    if (! $product) {
        return $quantity;
    }

    $master_id = $product->get_meta('_master_id');
    if (! $master_id) {
        return $quantity; // Not a shadow — let WC handle it
    }

    // Reduce master stock ourselves, then return 0 to prevent WC from reducing shadow stock
    $master_product = wc_get_product($master_id);
    if ($master_product && $master_product->managing_stock()) {
        wc_update_product_stock($master_product, $quantity, 'decrease');
    }

    return 0;
}

// ============================================================
// CURRENCY BRIDGE — Per-domain currency
// ============================================================
//
// Shadow domains report their own currency (POLYGLOT_LOCALES[...]['currency']).
// Combined with the price conversion below, the storefront and the Google feed
// show identical prices in the local currency — a Merchant Center requirement.
// Admin / master keep the store's base currency unchanged.

add_filter('woocommerce_currency', 'polyglot_domain_currency');

function polyglot_domain_currency($currency)
{
    if (is_admin() && ! (defined('REST_REQUEST') && REST_REQUEST)) {
        return $currency;
    }

    $entry = polyglot_get_current_entry();

    return $entry['currency'] ?? $currency;
}

/**
 * Convert an amount expressed in the base (master) currency to the current
 * domain currency. No-op on the base currency or when the amount is empty.
 *
 * @param string|float|int $amount
 *
 * @return string|float|int
 */
function polyglot_maybe_convert_from_base($amount)
{
    if ('' === $amount || null === $amount) {
        return $amount;
    }

    $currency = polyglot_get_current_currency();
    if ($currency === polyglot_fx_base_currency()) {
        return $amount;
    }

    return (string) polyglot_convert_price((float) $amount, $currency);
}

// ============================================================
// PRICE BRIDGE — Shadow reads prices from Master (FX-converted when needed)
// ============================================================
//
// A shadow's OWN _price is assumed to already be in its local currency and is
// returned verbatim (early return on a non-empty value). Otherwise the master
// price (base currency) is read and converted to the current domain currency.

add_filter('woocommerce_product_get_price', 'polyglot_virtual_price', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'polyglot_virtual_regular_price', 10, 2);
add_filter('woocommerce_product_get_sale_price', 'polyglot_virtual_sale_price', 10, 2);

function polyglot_virtual_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? polyglot_maybe_convert_from_base($master->get_price()) : $value;
}

function polyglot_virtual_regular_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? polyglot_maybe_convert_from_base($master->get_regular_price()) : $value;
}

function polyglot_virtual_sale_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? polyglot_maybe_convert_from_base($master->get_sale_price()) : $value;
}

// ============================================================
// SHIPPING BRIDGE — Convert carrier (Boxtal) rate costs to the domain currency
// ============================================================
//
// Boxtal returns shipping rates in the base currency (EUR). On non-base domains
// we convert each rate's cost (and taxes) with the same per-currency FX rate +
// markup as products, so the cart total stays consistent with the displayed
// product prices. Without this, a DKK cart would show EUR shipping amounts.

add_filter('woocommerce_package_rates', 'polyglot_convert_shipping_rates', 20);

function polyglot_convert_shipping_rates(array $rates): array
{
    $currency = polyglot_get_current_currency();
    if ($currency === polyglot_fx_base_currency()) {
        return $rates;
    }

    foreach ($rates as $rate) {
        $rate->set_cost(polyglot_convert_price((float) $rate->get_cost(), $currency));

        $taxes = $rate->get_taxes();
        if (is_array($taxes)) {
            foreach ($taxes as $key => $tax) {
                if (is_numeric($tax)) {
                    $taxes[$key] = polyglot_convert_price((float) $tax, $currency);
                }
            }
            $rate->set_taxes($taxes);
        }
    }

    return $rates;
}

// ============================================================
// IMAGE BRIDGE — Shadow inherits images from Master
// ============================================================

add_filter('post_thumbnail_id', 'polyglot_virtual_thumbnail', 10, 2);

function polyglot_virtual_thumbnail($thumbnail_id, $post)
{
    if ($thumbnail_id) {
        return $thumbnail_id;
    }

    $post_id = $post instanceof WP_Post ? $post->ID : (int) $post;
    $master_id = get_post_meta($post_id, '_master_id', true);
    if (! $master_id) {
        return $thumbnail_id;
    }

    return get_post_thumbnail_id($master_id) ?: $thumbnail_id;
}

add_filter('woocommerce_product_get_image_id', 'polyglot_virtual_product_image_id', 10, 2);

function polyglot_virtual_product_image_id($value, $product)
{
    if ($value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? $master->get_image_id() : $value;
}

add_filter('woocommerce_product_get_gallery_image_ids', 'polyglot_virtual_gallery_image_ids', 10, 2);

function polyglot_virtual_gallery_image_ids($value, $product)
{
    if (! empty($value)) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? $master->get_gallery_image_ids() : $value;
}

// ============================================================
// RELATED PRODUCTS BRIDGE — Shadow resolves related from Master
// ============================================================

add_filter('woocommerce_related_products', 'polyglot_virtual_related_products', 10, 3);

function polyglot_virtual_related_products(array $related_ids, int $product_id, array $args): array
{
    if (polyglot_is_master()) {
        return $related_ids;
    }

    $locale = polyglot_get_current_locale();
    $master_id = (int) get_post_meta($product_id, '_master_id', true);

    if (! $master_id) {
        return $related_ids;
    }

    // Get related products for the MASTER product
    // Temporarily unhook to avoid recursion
    remove_filter('woocommerce_related_products', 'polyglot_virtual_related_products', 10);
    $master_related = wc_get_related_products($master_id, $args['limit'] ?? 4);
    add_filter('woocommerce_related_products', 'polyglot_virtual_related_products', 10, 3);

    // Map each master related ID to its shadow for current locale (single batch query)
    $shadow_map = polyglot_find_shadow_ids(array_map('intval', $master_related), $locale);
    $shadow_ids = array_values($shadow_map);

    return $shadow_ids ?: $related_ids;
}

<?php

// ============================================================
// INVENTORY BRIDGE — Shadow reads stock from Master
// ============================================================

add_filter('woocommerce_product_get_stock_quantity', 'polyglot_virtual_stock', 10, 2);
add_filter('woocommerce_product_get_stock_status', 'polyglot_virtual_stock_status', 10, 2);
add_filter('woocommerce_product_is_in_stock', 'polyglot_virtual_in_stock', 10, 2);

function polyglot_get_master($product)
{
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
// PRICE BRIDGE — Shadow reads prices from Master
// ============================================================

add_filter('woocommerce_product_get_price', 'polyglot_virtual_price', 10, 2);
add_filter('woocommerce_product_get_regular_price', 'polyglot_virtual_regular_price', 10, 2);
add_filter('woocommerce_product_get_sale_price', 'polyglot_virtual_sale_price', 10, 2);

function polyglot_virtual_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? $master->get_price() : $value;
}

function polyglot_virtual_regular_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? $master->get_regular_price() : $value;
}

function polyglot_virtual_sale_price($value, $product)
{
    if ('' !== $value) {
        return $value;
    }

    $master = polyglot_get_master($product);

    return $master ? $master->get_sale_price() : $value;
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

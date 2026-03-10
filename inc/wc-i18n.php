<?php

// ============================================================
// WOOCOMMERCE FRONTEND TRANSLATIONS — Shipping, checkout, cart
// ============================================================

// --- Shipping rate labels ---
// Master-language shipping methods are configured with master titles in WC settings.
// Translate them on shadow domains via the package rates filter.
// Provide translations via the 'polyglot_shipping_labels' filter:
//   add_filter('polyglot_shipping_labels', function () {
//       return [
//           'en' => ['Livraison standard' => 'Standard delivery'],
//           'es' => ['Livraison standard' => 'Envío estándar'],
//       ];
//   });
add_filter('woocommerce_package_rates', 'polyglot_translate_shipping_labels', 20);

function polyglot_translate_shipping_labels($rates)
{
    if (polyglot_is_master()) {
        return $rates;
    }

    $hreflang = polyglot_get_current_entry()['hreflang'] ?? '';
    $translations = apply_filters('polyglot_shipping_labels', []);
    $map = $translations[$hreflang] ?? [];
    if (empty($map)) {
        return $rates;
    }

    foreach ($rates as &$rate) {
        if (isset($map[$rate->label])) {
            $rate->label = $map[$rate->label];
        }
    }

    return $rates;
}

// --- Checkout option strings (privacy policy + terms checkbox) ---
// These are stored as master-language option values in the DB. Replace with
// WooCommerce's own gettext default (which will be translated by the locale).
add_filter('option_woocommerce_checkout_privacy_policy_text', 'polyglot_translate_checkout_privacy');
add_filter('option_woocommerce_checkout_terms_and_conditions_checkbox_text', 'polyglot_translate_checkout_terms');

function polyglot_translate_checkout_privacy($value)
{
    if (is_admin() || polyglot_is_master()) {
        return $value;
    }

    return sprintf(__('Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our %s.', 'woocommerce'), '[privacy_policy]');
}

function polyglot_translate_checkout_terms($value)
{
    if (is_admin() || polyglot_is_master()) {
        return $value;
    }

    return sprintf(__('I have read and agree to the website %s', 'woocommerce'), '[terms]');
}

// --- Cart item names: show shadow product title for master products ---
// When a master product (FR) is in the cart and we're on a shadow domain,
// display the shadow product's translated title instead.
add_filter('woocommerce_cart_item_name', 'polyglot_translate_cart_item_name', 10, 3);

function polyglot_translate_cart_item_name($name, $cart_item, $cart_item_key)
{
    if (polyglot_is_master()) {
        return $name;
    }

    $product_id = $cart_item['product_id'];
    $master_id = get_post_meta($product_id, '_master_id', true);

    // Product is already a shadow — check if it's our locale
    if ($master_id) {
        $product_locale = get_post_meta($product_id, '_locale', true);
        if ($product_locale === polyglot_get_current_locale()) {
            return $name; // correct locale already
        }
        // Wrong locale shadow — find the right one
        $real_master_id = (int) $master_id;
    } else {
        // Product is a master — find shadow for current locale
        $real_master_id = $product_id;
    }

    // Look up shadow product for current locale
    $shadow_id = polyglot_find_shadow_id($real_master_id, polyglot_get_current_locale());

    if ($shadow_id) {
        $shadow_title = get_the_title($shadow_id);
        if ($shadow_title) {
            // Preserve the link wrapper if present
            if (preg_match('/<a\b[^>]*>/', $name, $m)) {
                return $m[0].esc_html($shadow_title).'</a>';
            }

            return esc_html($shadow_title);
        }
    }

    return $name;
}

// ============================================================
// USER REGISTRATION — Tag with registration locale
// ============================================================

add_action('user_register', 'polyglot_tag_registration_locale');

function polyglot_tag_registration_locale($user_id)
{
    update_user_meta($user_id, '_registration_locale', polyglot_get_current_locale());
}

// ============================================================
// WOOCOMMERCE — Save order locale at checkout
// ============================================================

add_action('woocommerce_checkout_order_processed', 'polyglot_save_order_locale', 10, 1);

function polyglot_save_order_locale($order_id)
{
    $locale = polyglot_get_current_locale();
    update_post_meta($order_id, '_order_locale', $locale);

    // HPOS compatibility
    if (function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_order_locale', $locale);
            $order->save();
        }
    }
}

// ============================================================
// WOOCOMMERCE — Email i18n (switch locale for order emails)
// ============================================================

$polyglot_email_locale_active = false;

$polyglot_wc_email_hooks = [
    'woocommerce_order_status_pending_to_processing_notification',
    'woocommerce_order_status_pending_to_completed_notification',
    'woocommerce_order_status_pending_to_on-hold_notification',
    'woocommerce_order_status_failed_to_processing_notification',
    'woocommerce_order_status_on-hold_to_processing_notification',
    'woocommerce_order_status_on-hold_to_completed_notification',
    'woocommerce_order_status_completed_notification',
    'woocommerce_order_status_refunded_notification',
];

foreach ($polyglot_wc_email_hooks as $_hook) {
    add_action($_hook, 'polyglot_email_switch_locale', 5, 1);
    add_action($_hook, 'polyglot_email_restore_locale', 999, 1);
}

add_action('woocommerce_before_resend_order_emails', 'polyglot_email_switch_locale_for_resend', 5, 1);
add_action('woocommerce_after_resend_order_email', 'polyglot_email_restore_locale_after_resend', 999, 2);

function polyglot_email_switch_locale($order_id)
{
    global $polyglot_email_locale_active;

    $order_locale = get_post_meta($order_id, '_order_locale', true);
    if (! $order_locale) {
        // Try HPOS
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order_locale = $order->get_meta('_order_locale');
            }
        }
    }

    if (! $order_locale) {
        return;
    }

    $polyglot_email_locale_active = true;
    switch_to_locale($order_locale);
}

function polyglot_email_restore_locale($order_id)
{
    global $polyglot_email_locale_active;

    if (! $polyglot_email_locale_active) {
        return;
    }

    restore_previous_locale();
    $polyglot_email_locale_active = false;
}

function polyglot_email_switch_locale_for_resend($order)
{
    $order_id = is_object($order) ? $order->get_id() : $order;
    polyglot_email_switch_locale($order_id);
}

function polyglot_email_restore_locale_after_resend($order, $email_type)
{
    $order_id = is_object($order) ? $order->get_id() : $order;
    polyglot_email_restore_locale($order_id);
}

// Prevent WC from overriding our locale switch
add_filter('woocommerce_email_setup_locale', 'polyglot_block_wc_locale_switch');
add_filter('woocommerce_email_restore_locale', 'polyglot_block_wc_locale_switch');

function polyglot_block_wc_locale_switch($do_switch)
{
    global $polyglot_email_locale_active;

    return $polyglot_email_locale_active ? false : $do_switch;
}

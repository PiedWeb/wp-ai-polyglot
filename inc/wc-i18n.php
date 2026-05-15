<?php

if (! defined('ABSPATH')) {
    exit;
}

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

    /* translators: %s: placeholder replaced by [privacy_policy] shortcode link. */
    return sprintf(__('Your personal data will be used to process your order, support your experience throughout this website, and for other purposes described in our %s.', 'woocommerce'), '[privacy_policy]'); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentionally reusing WooCommerce's translation.
}

function polyglot_translate_checkout_terms($value)
{
    if (is_admin() || polyglot_is_master()) {
        return $value;
    }

    /* translators: %s: placeholder replaced by [terms] shortcode link. */
    return sprintf(__('I have read and agree to the website %s', 'woocommerce'), '[terms]'); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- intentionally reusing WooCommerce's translation.
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

/**
 * Track whether an email locale switch is active (replaces a global flag).
 * Call with a bool to set, without arguments to read.
 */
function polyglot_email_locale_active(?bool $set = null): bool
{
    static $active = false;
    if (null !== $set) {
        $active = $set;
    }

    return $active;
}

$_polyglot_wc_email_hooks = [
    'woocommerce_order_status_pending_to_processing_notification',
    'woocommerce_order_status_pending_to_completed_notification',
    'woocommerce_order_status_pending_to_on-hold_notification',
    'woocommerce_order_status_failed_to_processing_notification',
    'woocommerce_order_status_on-hold_to_processing_notification',
    'woocommerce_order_status_on-hold_to_completed_notification',
    'woocommerce_order_status_completed_notification',
    'woocommerce_order_status_refunded_notification',
];

foreach ($_polyglot_wc_email_hooks as $_hook) {
    add_action($_hook, 'polyglot_email_switch_locale', 5, 1);
    add_action($_hook, 'polyglot_email_restore_locale', 999, 1);
}
unset($_polyglot_wc_email_hooks, $_hook);

// Resend hooks: same functions — polyglot_email_switch_locale accepts int|object
add_action('woocommerce_before_resend_order_emails', 'polyglot_email_switch_locale', 5, 1);
add_action('woocommerce_after_resend_order_email', 'polyglot_email_restore_locale', 999, 1);

function polyglot_email_switch_locale($order_id)
{
    if (is_object($order_id)) {
        $order_id = $order_id->get_id();
    }

    $order_locale = get_post_meta($order_id, '_order_locale', true);
    if (! $order_locale && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order_locale = $order->get_meta('_order_locale');
        }
    }

    if (! $order_locale) {
        return;
    }

    polyglot_email_locale_active(true);
    switch_to_locale($order_locale);
}

function polyglot_email_restore_locale($order_id = null)
{
    if (! polyglot_email_locale_active()) {
        return;
    }

    restore_previous_locale();
    polyglot_email_locale_active(false);
}

// Prevent WC from overriding our locale switch
add_filter('woocommerce_email_setup_locale', 'polyglot_block_wc_locale_switch');
add_filter('woocommerce_email_restore_locale', 'polyglot_block_wc_locale_switch');

function polyglot_block_wc_locale_switch($do_switch)
{
    return polyglot_email_locale_active() ? false : $do_switch;
}

// ============================================================
// WOOCOMMERCE — Email additional_content & footer translations
// ============================================================

/**
 * Resolve the locale for the current email context.
 * Uses the switched locale (for order emails) or the domain locale (for account emails).
 */
function polyglot_get_email_locale(): string
{
    return polyglot_email_locale_active() ? get_locale() : polyglot_get_current_locale();
}

// --- Email additional_content translations ---

$_polyglot_email_content_types = [
    'customer_processing_order',
    'customer_completed_order',
    'customer_on_hold_order',
    'customer_refunded_order',
    'customer_invoice',
    'customer_note',
    'customer_new_account',
    'customer_reset_password',
];

foreach ($_polyglot_email_content_types as $_type) {
    add_filter("woocommerce_email_additional_content_{$_type}", 'polyglot_translate_email_additional_content', 10, 3);
}
unset($_polyglot_email_content_types, $_type);

function polyglot_translate_email_additional_content($content, $object, $email)
{
    $locale = polyglot_get_email_locale();
    if ($locale === polyglot_get_master_locale()) {
        return $content;
    }

    static $map = null;
    if ($map === null) {
        $map = polyglot_email_content_translations();
    }

    $email_id = $email->id;
    if (! isset($map[$email_id][$locale])) {
        return $content;
    }

    $text = $map[$email_id][$locale];

    // Replace {site_url} placeholder with the shadow domain hostname
    if (str_contains($text, '{site_url}')) {
        $authority = polyglot_locale_to_authority($locale);
        $text = str_replace('{site_url}', $authority ?: wp_parse_url(home_url(), PHP_URL_HOST), $text);
    }

    return $text;
}

function polyglot_email_content_translations(): array
{
    $use_site_url = [
        'en_IE' => 'Thanks for shopping with {site_url}!',
        'es_ES' => '¡Gracias por comprar en {site_url}!',
        'it_IT' => 'Grazie per aver acquistato su {site_url}!',
        'de_DE' => 'Vielen Dank für Ihren Einkauf bei {site_url}!',
        'pt_PT' => 'Obrigado por comprar em {site_url}!',
        'da_DK' => 'Tak fordi du handler hos {site_url}!',
        'pl_PL' => 'Dziękujemy za zakupy w {site_url}!',
    ];

    $thanks_purchase = [
        'en_IE' => 'Thanks for your purchase.',
        'es_ES' => 'Gracias por tu compra.',
        'it_IT' => 'Grazie per il tuo acquisto.',
        'de_DE' => 'Vielen Dank für Ihren Einkauf.',
        'pt_PT' => 'Obrigado pela sua compra.',
        'da_DK' => 'Tak for dit køb.',
        'pl_PL' => 'Dziękujemy za zakup.',
    ];

    $see_you_soon = [
        'en_IE' => 'We look forward to seeing you again in our shop.',
        'es_ES' => 'Esperamos verte de nuevo pronto en nuestra tienda.',
        'it_IT' => 'Speriamo di rivederti presto nel nostro negozio.',
        'de_DE' => 'Wir freuen uns auf Ihren nächsten Besuch in unserem Shop.',
        'pt_PT' => 'Esperamos vê-lo novamente em breve na nossa loja.',
        'da_DK' => 'Vi glæder os til at se dig igen i vores butik.',
        'pl_PL' => 'Mamy nadzieję, że wkrótce odwiedzisz nasz sklep ponownie.',
    ];

    $thank_attention = [
        'en_IE' => 'Thank you for your attention.',
        'es_ES' => 'Gracias por tu atención.',
        'it_IT' => 'Grazie per la tua attenzione.',
        'de_DE' => 'Vielen Dank für Ihre Aufmerksamkeit.',
        'pt_PT' => 'Obrigado pela sua atenção.',
        'da_DK' => 'Tak for din opmærksomhed.',
        'pl_PL' => 'Dziękujemy za uwagę.',
    ];

    $process_shortly = [
        'en_IE' => 'We expect to process your order shortly.',
        'es_ES' => 'Esperamos procesar tu pedido en breve.',
        'it_IT' => 'Prevediamo di elaborare il tuo ordine a breve.',
        'de_DE' => 'Wir werden Ihre Bestellung in Kürze bearbeiten.',
        'pt_PT' => 'Esperamos processar a sua encomenda em breve.',
        'da_DK' => 'Vi forventer at behandle din ordre snart.',
        'pl_PL' => 'Oczekujemy, że wkrótce zrealizujemy Twoje zamówienie.',
    ];

    return [
        'customer_processing_order' => $use_site_url,
        'customer_completed_order' => $thanks_purchase,
        'customer_on_hold_order' => $process_shortly,
        'customer_refunded_order' => $see_you_soon,
        'customer_invoice' => $use_site_url,
        'customer_note' => $thank_attention,
        'customer_new_account' => $see_you_soon,
        'customer_reset_password' => $thank_attention,
    ];
}

// --- Email footer: replace domain link for shadow locales ---

add_filter('woocommerce_email_footer_text', 'polyglot_translate_email_footer', 10, 2);

function polyglot_translate_email_footer($text, $email = null)
{
    $locale = polyglot_get_email_locale();
    if ($locale === polyglot_get_master_locale()) {
        return $text;
    }

    $authority = polyglot_locale_to_authority($locale);
    if (! $authority) {
        return $text;
    }

    $shadow_url = polyglot_authority_to_url($authority);
    $shadow_host = wp_parse_url($shadow_url, PHP_URL_HOST) ?: $authority;

    // Replace all hrefs pointing to master domain
    $master_authority = polyglot_get_master_authority();
    $master_url = polyglot_authority_to_url($master_authority);
    $text = str_replace($master_url, $shadow_url, $text);

    // Replace display hostname (handles both "woodrock.fr" and "www.woodrock.fr" patterns)
    $master_host = wp_parse_url($master_url, PHP_URL_HOST) ?: $master_authority;
    if ($master_host !== $shadow_host) {
        $text = str_replace($master_host, $shadow_host, $text);
    }

    return $text;
}

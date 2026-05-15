<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// COOKIE BANNER — Translate Cookie Law Info on shadow domains
// ============================================================

function polyglot_cookie_translations(): array
{
    static $cache = null;

    if (null !== $cache) {
        return $cache;
    }

    $hreflang = polyglot_get_current_entry()['hreflang'] ?? '';
    $translations = apply_filters('polyglot_cookie_translations', []);

    return $cache = $translations[$hreflang] ?? [];
}

// Filter cookie bar HTML
add_filter('cli_show_cookie_bar_only_on_selected_pages', 'polyglot_translate_cookie_bar_html');

function polyglot_translate_cookie_bar_html(string $html): string
{
    if (is_admin() || polyglot_is_master()) {
        return $html;
    }

    $map = polyglot_cookie_translations();
    $entry = polyglot_get_current_entry();
    if (empty($map)) {
        return $html;
    }

    return str_replace(array_keys($map), array_values($map), $html);
}

// Translate cookie settings modal via output buffering
add_action('wp_footer', 'polyglot_cookie_modal_ob_start', 0);
add_action('wp_footer', 'polyglot_cookie_modal_ob_end', \PHP_INT_MAX);

function polyglot_cookie_modal_ob_start(): void
{
    if (is_admin() || polyglot_is_master()) {
        return;
    }

    if (empty(polyglot_cookie_translations())) {
        return;
    }

    ob_start();
}

function polyglot_cookie_modal_ob_end(): void
{
    if (is_admin() || polyglot_is_master()) {
        return;
    }

    $map = polyglot_cookie_translations();
    if (empty($map)) {
        return;
    }

    $html = ob_get_clean();
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is WP's already-escaped output buffer.
    echo str_replace(array_keys($map), array_values($map), $html);
}

// ============================================================

// LANGUAGE SUGGESTION BANNER — Browser-locale-based suggestion
// ============================================================
//
// Shows a fixed top banner when the visitor's browser language
// differs from the current page locale. Dismissed state is
// persisted in localStorage. Disable with:
//   define('POLYGLOT_BAR', false);  // in wp-config.php
//

add_action('wp_enqueue_scripts', 'polyglot_enqueue_bar_assets');
add_action('wp_body_open', 'polyglot_language_switcher_bar');

function polyglot_bar_is_enabled(): bool
{
    if (is_admin()) {
        return false;
    }
    if (defined('POLYGLOT_BAR') && ! POLYGLOT_BAR) {
        return false;
    }

    return (bool) polyglot_get_current_entry();
}

function polyglot_enqueue_bar_assets(): void
{
    if (! polyglot_bar_is_enabled()) {
        return;
    }

    wp_enqueue_style(
        'polyglot-bar',
        plugins_url('assets/polyglot-bar.css', POLYGLOT_PLUGIN_FILE),
        [],
        POLYGLOT_VERSION
    );

    wp_enqueue_script(
        'polyglot-bar',
        plugins_url('assets/polyglot-bar.js', POLYGLOT_PLUGIN_FILE),
        [],
        POLYGLOT_VERSION,
        true
    );
}

/**
 * Translated strings for the suggestion banner, keyed by hreflang.
 */
function polyglot_bar_i18n(): array
{
    return [
        'fr' => ['message' => 'Cette page est disponible en français', 'cta' => 'Voir en français'],
        'en' => ['message' => 'This page is available in English', 'cta' => 'View in English'],
        'es' => ['message' => 'Esta página está disponible en español', 'cta' => 'Ver en español'],
        'it' => ['message' => 'Questa pagina è disponibile in italiano', 'cta' => 'Vedi in italiano'],
        'de' => ['message' => 'Diese Seite ist auf Deutsch verfügbar', 'cta' => 'Auf Deutsch ansehen'],
        'pt' => ['message' => 'Esta página está disponível em português', 'cta' => 'Ver em português'],
        'da' => ['message' => 'Denne side er tilgængelig på dansk', 'cta' => 'Se på dansk'],
        'pl' => ['message' => 'Ta strona jest dostępna po polsku', 'cta' => 'Zobacz po polsku'],
    ];
}

function polyglot_language_switcher_bar(): void
{
    if (! polyglot_bar_is_enabled()) {
        return;
    }

    $current_authority = polyglot_get_current_authority();
    $current_hreflang = polyglot_get_current_entry()['hreflang'];
    $i18n = polyglot_bar_i18n();
    $paths = polyglot_get_locale_paths();

    $locales = [];
    foreach (POLYGLOT_LOCALES as $authority => $cfg) {
        if ($authority === $current_authority) {
            continue;
        }

        $path = $paths[$authority] ?? null;
        if (! $path) {
            continue;
        }

        $url = polyglot_authority_to_url($authority).$path;

        if (function_exists('WC') && WC()->session) {
            $session_key = WC()->session->get_customer_id();
            $expires = time() + 60;
            $token = $session_key.'|'.$expires;
            $sig = hash_hmac('sha256', $token, wp_salt('auth'));
            $url = add_query_arg('wc_session', urlencode($token.'|'.$sig), $url);
        }

        $hl = $cfg['hreflang'];
        $strings = $i18n[$hl] ?? ['message' => $cfg['label'], 'cta' => $cfg['label']];

        $locales[$hl] = [
            'url' => $url,
            'message' => $strings['message'],
            'cta' => $strings['cta'],
        ];
    }

    $locales_json = wp_json_encode($locales);

    include POLYGLOT_PLUGIN_DIR.'templates/suggestion-banner.php';
}

/**
 * @deprecated use polyglot_language_switcher_bar() instead
 */
function polyglot_language_switcher(): void
{
    polyglot_language_switcher_bar();
}

// ============================================================
// FOOTER LANGUAGE SWITCHER — Visual dropdown in site footer
// ============================================================

add_action('wp_footer', 'polyglot_footer_switcher_bar', 50);

function polyglot_footer_switcher_bar(): void
{
    if (is_admin()) {
        return;
    }
    if (defined('POLYGLOT_FOOTER') && ! POLYGLOT_FOOTER) {
        return;
    }
    if (! get_option('polyglot_footer_switcher', true)) {
        return;
    }
    if (count(POLYGLOT_LOCALES) < 2) {
        return;
    }

    $current_authority = polyglot_get_current_authority();
    $current_entry = polyglot_get_current_entry();
    if (! $current_entry) {
        return;
    }

    $active_country = polyglot_locale_to_country($current_entry['locale']);
    $active_label = $current_entry['label'];
    $active_flag_url = plugins_url('assets/flags/'.$active_country.'.svg', POLYGLOT_PLUGIN_FILE);

    $paths = polyglot_get_locale_paths();

    $items = [];
    foreach (POLYGLOT_LOCALES as $authority => $cfg) {
        $country = polyglot_locale_to_country($cfg['locale']);
        $items[] = [
            'label' => $cfg['label'],
            'hreflang' => $cfg['hreflang'],
            'flag_url' => plugins_url('assets/flags/'.$country.'.svg', POLYGLOT_PLUGIN_FILE),
            'url' => polyglot_authority_to_url($authority).($paths[$authority] ?? '/'),
            'active' => ($authority === $current_authority),
        ];
    }

    require POLYGLOT_PLUGIN_DIR.'templates/footer-switcher.php';
}

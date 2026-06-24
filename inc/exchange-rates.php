<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// EXCHANGE RATES — ECB daily reference rates for FX price conversion
// ============================================================
//
// Master prices are stored in the base (master) currency (EUR). Shadow domains
// whose currency differs (DKK, PLN) display, feed and charge prices converted
// from the base currency via ECB daily reference rates (pure rates are stored
// in the option 'polyglot_exchange_rates', refreshed daily via WP-Cron or
// `wp polyglot update-exchange-rates').
//
// On top of the raw rate, a per-currency MARKUP is applied at conversion time
// to cover FX / payment-conversion costs (e.g. Stripe settles DKK→EUR with a
// spread). The markup is invisible — it is baked into every converted amount
// (products AND shipping), so the storefront, the cart total and the Google
// feed stay consistent. Configure via POLYGLOT_FX_MARKUP (see below).
//
// A shadow product that carries its OWN _price meta is assumed to already be in
// its local currency and is never converted (see inc/wc-product-bridge.php).
//

const POLYGLOT_FX_OPTION = 'polyglot_exchange_rates';

const POLYGLOT_FX_ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

// Per-currency markup added on top of the raw ECB rate to cover conversion
// costs. A scalar applies to every non-base currency; an array keys it by
// currency code. Positive = foreign prices go UP. Default 0 (pure ECB rate).
// Override in wp-config.php, e.g.:
//   define('POLYGLOT_FX_MARKUP', 0.05);                       // +5% everywhere
//   define('POLYGLOT_FX_MARKUP', ['DKK' => 0.05, 'PLN' => 0.03]);
if (! defined('POLYGLOT_FX_MARKUP')) {
    define('POLYGLOT_FX_MARKUP', 0.0);
}

/**
 * Base currency = the master locale's currency (the currency master prices are stored in).
 */
function polyglot_fx_base_currency(): string
{
    $authority = polyglot_get_master_authority();

    return POLYGLOT_LOCALES[$authority]['currency'] ?? 'EUR';
}

/**
 * Currency of the current domain (falls back to the base currency).
 */
function polyglot_get_current_currency(): string
{
    $entry = polyglot_get_current_entry();

    return $entry['currency'] ?? polyglot_fx_base_currency();
}

/**
 * Distinct non-base currencies used by any configured locale (e.g. ['DKK', 'PLN']).
 *
 * @return string[]
 */
function polyglot_fx_target_currencies(): array
{
    $base = polyglot_fx_base_currency();
    $set = [];
    foreach (POLYGLOT_LOCALES as $cfg) {
        $currency = $cfg['currency'] ?? '';
        if ('' !== $currency && $currency !== $base) {
            $set[$currency] = true;
        }
    }

    return array_keys($set);
}

/**
 * Per-currency markup (fraction, e.g. 0.05 = +5%) applied on top of the raw rate.
 * Reads POLYGLOT_FX_MARKUP (scalar or per-currency array), filterable.
 */
function polyglot_fx_markup(string $currency): float
{
    $configured = defined('POLYGLOT_FX_MARKUP') ? POLYGLOT_FX_MARKUP : 0.0;
    $value = is_array($configured) ? ($configured[$currency] ?? 0.0) : $configured;

    return (float) apply_filters('polyglot_fx_markup', (float) $value, $currency);
}

/**
 * Raw (pure ECB) conversion rate base→$currency. Returns 1.0 for the base
 * currency or when no rate is available (no conversion rather than a wrong number).
 */
function polyglot_get_exchange_rate(string $currency): float
{
    if ($currency === polyglot_fx_base_currency()) {
        return 1.0;
    }

    $data = get_option(POLYGLOT_FX_OPTION, []);
    $rate = is_array($data) ? ($data['rates'][$currency] ?? null) : null;

    return is_numeric($rate) ? (float) $rate : 1.0;
}

/**
 * Convert an amount expressed in the base currency to $currency: raw ECB rate
 * times the per-currency markup, then rounded. No-op (just rounded) for the base
 * currency. Rounding is filterable (`polyglot_fx_round`) so storefront, cart and
 * feed round identically — Merchant Center requires feed price == landing-page price.
 */
function polyglot_convert_price(float $amount, string $currency): float
{
    if ($currency === polyglot_fx_base_currency()) {
        return round($amount, 2);
    }

    $rate = polyglot_get_exchange_rate($currency) * (1 + polyglot_fx_markup($currency));
    $converted = $amount * $rate;

    return (float) apply_filters('polyglot_fx_round', round($converted, 2), $converted, $currency);
}

/**
 * Fetch ECB daily reference rates and store the pure rates in the option.
 *
 * @return array<string, mixed>|WP_Error
 */
function polyglot_update_exchange_rates()
{
    $currencies = polyglot_fx_target_currencies();
    if (empty($currencies)) {
        // Nothing to convert — store an empty (but valid) snapshot.
        $data = polyglot_fx_snapshot([], date('Y-m-d'));
        update_option(POLYGLOT_FX_OPTION, $data, false);

        return $data;
    }

    $response = wp_remote_get(POLYGLOT_FX_ECB_URL, ['timeout' => 15]);
    if (is_wp_error($response)) {
        return $response;
    }

    $code = wp_remote_retrieve_response_code($response);
    if (200 !== (int) $code) {
        return new WP_Error('polyglot_fx_http', sprintf('ECB request failed (HTTP %s)', $code));
    }

    $xml = wp_remote_retrieve_body($response);
    if ('' === $xml) {
        return new WP_Error('polyglot_fx_empty', 'Empty ECB response');
    }

    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $loaded = $doc->loadXML($xml);
    libxml_use_internal_errors($previous);
    if (! $loaded) {
        return new WP_Error('polyglot_fx_parse', 'Failed to parse ECB XML');
    }

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');

    $rates = [];
    foreach ($currencies as $currency) {
        $nodes = $xpath->query(sprintf("//ecb:Cube[@currency='%s']/@rate", $currency));
        if (false === $nodes || 0 === $nodes->length || null === $nodes->item(0)) {
            return new WP_Error('polyglot_fx_missing', sprintf('%s rate not found in ECB data', $currency));
        }

        $rates[$currency] = round((float) $nodes->item(0)->nodeValue, 6);
    }

    $date_nodes = $xpath->query('//ecb:Cube[@time]/@time');
    $date = (false !== $date_nodes && $date_nodes->length > 0 && null !== $date_nodes->item(0))
        ? (string) $date_nodes->item(0)->nodeValue
        : date('Y-m-d');

    $data = polyglot_fx_snapshot($rates, $date);
    update_option(POLYGLOT_FX_OPTION, $data, false);

    // Prices changed → drop cached feeds.
    if (function_exists('polyglot_feed_flush_cache')) {
        polyglot_feed_flush_cache();
    }

    return $data;
}

/**
 * Build the option payload (base rate is always 1.0).
 *
 * @param array<string, float> $rates pure ECB rates keyed by currency
 */
function polyglot_fx_snapshot(array $rates, string $date): array
{
    $base = polyglot_fx_base_currency();

    return [
        'base' => $base,
        'date' => $date,
        'updated_at' => gmdate('c'),
        'rates' => array_merge([$base => 1.0], $rates),
    ];
}

// ============================================================
// CRON — Daily refresh (only when at least one non-base currency exists)
// ============================================================

add_action('polyglot_fx_refresh', 'polyglot_update_exchange_rates');

add_action('init', function (): void {
    if (empty(polyglot_fx_target_currencies())) {
        return;
    }
    if (! wp_next_scheduled('polyglot_fx_refresh')) {
        wp_schedule_event(time(), 'daily', 'polyglot_fx_refresh');
    }
});

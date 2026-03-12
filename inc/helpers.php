<?php

// ============================================================
// HELPERS
// ============================================================

function polyglot_get_current_authority(): string
{
    $authority = apply_filters('polyglot_current_authority', $_SERVER['HTTP_HOST'] ?? '');

    // CLI / cron: no HTTP_HOST → default to master authority
    if (empty($authority) || ! isset(POLYGLOT_LOCALES[$authority])) {
        if (\PHP_SAPI === 'cli' || defined('DOING_CRON')) {
            return polyglot_get_master_authority();
        }
    }

    return $authority;
}

function polyglot_get_current_entry(): ?array
{
    $authority = polyglot_get_current_authority();

    return POLYGLOT_LOCALES[$authority] ?? null;
}

function polyglot_is_master(): bool
{
    $entry = polyglot_get_current_entry();

    return $entry && ! empty($entry['master']);
}

function polyglot_get_current_locale(): string
{
    $entry = polyglot_get_current_entry();

    return $entry ? $entry['locale'] : polyglot_get_master_locale();
}

function polyglot_get_master_authority(): string
{
    foreach (POLYGLOT_LOCALES as $authority => $cfg) {
        if (! empty($cfg['master'])) {
            return $authority;
        }
    }

    return array_key_first(POLYGLOT_LOCALES);
}

function polyglot_authority_to_url(string $authority): string
{
    $scheme = (str_contains($authority, '127.0.0.1') || str_contains($authority, 'localhost')) ? 'http' : 'https';

    return $scheme.'://'.$authority;
}

function polyglot_get_shadow_locales(): array
{
    $shadows = array_filter(POLYGLOT_LOCALES, fn($cfg) => empty($cfg['master']));

    return array_column($shadows, 'locale');
}

function polyglot_locale_to_country(string $locale): string
{
    return strtolower(substr($locale, 3, 2));
}

function polyglot_locale_to_label(string $locale): string
{
    foreach (POLYGLOT_LOCALES as $cfg) {
        if ($cfg['locale'] === $locale) {
            return $cfg['label'];
        }
    }

    return $locale;
}

function polyglot_locale_to_authority(string $locale): string
{
    foreach (POLYGLOT_LOCALES as $authority => $cfg) {
        if ($cfg['locale'] === $locale) {
            return $authority;
        }
    }

    return '';
}

function polyglot_get_master_locale(): string
{
    foreach (POLYGLOT_LOCALES as $cfg) {
        if (! empty($cfg['master'])) {
            return $cfg['locale'];
        }
    }

    // Fallback: first entry is master
    return array_values(POLYGLOT_LOCALES)[0]['locale'];
}

function polyglot_get_master_label(): string
{
    return polyglot_locale_to_label(polyglot_get_master_locale());
}

/**
 * Post types managed by Polyglot (translatable).
 */
function polyglot_get_post_types(): array
{
    return apply_filters('polyglot_post_types', ['product', 'page', 'post']);
}

// ============================================================
// SHADOW LOOKUP HELPER
// ============================================================

function polyglot_find_shadow_id(int $master_id, string $locale): ?int
{
    static $cache = [];
    $key = "{$master_id}|{$locale}";

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    global $wpdb;
    $id = $wpdb->get_var($wpdb->prepare(
        "SELECT pm1.post_id FROM $wpdb->postmeta pm1
         JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
         WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d
         AND pm2.meta_value = %s LIMIT 1",
        $master_id,
        $locale
    ));

    return $cache[$key] = $id ? (int) $id : null;
}

/**
 * Batch lookup: find shadow IDs for multiple master IDs in one query.
 *
 * @param int[] $master_ids
 *
 * @return array<int, int> Map of master_id => shadow_id
 */
function polyglot_find_shadow_ids(array $master_ids, string $locale): array
{
    if (empty($master_ids)) {
        return [];
    }

    global $wpdb;
    $placeholders = implode(',', array_fill(0, count($master_ids), '%d'));
    $params = array_merge($master_ids, [$locale]);

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT pm1.meta_value AS master_id, pm1.post_id
         FROM $wpdb->postmeta pm1
         JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
         WHERE pm1.meta_key = '_master_id' AND pm1.meta_value IN ($placeholders)
         AND pm2.meta_value = %s",
        ...$params
    ));

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row->master_id] = (int) $row->post_id;
    }

    return $map;
}

// ============================================================
// LOCALE — Switch WP locale on shadow domains (gettext i18n)
// ============================================================

add_filter('locale', 'polyglot_switch_frontend_locale');

function polyglot_switch_frontend_locale(string $locale): string
{
    if (is_admin() || polyglot_is_master()) {
        return $locale;
    }

    $polyglot_locale = polyglot_get_current_locale();

    // Map locales without WP language packs to their closest variant
    $locale_map = [
        'en_IE' => 'en_GB',
    ];

    return $locale_map[$polyglot_locale] ?? $polyglot_locale;
}

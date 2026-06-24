<?php

if (! defined('ABSPATH')) {
    exit;
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Absolute path to the flat-file export/import directory.
 *
 * Defaults to a "polyglot-flat" folder inside the uploads directory so the
 * plugin never writes inside its own folder (which is wiped on upgrade) or in
 * a web-accessible location it doesn't control. Override with the
 * POLYGLOT_TRANSLATIONS_DIR constant: absolute paths are used verbatim,
 * relative paths are resolved against the uploads base directory.
 */
function polyglot_translations_dir(): string
{
    if (! defined('POLYGLOT_TRANSLATIONS_DIR')) {
        return rtrim(trailingslashit(wp_upload_dir()['basedir']).'polyglot-flat', '/\\');
    }

    $dir = POLYGLOT_TRANSLATIONS_DIR;
    $is_absolute = '' !== $dir && ('/' === $dir[0] || preg_match('#^[A-Za-z]:[\\\\/]#', $dir));

    if ($is_absolute) {
        return rtrim($dir, '/\\');
    }

    return rtrim(trailingslashit(wp_upload_dir()['basedir']).$dir, '/\\');
}

function polyglot_get_current_authority(): string
{
    $authority = apply_filters('polyglot_current_authority', sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')));

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

/**
 * Cached derived maps from POLYGLOT_LOCALES (locale→authority, master authority/locale).
 * Built once per request; avoids repeated iteration over the constant.
 */
function polyglot_locale_map(): array
{
    static $map = null;
    if (null !== $map) {
        return $map;
    }

    $map = ['locale_to_authority' => [], 'master_authority' => '', 'master_locale' => ''];
    foreach (POLYGLOT_LOCALES as $authority => $cfg) {
        $map['locale_to_authority'][$cfg['locale']] = $authority;
        if (! empty($cfg['master'])) {
            $map['master_authority'] = $authority;
            $map['master_locale'] = $cfg['locale'];
        }
    }

    // Fallback: first entry is master
    if ('' === $map['master_authority']) {
        $first_key = array_key_first(POLYGLOT_LOCALES);
        $map['master_authority'] = $first_key;
        $map['master_locale'] = POLYGLOT_LOCALES[$first_key]['locale'];
    }

    return $map;
}

function polyglot_get_master_authority(): string
{
    return polyglot_locale_map()['master_authority'];
}

function polyglot_authority_to_url(string $authority): string
{
    $scheme = (str_contains($authority, '127.0.0.1') || str_contains($authority, 'localhost')) ? 'http' : 'https';

    return $scheme.'://'.$authority;
}

function polyglot_get_shadow_locales(): array
{
    $shadows = array_filter(POLYGLOT_LOCALES, static fn ($cfg) => empty($cfg['master']));

    return array_column($shadows, 'locale');
}

function polyglot_locale_to_country(string $locale): string
{
    return strtolower(substr($locale, 3, 2));
}

function polyglot_locale_to_label(string $locale): string
{
    $authority = polyglot_locale_to_authority($locale);

    return $authority ? (POLYGLOT_LOCALES[$authority]['label'] ?? $locale) : $locale;
}

function polyglot_locale_to_authority(string $locale): string
{
    return polyglot_locale_map()['locale_to_authority'][$locale] ?? '';
}

function polyglot_get_master_locale(): string
{
    return polyglot_locale_map()['master_locale'];
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

/**
 * Post statuses whose content is mirrored to polyglot-flat/.
 *
 * Drafts are included so editing an unpublished master/shadow in wp-admin keeps
 * its flat file current (auto-export on save, see inc/autoexport.php). The
 * non-content statuses 'auto-draft', 'trash' and 'inherit' (revisions /
 * attachments) are intentionally excluded.
 */
function polyglot_exportable_statuses(): array
{
    return apply_filters('polyglot_exportable_statuses', ['publish', 'draft', 'pending', 'private', 'future']);
}

// ============================================================
// FLAT-FILE FORMAT — frontmatter + body, and the optimistic-lock etag
// ============================================================
//
// Each polyglot-flat/<type>-<id>/<locale>.html is a small frontmatter block (a
// fixed, known set of scalar keys) followed by the raw HTML body. `etag` is the
// optimistic-lock token (If-Match) for the flat-write pipeline: md5 of every
// field a flat edit can change, so any concurrent change to the post flips it.
// It MUST be computed identically at export time and write time — hence this
// single shared helper. Keep it in lockstep with the staleness hash (Phase 2),
// which deliberately excludes slug + price.

/**
 * Optimistic-lock token for a post's editable fields (title, public slug,
 * excerpt, content, price). Covers slug + price so a concurrent slug/price
 * change is detected.
 */
function polyglot_post_etag(WP_Post $post): string
{
    $slug = get_post_meta($post->ID, 'custom_permalink', true) ?: $post->post_name;
    $price = ('product' === $post->post_type) ? (string) get_post_meta($post->ID, '_price', true) : '';

    return md5(implode("\0", [
        (string) $post->post_title,
        (string) $slug,
        (string) $post->post_excerpt,
        (string) $post->post_content,
        $price,
    ]));
}

/**
 * Staleness hash for a master's *translatable* text (title, excerpt, content).
 * Deliberately EXCLUDES slug and price (decision #5: a master slug-only change
 * must not flag its shadows for retranslation). A shadow stores this value in
 * `_src_hash` at translation time; it is stale once it no longer equals the
 * master's current hash. Separate from the etag, which locks every field.
 */
function polyglot_content_hash(WP_Post $master): string
{
    return md5(implode("\0", [
        (string) $master->post_title,
        (string) $master->post_excerpt,
        (string) $master->post_content,
    ]));
}

/**
 * Optimistic-lock token for a term's editable fields (name, slug, description).
 * Terms have no body of their own; the description is carried in the flat body.
 */
function polyglot_term_etag(WP_Term $term): string
{
    return md5(implode("\0", [
        (string) $term->name,
        (string) $term->slug,
        (string) $term->description,
    ]));
}

/**
 * Build the canonical flat-file string for a term (master or shadow). The body
 * carries the term description; name/slug live in the frontmatter.
 * `$master_term_id` is null for a master term.
 */
function polyglot_term_flat_build(WP_Term $term, string $locale, ?int $master_term_id, string $taxonomy): string
{
    return polyglot_flat_serialize([
        'term_id' => $term->term_id,
        'master_term_id' => $master_term_id,
        'taxonomy' => $taxonomy,
        'locale' => $locale,
        'etag' => polyglot_term_etag($term),
        'name' => $term->name,
        'slug' => $term->slug,
    ], (string) $term->description);
}

/**
 * Whether a scalar can be written bare (unquoted). Anything with surrounding
 * whitespace, a newline, an embedded ": " or a leading YAML sigil is quoted
 * (JSON-encoded) instead, so the parse round-trips unambiguously.
 */
function polyglot_flat_is_bare_safe(string $value): bool
{
    if ('' === $value || $value !== trim($value)) {
        return false;
    }
    if (false !== strpbrk($value[0], "\"'#-[]{}>|*&!%@`")) {
        return false;
    }

    return ! preg_match('/[\r\n]/', $value) && ! str_contains($value, ': ');
}

/**
 * Serialize a flat file: frontmatter (null/empty values skipped) + body.
 *
 * @param array<string, scalar|null> $front
 */
function polyglot_flat_serialize(array $front, string $body): string
{
    $lines = ['---'];
    foreach ($front as $key => $value) {
        if (null === $value || '' === $value) {
            continue;
        }
        $value = (string) $value;
        $lines[] = $key.': '.(polyglot_flat_is_bare_safe($value)
            ? $value
            : json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
    }
    $lines[] = '---';

    return implode("\n", $lines)."\n".$body;
}

/**
 * Parse a flat file into [frontmatter, body]. A file with no leading "---"
 * block is treated as a bare body (back-compat with pre-frontmatter files).
 * The body is returned verbatim (line endings preserved); a "---" line inside
 * the body is safe because only the FIRST closing delimiter ends the block.
 *
 * @return array{0: array<string, string>, 1: string}
 */
function polyglot_flat_parse(string $raw): array
{
    if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $raw, $m)) {
        return [[], $raw];
    }

    $front = [];
    foreach (preg_split('/\r\n|\n/', $m[1]) as $line) {
        $sep = strpos($line, ':');
        if (false === $sep) {
            continue;
        }
        $key = trim(substr($line, 0, $sep));
        if ('' === $key) {
            continue;
        }
        $val = ltrim(substr($line, $sep + 1));
        if ('' !== $val && '"' === $val[0]) {
            $decoded = json_decode($val, true);
            if (is_string($decoded)) {
                $val = $decoded;
            }
        }
        $front[$key] = $val;
    }

    return [$front, $m[2]];
}

/**
 * Strip frontmatter and return just the HTML body (for import → post_content).
 */
function polyglot_flat_body(string $raw): string
{
    return polyglot_flat_parse($raw)[1];
}

/**
 * Build the canonical flat-file string for a post (master or shadow).
 * `$master_id` is null for a master (the key is then omitted from frontmatter).
 */
function polyglot_flat_build(WP_Post $post, string $locale, ?int $master_id, bool $is_product): string
{
    $front = [
        'id' => $post->ID,
        'master_id' => $master_id,
        'locale' => $locale,
        'etag' => polyglot_post_etag($post),
        'mode' => null === $master_id ? 'manual' : (get_post_meta($post->ID, '_translation_mode', true) ?: 'auto'),
        'slug' => get_post_meta($post->ID, 'custom_permalink', true) ?: $post->post_name,
        'title' => $post->post_title,
    ];
    if ($is_product) {
        $front['short_desc'] = $post->post_excerpt;
        $front['price'] = (string) get_post_meta($post->ID, '_price', true);
    }
    if (null !== $master_id) {
        $front['src_hash'] = (string) get_post_meta($post->ID, '_src_hash', true);
    }

    return polyglot_flat_serialize($front, (string) $post->post_content);
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

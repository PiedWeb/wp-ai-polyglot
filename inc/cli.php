<?php

if (! defined('ABSPATH')) {
    exit;
}

class Polyglot_CLI
{
    /**
     * Translate a post/product to a target locale.
     *
     * ## USAGE
     *
     *   wp polyglot translate 100 --target=en_IE
     *   wp polyglot translate 100 --target=en_IE --payload='{"translated_title":"Red Shoes",...}'
     *
     * ## OPTIONS
     *
     * <id>
     * : The post ID of the master.
     *
     * --target=<locale>
     * : Target locale (en_IE, es_ES, etc.).
     *
     * [--payload=<json>]
     * : JSON with translated fields. If omitted, outputs master data.
     *
     * [--force]
     * : Overwrite even if manually edited.
     *
     * [--if-match=<etag>]
     * : Optimistic lock: apply only if the shadow's current etag matches (or
     *   `new` to require it not yet exist). Mismatch is skipped, not an error.
     */
    public function translate($args, $assoc_args): void
    {
        $master_id = (int) $args[0];
        $target_locale = $assoc_args['target'];

        $this->validate_shadow_locale($target_locale);

        // --- FETCH MODE ---
        if (empty($assoc_args['payload'])) {
            $product = wc_get_product($master_id);
            if (! $product) {
                $post = get_post($master_id);
                if (! $post) {
                    WP_CLI::error("Post/Product $master_id not found.");
                }
                $data = [
                    'id' => $master_id,
                    'post_type' => $post->post_type,
                    'target_locale' => $target_locale,
                    'title' => $post->post_title,
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                ];
            } else {
                $data = [
                    'id' => $master_id,
                    'post_type' => 'product',
                    'target_locale' => $target_locale,
                    'title' => $product->get_name(),
                    'description' => $product->get_description(),
                    'short_desc' => $product->get_short_description(),
                    'price' => $product->get_price(),
                    'sku' => $product->get_sku(),
                    'categories' => wp_get_post_terms($master_id, 'product_cat', ['fields' => 'names']),
                    'attributes' => $product->get_attributes(),
                ];
            }
            WP_CLI::line(json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));

            return;
        }

        // --- INSERT / UPDATE MODE ---
        $ai_data = json_decode($assoc_args['payload'], true);
        if (! $ai_data) {
            WP_CLI::error('Invalid JSON payload.');
        }

        $existing_id = $this->find_shadow($master_id, $target_locale);

        if ($existing_id) {
            $mode = get_post_meta($existing_id, '_translation_mode', true);
            if ('manual' === $mode && empty($assoc_args['force'])) {
                WP_CLI::warning("Shadow $existing_id was manually edited. Use --force to overwrite.");

                return;
            }
        }

        // Optimistic lock (decision #9): a per-post --if-match makes the
        // break-glass payload path lost-update-safe like `write`.
        if (isset($assoc_args['if-match'])) {
            $if_match = (string) $assoc_args['if-match'];
            if ($existing_id) {
                clean_post_cache($existing_id);
                if ('new' === $if_match) {
                    WP_CLI::warning("If-Match=new but shadow $existing_id already exists ($target_locale). Skipped.");

                    return;
                }
                if ($if_match !== polyglot_post_etag(get_post($existing_id))) {
                    WP_CLI::warning("Shadow $existing_id changed since If-Match ($target_locale). Skipped.");

                    return;
                }
            } elseif ('new' !== $if_match) {
                WP_CLI::warning("If-Match references a shadow that no longer exists ($target_locale). Skipped.");

                return;
            }
        }

        $master_post = get_post($master_id);
        $post_args = [
            'ID' => $existing_id ? $existing_id : 0,
            'post_title' => $ai_data['translated_title'],
            'post_content' => $ai_data['translated_description'] ?? '',
            'post_excerpt' => $ai_data['translated_short_desc'] ?? '',
            // Mirror the master's status (draft master → draft shadow).
            'post_status' => $master_post ? $master_post->post_status : 'publish',
            'post_type' => $ai_data['post_type'] ?? 'product',
            'post_name' => sanitize_title($ai_data['translated_title']),
            'menu_order' => $master_post ? $master_post->menu_order : 0,
        ];

        $GLOBALS['polyglot_pending_locale'] = $target_locale;
        $shadow_id = wp_insert_post($post_args);
        unset($GLOBALS['polyglot_pending_locale']);

        if (is_wp_error($shadow_id)) {
            WP_CLI::error($shadow_id->get_error_message());
        }

        update_post_meta($shadow_id, '_master_id', $master_id);
        update_post_meta($shadow_id, '_locale', $target_locale);
        delete_post_meta($shadow_id, '_translation_mode');
        if ($master_post) {
            update_post_meta($shadow_id, '_src_hash', polyglot_content_hash($master_post));
        }

        if (isset($ai_data['price'])) {
            update_post_meta($shadow_id, '_price', $ai_data['price']);
            update_post_meta($shadow_id, '_regular_price', $ai_data['price']);
        }

        WP_CLI::success("Shadow $shadow_id created/updated for '$target_locale' (master: $master_id).");
    }

    /**
     * Translate a taxonomy term.
     *
     * ## USAGE
     *
     *   wp polyglot translate-term 5 --taxonomy=product_cat --target=en_IE --name="Shoes"
     */
    public function translate_term($args, $assoc_args): void
    {
        $master_term_id = (int) $args[0];
        $taxonomy = $assoc_args['taxonomy'];
        $target_locale = $assoc_args['target'];
        $target_name = $assoc_args['name'];
        $target_slug = sanitize_title($target_name);

        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT tm1.term_id FROM $wpdb->termmeta tm1
             JOIN $wpdb->termmeta tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = '_locale'
             WHERE tm1.meta_key = '_master_term_id' AND tm1.meta_value = %d
             AND tm2.meta_value = %s LIMIT 1",
            $master_term_id,
            $target_locale
        ));

        if ($existing) {
            wp_update_term($existing, $taxonomy, [
                'name' => $target_name,
                'slug' => $target_slug,
            ]);
            WP_CLI::success("Updated shadow term $existing ($target_locale).");
        } else {
            $result = wp_insert_term($target_name, $taxonomy, ['slug' => $target_slug]);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            $new_term_id = $result['term_id'];
            update_term_meta($new_term_id, '_master_term_id', $master_term_id);
            update_term_meta($new_term_id, '_locale', $target_locale);
            WP_CLI::success("Created shadow term $new_term_id for master $master_term_id ($target_locale).");
        }
    }

    /**
     * List untranslated posts for a given locale.
     *
     * ## USAGE
     *
     *   wp polyglot untranslated --type=product --target=en_IE
     *   wp polyglot untranslated --type=product              # shows all missing
     *   wp polyglot untranslated --type=page --stale         # also list shadows whose master changed
     *
     * ## OPTIONS
     *
     * [--stale]
     * : Also report existing shadows whose master's translatable content has
     *   changed since they were translated (src_hash mismatch).
     */
    public function untranslated($args, $assoc_args): void
    {
        $post_type = $assoc_args['type'] ?? 'product';
        $target_locale = $assoc_args['target'] ?? null;
        global $wpdb;

        // Get all master IDs (posts that are NOT shadows)
        $masters = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts p
             WHERE p.post_type = %s AND p.post_status = 'publish'
             AND p.ID NOT IN (
                 SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_master_id'
             )",
            $post_type
        ));

        if (empty($masters)) {
            WP_CLI::success("No master $post_type found.");

            return;
        }

        // Determine which shadow locales to check
        $shadow_locales = [];
        foreach (POLYGLOT_LOCALES as $cfg) {
            if (! empty($cfg['master'])) {
                continue;
            }
            if ($target_locale && $cfg['locale'] !== $target_locale) {
                continue;
            }
            $shadow_locales[] = $cfg['locale'];
        }

        $stale_flag = ! empty($assoc_args['stale']);
        $total_missing = 0;
        $total_stale = 0;
        foreach ($shadow_locales as $locale) {
            $missing = [];
            $stale = [];
            foreach ($masters as $id) {
                $shadow = $this->find_shadow($id, $locale);
                if (! $shadow) {
                    $missing[] = $id;

                    continue;
                }
                if ($stale_flag) {
                    $master = get_post($id);
                    if ($master && get_post_meta($shadow, '_src_hash', true) !== polyglot_content_hash($master)) {
                        $stale[] = $id;
                    }
                }
            }
            if (! empty($missing)) {
                WP_CLI::log("\n[$locale] ".count($missing)." untranslated $post_type(s):");
                foreach ($missing as $id) {
                    WP_CLI::log("  - ID $id: ".get_the_title($id));
                }
                $total_missing += count($missing);
            }
            if (! empty($stale)) {
                WP_CLI::log("\n[$locale] ".count($stale)." stale $post_type(s) (master changed since translation):");
                foreach ($stale as $id) {
                    WP_CLI::log("  - ID $id: ".get_the_title($id));
                }
                $total_stale += count($stale);
            }
        }

        if (0 === $total_missing && 0 === $total_stale) {
            WP_CLI::success($stale_flag ? 'All posts are translated and up to date!' : 'All posts are translated!');
        }
    }

    /**
     * List all configured locales.
     *
     * ## USAGE
     *
     *   wp polyglot locales
     */
    public function locales(): void
    {
        WP_CLI::log('Configured locales:');
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            $role = ! empty($cfg['master']) ? 'MASTER' : 'shadow';
            WP_CLI::log(sprintf(
                '  %-30s  %-6s  %-10s  %s  %s',
                $authority,
                $cfg['hreflang'],
                $cfg['locale'],
                $cfg['currency'],
                $role
            ));
        }
    }

    /**
     * Fetch ECB daily reference rates and store them for FX price conversion.
     *
     * ## USAGE
     *
     *   wp polyglot update-exchange-rates
     *
     * Stores base→currency margined rates in the 'polyglot_exchange_rates' option.
     * Run daily (a WP-Cron event does this automatically when a non-base currency
     * locale exists). Shadow products without their own price are converted from
     * the master (base currency) price using these rates.
     */
    public function update_exchange_rates(): void
    {
        $result = polyglot_update_exchange_rates();
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        $targets = polyglot_fx_target_currencies();
        if (empty($targets)) {
            WP_CLI::success('No non-base currency configured — nothing to convert.');

            return;
        }

        WP_CLI::log(sprintf('Base %s · ECB date %s', $result['base'], $result['date']));
        foreach ($targets as $currency) {
            $rate = (float) ($result['rates'][$currency] ?? 0);
            $markup = polyglot_fx_markup($currency);
            WP_CLI::log(sprintf(
                '  %s: %.4f  (markup %+.1f%% → effective %.4f)',
                $currency,
                $rate,
                $markup * 100,
                $rate * (1 + $markup)
            ));
        }
        WP_CLI::success('Exchange rates updated.');
    }

    /**
     * Render the Google product feed for one locale (debug helper).
     *
     * ## USAGE
     *
     *   wp polyglot feed --target=da_DK
     *
     * ## OPTIONS
     *
     * [--target=<locale>]
     * : Locale to render (default: master locale).
     *
     * Prices and currency reflect the target locale. NOTE: product links reflect
     * the CLI environment's home URL (usually the master domain) — use
     * `curl https://<domain>/polyglot-feed/google.xml` to verify per-domain URLs.
     */
    public function feed($args, $assoc_args): void
    {
        if (! function_exists('wc_get_products')) {
            WP_CLI::error('WooCommerce is not active.');
        }

        $target = $assoc_args['target'] ?? polyglot_get_master_locale();
        $authority = polyglot_locale_to_authority($target);
        if (! $authority) {
            WP_CLI::error("Unknown locale: {$target}");
        }

        $override = static fn () => $authority;
        add_filter('polyglot_current_authority', $override);
        $xml = polyglot_feed_build_xml();
        remove_filter('polyglot_current_authority', $override);

        WP_CLI::line($xml);
    }

    /**
     * Export all translations to a directory (TSV + HTML files).
     *
     * ## USAGE
     *
     *   wp polyglot export [--type=product] [--target=en_IE]
     *
     * ## OPTIONS
     *
     * [--type=<post_type>]
     * : Filter by post type (default: all translatable types).
     *
     * [--target=<locale>]
     * : Limit to one shadow locale (default: all).
     *
     * [--worker]
     * : Internal. Run as a single-flight background drain worker (used by the
     *   auto-export-on-save hook, inc/autoexport.php). Bails out silently if
     *   another import/export is already running.
     */
    public function export($args, $assoc_args): void
    {
        if (! empty($assoc_args['worker'])) {
            $this->export_worker_drain($assoc_args);

            return;
        }

        $this->acquire_sync_lock('export');

        try {
            $res = $this->run_export_core($assoc_args);
            $this->release_sync_lock();
            $purged = $res['purged'] ? ", {$res['purged']} orphan folder(s) purged" : '';
            WP_CLI::success("Exported to {$res['dir']}/ — {$res['entities']} entities{$purged}.");
        } catch (Throwable $e) {
            $this->release_sync_lock();

            throw $e;
        }

        $this->maybe_drain_pending_export();
    }

    /**
     * Run an export queued by a wp-admin save that landed while this command
     * held the sync lock. import / manual export don't drain the auto-export
     * queue themselves, so without this a pending edit would wait for the next
     * save. Local only and a no-op unless a save actually armed the flag.
     */
    private function maybe_drain_pending_export(): void
    {
        if (! function_exists('polyglot_autoexport_enabled') || ! polyglot_autoexport_enabled()) {
            return;
        }
        if (! get_transient('polyglot_export_pending')) {
            return;
        }

        WP_CLI::log('Draining auto-export queued by an edit during this command…');
        // Full export (no type/locale filter): the pending flag doesn't record
        // which post changed, so re-export everything to be safe.
        $this->export_worker_drain([]);
    }

    /**
     * Background drain worker for auto-export on save (see inc/autoexport.php).
     *
     * Single-flight: grabs the MySQL sync lock non-blocking and bails out if
     * any other import/export/worker already holds it — that one drains the
     * queue. Coalescing: a save landing mid-export re-arms the
     * 'polyglot_export_pending' flag, so one trailing export runs afterwards.
     * Net result: never two exports at once, and no save is ever lost.
     */
    private function export_worker_drain($assoc_args): void
    {
        if (! $this->try_acquire_sync_lock('export --worker')) {
            return;
        }

        try {
            do {
                delete_transient('polyglot_export_pending');
                $this->run_export_core($assoc_args);
                // Drop the warm object cache: a save that landed mid-export
                // changed the DB in another process, so a trailing pass must
                // re-read fresh rows (get_post() would otherwise return the
                // content cached during the pass above).
                wp_cache_flush();
            } while (get_transient('polyglot_export_pending'));
        } catch (Throwable $e) {
            // Background worker — no caller to report to; the lock is released below.
        } finally {
            $this->release_sync_lock();
        }
    }

    /**
     * Core export routine. Callers own the sync lock; this never touches it.
     *
     * @return array{dir: string, entities: int, rows: int, purged: int}
     */
    private function run_export_core($assoc_args): array
    {
        $dir = polyglot_translations_dir();
        $filter_type = $assoc_args['type'] ?? null;
        $filter_locale = $assoc_args['target'] ?? null;
        global $wpdb;

        // Collect shadow locales
        $shadow_locales = [];
        foreach (POLYGLOT_LOCALES as $cfg) {
            if (! empty($cfg['master'])) {
                continue;
            }
            if ($filter_locale && $cfg['locale'] !== $filter_locale) {
                continue;
            }
            $shadow_locales[] = $cfg['locale'];
        }
        if (empty($shadow_locales)) {
            throw new RuntimeException('No shadow locales matched.');
        }

        // Post types to export
        $post_types = $filter_type ? [$filter_type] : polyglot_get_post_types();

        // Ensure output dir exists
        if (! is_dir($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WP-CLI command running with admin privileges; WP_Filesystem is overkill for batch flat-file I/O.
            mkdir($dir, 0755, true);
        }

        // --- POSTS ---
        $master_locale = polyglot_get_master_locale();

        // Batch-load all shadow mappings
        $shadow_map = $this->build_shadow_map($post_types);

        // Prime caches for all shadow posts
        $all_shadow_ids = [];
        foreach ($shadow_map as $per_locale) {
            foreach ($per_locale as $sid) {
                $all_shadow_ids[] = $sid;
            }
        }
        if ($all_shadow_ids) {
            _prime_post_caches($all_shadow_ids);
        }

        $statuses = polyglot_exportable_statuses();
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // Folder basenames (re)written this run, used to purge orphans below.
        $kept_folders = [];

        // Rows for the read-only _index.md (grep by id/slug + coverage).
        $index_rows = [];

        foreach ($post_types as $post_type) {
            $masters = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts
                 WHERE post_type = %s AND post_status IN ($placeholders)
                 AND ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_master_id')
                 ORDER BY ID",
                $post_type,
                ...$statuses
            ));

            $is_product = ('product' === $post_type);

            foreach ($masters as $master_id) {
                $post = get_post($master_id);
                if (! $post) {
                    continue;
                }

                // Gather shadow data per locale (from preloaded map)
                $shadows = [];
                foreach ($shadow_locales as $locale) {
                    $sid = $shadow_map[(int) $master_id][$locale] ?? null;
                    $shadows[$locale] = $sid ? get_post($sid) : null;
                }

                // Long content + per-locale frontmatter → HTML files.
                // Folder is the stable "{type}-{id}" (slug lives in frontmatter);
                // an old slug-suffixed folder is purged below as an orphan.
                $fr_content = $post->post_content;
                $folder = "$dir/$post_type-$master_id";

                if ('' !== trim($fr_content)) {
                    if (! is_dir($folder)) {
                        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- WP-CLI command running with admin privileges; WP_Filesystem is overkill for batch flat-file I/O.
                        mkdir($folder, 0755, true);
                    }
                    file_put_contents("$folder/$master_locale.html", polyglot_flat_build($post, $master_locale, null, $is_product));

                    $master_chash = polyglot_content_hash($post);
                    $present = 0;
                    $stale = 0;
                    foreach ($shadow_locales as $locale) {
                        $s = $shadows[$locale];
                        if ($s && '' !== trim($s->post_content)) {
                            // Self-heal: a shadow with no staleness baseline (legacy
                            // content predating _src_hash, or imported without one)
                            // is assumed current as of now, so staleness only flags
                            // genuine post-baseline master changes.
                            if ('' === (string) get_post_meta($s->ID, '_src_hash', true)) {
                                update_post_meta($s->ID, '_src_hash', $master_chash);
                            }
                            file_put_contents("$folder/$locale.html", polyglot_flat_build($s, $locale, (int) $master_id, $is_product));
                            ++$present;
                            if (get_post_meta($s->ID, '_src_hash', true) !== $master_chash) {
                                ++$stale;
                            }
                        }
                    }

                    $kept_folders[basename($folder)] = true;
                    $index_rows[] = [
                        'id' => (int) $master_id,
                        'type' => $post_type,
                        'slug' => get_post_meta($master_id, 'custom_permalink', true) ?: $post->post_name,
                        'title' => $post->post_title,
                        'present' => $present,
                        'total' => count($shadow_locales),
                        'stale' => $stale,
                    ];
                }
            }
        }

        // Purge orphan flat folders. A master that was deleted, trashed,
        // emptied, or had its slug changed leaves its old folder behind: the
        // TSV is rewritten wholesale each run, but the HTML folders are not.
        // Remove any folder for an exported post type that wasn't (re)written
        // above. Scoped to $post_types so a --type=product run never touches
        // page/post folders. Folder existence is master-keyed (locale-
        // independent), so a --target run is safe here too.
        $purged = 0;
        foreach ($post_types as $post_type) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- WP-CLI command running with admin privileges; WP_Filesystem is overkill for batch flat-file I/O.
            foreach (glob("$dir/$post_type-*", \GLOB_ONLYDIR) ?: [] as $path) {
                $base = basename($path);
                // Only "{type}-{id}" or "{type}-{id}-{slug}"; never a sibling
                // post type whose name happens to share this prefix.
                if (! preg_match('#^'.preg_quote($post_type, '#').'-\d+(?:-|$)#', $base)) {
                    continue;
                }
                if (isset($kept_folders[$base])) {
                    continue;
                }
                $this->rmdir_recursive($path);
                ++$purged;
            }
        }

        // --- TERMS ---
        $term_shadow_map = $this->build_term_shadow_map();

        // Prime caches for all shadow terms
        $all_shadow_term_ids = [];
        foreach ($term_shadow_map as $per_locale) {
            foreach ($per_locale as $tid) {
                $all_shadow_term_ids[] = $tid;
            }
        }
        if ($all_shadow_term_ids) {
            _prime_term_caches($all_shadow_term_ids);
        }

        $taxonomies = ['product_cat', 'product_tag', 'category', 'post_tag'];
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => '_master_term_id', 'compare' => 'NOT EXISTS'],
                ],
            ]);
            if (is_wp_error($terms)) {
                continue;
            }

            $kept_term_folders = [];
            foreach ($terms as $term) {
                if (get_term_meta($term->term_id, '_master_term_id', true)) {
                    continue; // shadow term — written under its master's folder
                }
                $folder = "$dir/term-$taxonomy-{$term->term_id}";
                if (! is_dir($folder)) {
                    mkdir($folder, 0755, true);
                }
                file_put_contents("$folder/$master_locale.html", polyglot_term_flat_build($term, $master_locale, null, $taxonomy));

                $present = 0;
                foreach ($shadow_locales as $locale) {
                    $stid = $term_shadow_map[$term->term_id][$locale] ?? null;
                    $st = $stid ? get_term($stid, $taxonomy) : null;
                    if ($st instanceof WP_Term) {
                        file_put_contents("$folder/$locale.html", polyglot_term_flat_build($st, $locale, (int) $term->term_id, $taxonomy));
                        ++$present;
                    }
                }

                $kept_term_folders[basename($folder)] = true;
                $index_rows[] = [
                    'id' => (int) $term->term_id,
                    'type' => "term:$taxonomy",
                    'slug' => $term->slug,
                    'title' => $term->name,
                    'present' => $present,
                    'total' => count($shadow_locales),
                    'stale' => 0,
                ];
            }

            // Purge orphan term folders for this taxonomy (master deleted).
            foreach (glob("$dir/term-$taxonomy-*", \GLOB_ONLYDIR) ?: [] as $path) {
                if (! isset($kept_term_folders[basename($path)])) {
                    $this->rmdir_recursive($path);
                    ++$purged;
                }
            }
        }

        // Read-only index: grep by id/slug, see translation coverage at a glance.
        usort($index_rows, static fn ($a, $b) => [$a['type'], $a['id']] <=> [$b['type'], $b['id']]);
        $index_md = "# Polyglot flat — index (généré, lecture seule)\n\n";
        $index_md .= "| id | type | slug | titre | trad | stale |\n|---|---|---|---|---|---|\n";
        foreach ($index_rows as $r) {
            $title = str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], (string) $r['title']);
            $index_md .= sprintf("| %d | %s | %s | %s | %d/%d | %s |\n", $r['id'], $r['type'], $r['slug'], $title, $r['present'], $r['total'], $r['stale'] ? (string) $r['stale'] : '—');
        }
        file_put_contents("$dir/_index.md", $index_md);

        // Self-heal: the wide TSV is retired (Option B). Drop a stale one left
        // over from a pre-migration export so it can't be mistaken for live data.
        if (file_exists("$dir/translations.tsv")) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- WP-CLI command, batch flat-file I/O.
            unlink("$dir/translations.tsv");
        }

        return ['dir' => $dir, 'entities' => count($index_rows), 'purged' => $purged];
    }

    /**
     * Import flat files into the DB (bulk apply). Reuses the same per-file,
     * optimistic-locked write path as `write`/`push`, iterating every
     * polyglot-flat/<...>/<locale>.html — posts, products and terms alike.
     *
     * ## USAGE
     *
     *   wp polyglot import            # apply all flat files (skips conflicts/locks)
     *   wp polyglot import --force    # override the etag lock and manual shadows
     *   wp polyglot import --dry-run  # preview
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without writing.
     *
     * [--force]
     * : Override the optimistic lock and manually-edited (manual) shadows.
     */
    public function import($args, $assoc_args): void
    {
        $this->acquire_sync_lock('import');

        try {
            $dir = polyglot_translations_dir();
            $dry_run = ! empty($assoc_args['dry-run']);
            $force = ! empty($assoc_args['force']);
            $master_locale = polyglot_get_master_locale();
            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

            if (! $dry_run) {
                wp_defer_term_counting(true);
            }

            // Every flat file, masters first so a shadow reads fresh master state.
            $files = array_filter(
                glob("$dir/*/*.html") ?: [],
                static fn ($f) => ! str_contains($f, '/_new/')
            );
            usort($files, static fn ($a, $b) => (int) (basename($b, '.html') === $master_locale)
                <=> (int) (basename($a, '.html') === $master_locale));

            foreach ($files as $file) {
                $raw = (string) file_get_contents($file);
                [$front] = polyglot_flat_parse($raw);
                $locale = (string) ($front['locale'] ?? basename($file, '.html'));

                // Human lock: never bulk-overwrite a manually-edited shadow unless
                // --force (the hook/`write` path applies deliberate edits directly).
                if (! $force && $this->is_locked_shadow($front, $locale, $master_locale)) {
                    WP_CLI::log("Skipped (manual): $file");
                    ++$stats['skipped'];

                    continue;
                }

                if ($dry_run) {
                    WP_CLI::log("[DRY-RUN] Would apply: $file");
                    ++$stats['updated'];

                    continue;
                }

                try {
                    $res = $this->write_dispatch($raw, $force);
                } catch (Throwable $e) {
                    WP_CLI::warning("Error on $file: ".$e->getMessage());
                    ++$stats['errors'];

                    continue;
                }

                switch ((int) ($res['status'] ?? 500)) {
                    case 201:
                        ++$stats['created'];

                        break;
                    case 200:
                        ++$stats['updated'];

                        break;
                    case 409:
                        WP_CLI::warning("Conflict (use --force): $file");
                        ++$stats['skipped'];

                        break;
                    default:
                        WP_CLI::warning("Error on $file: ".($res['error'] ?? 'unknown'));
                        ++$stats['errors'];
                }
            }

            if (! $dry_run) {
                wp_defer_term_counting(false);
                wp_cache_flush();
            }

            $label = $dry_run ? 'Dry-run complete' : 'Import complete';
            $this->release_sync_lock();
            WP_CLI::success("$label — Created: {$stats['created']}, Updated: {$stats['updated']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}");
        } catch (Throwable $e) {
            $this->release_sync_lock();

            throw $e;
        }

        $this->maybe_drain_pending_export();
    }

    /**
     * Whether a flat file targets a manually-edited (Human Lock) shadow post.
     * Terms have no human lock; masters are never locked.
     */
    private function is_locked_shadow(array $front, string $locale, string $master_locale): bool
    {
        if ($locale === $master_locale || isset($front['taxonomy'])) {
            return false;
        }
        $master_id = (int) ($front['master_id'] ?? 0);
        if (! $master_id) {
            return false;
        }
        $sid = $this->find_shadow($master_id, $locale);

        return $sid && 'manual' === get_post_meta((int) $sid, '_translation_mode', true);
    }

    /**
     * Translate a review/comment to a target locale.
     *
     * ## USAGE
     *
     *   wp polyglot translate-comment 42 --target=en_IE
     *   wp polyglot translate-comment 42 --target=en_IE --payload='{"translated_content":"Great!"}'
     *
     * ## OPTIONS
     *
     * <id>
     * : The comment ID of the original review.
     *
     * --target=<locale>
     * : Target locale (en_IE, es_ES, etc.).
     *
     * [--payload=<json>]
     * : JSON with translated_content. If omitted, outputs review data.
     *
     * [--force]
     * : Overwrite even if manually edited.
     */
    public function translate_comment($args, $assoc_args): void
    {
        $comment_id = (int) $args[0];
        $target_locale = $assoc_args['target'];

        $this->validate_shadow_locale($target_locale);

        $comment = get_comment($comment_id);
        if (! $comment) {
            WP_CLI::error("Comment $comment_id not found.");
        }

        // --- FETCH MODE ---
        if (empty($assoc_args['payload'])) {
            $data = [
                'id' => $comment_id,
                'author' => $comment->comment_author,
                'content' => $comment->comment_content,
                'rating' => get_comment_meta($comment_id, 'rating', true),
                'source_locale' => get_comment_meta($comment_id, '_source_locale', true),
            ];
            WP_CLI::line(json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));

            return;
        }

        // --- INSERT / UPDATE MODE ---
        $ai_data = json_decode($assoc_args['payload'], true);
        if (! $ai_data) {
            WP_CLI::error('Invalid JSON payload.');
        }

        $existing_id = $this->find_shadow_comment($comment_id, $target_locale);

        if ($existing_id) {
            $mode = get_comment_meta($existing_id, '_translation_mode', true);
            if ('manual' === $mode && empty($assoc_args['force'])) {
                WP_CLI::warning("Shadow comment $existing_id was manually edited. Use --force to overwrite.");

                return;
            }
        }

        $comment_args = [
            'comment_post_ID' => (int) $comment->comment_post_ID,
            'comment_author' => $comment->comment_author,
            'comment_author_email' => $comment->comment_author_email,
            'comment_content' => $ai_data['translated_content'],
            'comment_date' => $comment->comment_date,
            'comment_date_gmt' => $comment->comment_date_gmt,
            'comment_approved' => $comment->comment_approved,
            'comment_type' => $comment->comment_type,
        ];

        if ($existing_id) {
            wp_update_comment(['comment_ID' => (int) $existing_id] + $comment_args);
            $shadow_comment_id = (int) $existing_id;
        } else {
            $shadow_comment_id = wp_insert_comment($comment_args);
        }

        if (! $shadow_comment_id) {
            WP_CLI::error('Failed to create/update shadow comment.');
        }

        update_comment_meta($shadow_comment_id, '_master_comment_id', $comment_id);
        update_comment_meta($shadow_comment_id, '_locale', $target_locale);
        delete_comment_meta($shadow_comment_id, '_translation_mode');

        $rating = get_comment_meta($comment_id, 'rating', true);
        if ($rating) {
            update_comment_meta($shadow_comment_id, 'rating', $rating);
        }

        WP_CLI::success("Shadow comment $shadow_comment_id created/updated for '$target_locale' (master comment: $comment_id).");
    }

    /**
     * Write a single flat file's state to the DB under an optimistic lock.
     *
     * Reads the edited flat file (frontmatter + body) from STDIN — the hook
     * pipes it at edit time so a concurrent export can't swap the file out from
     * under us — or, for `push`/manual use, from --file. Compares the
     * frontmatter `etag` against a fresh read of the post's editable fields:
     *   - match    → applies, returns {status:200, etag, path, content}
     *   - mismatch → no DB write, returns the current DB {status:409, …}
     *   - etag:new → create (shadow, or master via the _new/ handshake)
     *
     * Emits a single JSON object on STDOUT and nothing else; diagnostics go to
     * STDERR. The folder is master-keyed ("{type}-{master_id}"); the response
     * `path` is the canonical location the hook must write back to.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Read the flat file from this path instead of STDIN (push / manual use).
     *
     * @when after_wp_load
     */
    public function write($args, $assoc_args)
    {
        $raw = isset($assoc_args['file'])
            ? (file_exists($assoc_args['file']) ? file_get_contents($assoc_args['file']) : '')
            : (string) file_get_contents('php://stdin');

        try {
            if ('' === trim($raw)) {
                throw new RuntimeException('Empty payload (nothing on STDIN and no readable --file).');
            }
            $result = $this->write_apply($raw);
        } catch (Throwable $e) {
            // Always emit structured output so the hook / push can branch on it
            // rather than dying on a hard WP_CLI::error.
            $result = ['status' => 500, 'error' => $e->getMessage()];
        }

        WP_CLI::line(json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

        // Return for in-process callers (tests / push); WP-CLI ignores it.
        return $result;
    }

    /**
     * Reconcile flat files to the DB out-of-band — the PostToolUse hook only
     * fires on the agent's own edits, so this covers human (vim) edits, a
     * post-`git pull` sync, or a batch. Runs `write` per file under the same
     * optimistic lock: 200/201 self-converge (canonical written back), 409 is
     * reported and left untouched for manual resolution.
     *
     * ## USAGE
     *
     *   wp polyglot push            # files changed since the last push
     *   wp polyglot push --all      # every flat file
     *
     * ## OPTIONS
     *
     * [--all]
     * : Push every *.html file, not just those modified since the last push.
     */
    public function push($args, $assoc_args): void
    {
        $dir = polyglot_translations_dir();
        $all = ! empty($assoc_args['all']);
        $since = $all ? 0 : (int) get_option('polyglot_push_marker', 0);

        $files = array_merge(glob("$dir/*/*.html") ?: [], glob("$dir/_new/*/*.html") ?: []);
        $start = time();
        $stats = ['applied' => 0, 'created' => 0, 'conflict' => 0, 'skipped' => 0, 'error' => 0];

        foreach ($files as $f) {
            if (! $all && (int) filemtime($f) <= $since) {
                ++$stats['skipped'];

                continue;
            }
            $res = $this->write_apply_safe((string) file_get_contents($f));
            $status = (int) ($res['status'] ?? 500);

            if (200 === $status || 201 === $status) {
                $this->converge_flat_file($f, $res['path'] ?? $f, (string) ($res['content'] ?? ''));
                ++$stats[201 === $status ? 'created' : 'applied'];
            } elseif (409 === $status) {
                WP_CLI::warning("CONFLICT: $f changed server-side — left untouched, resolve manually.");
                ++$stats['conflict'];
            } else {
                WP_CLI::warning("Error on $f: ".($res['error'] ?? 'unknown'));
                ++$stats['error'];
            }
        }

        update_option('polyglot_push_marker', $start, false);
        WP_CLI::success(sprintf(
            'push: %d applied, %d created, %d conflict(s), %d skipped, %d error(s).',
            $stats['applied'],
            $stats['created'],
            $stats['conflict'],
            $stats['skipped'],
            $stats['error']
        ));
    }

    /** write_apply wrapped so one malformed file can't abort a `push` batch. */
    private function write_apply_safe(string $raw): array
    {
        try {
            return $this->write_apply($raw);
        } catch (Throwable $e) {
            return ['status' => 500, 'error' => $e->getMessage()];
        }
    }

    /**
     * Write a 200/201 canonical response back to the flat tree, relocating (and
     * cleaning up the old stub folder) when the canonical folder differs.
     */
    private function converge_flat_file(string $edited, string $canonical, string $content): void
    {
        if (rtrim(dirname($canonical), '/') !== rtrim(dirname($edited), '/')) {
            if (! is_dir(dirname($canonical))) {
                mkdir(dirname($canonical), 0755, true);
            }
            file_put_contents($canonical, $content);
            unlink($edited);
            $old_dir = dirname($edited);
            if (is_dir($old_dir) && [] === array_diff((array) scandir($old_dir), ['.', '..'])) {
                rmdir($old_dir);
            }
        } else {
            file_put_contents($canonical, $content);
        }
    }

    /**
     * Core of `write`: parse, lock, compare-and-swap, apply, converge. Returns
     * the response array; throws on malformed input (the caller renders it).
     * Separated from I/O so it is directly testable.
     *
     * @return array{status:int, etag:string, path:string, content:string}
     */
    private function write_apply(string $raw): array
    {
        // Effectively-blocking acquire: the autoexport worker holds the lock
        // only briefly, so retry ~5s rather than failing a racing edit outright.
        $got = false;
        for ($i = 0; $i < 50; ++$i) {
            if ($this->try_acquire_sync_lock('write')) {
                $got = true;

                break;
            }
            usleep(100000);
        }
        if (! $got) {
            throw new RuntimeException('Could not acquire the polyglot sync lock (import/export running).');
        }

        try {
            $result = $this->write_dispatch($raw);
        } finally {
            $this->release_sync_lock();
        }

        // Drain a full export armed by a concurrent admin save while we held the
        // lock (local dev only). Silent: export_worker_drain logs nothing to
        // STDOUT, so the JSON response stays clean.
        if (function_exists('polyglot_autoexport_enabled') && polyglot_autoexport_enabled()
            && get_transient('polyglot_export_pending')) {
            $this->export_worker_drain([]);
        }

        return $result;
    }

    /**
     * Parse a flat payload and apply it (post or term, master or shadow). The
     * caller owns the sync lock. `$force` bypasses the etag CAS and the human
     * lock (used by `import`); without it a stale write returns 409.
     *
     * @return array{status:int, etag:string, path:string, content:string}
     */
    private function write_dispatch(string $raw, bool $force = false): array
    {
        [$front, $body] = polyglot_flat_parse($raw);
        $locale = trim((string) ($front['locale'] ?? ''));
        if ('' === $locale) {
            throw new RuntimeException('Frontmatter is missing a `locale`.');
        }
        $base_etag = (string) ($front['etag'] ?? '');
        $is_create = ('new' === $base_etag || '' === $base_etag);
        $is_master = ($locale === polyglot_get_master_locale());

        if (isset($front['taxonomy'])) {
            return $this->write_term($front, $body, $locale, $base_etag, $is_create, $is_master, $force);
        }

        return $is_master
            ? $this->write_master($front, $body, $locale, $base_etag, $is_create, $force)
            : $this->write_shadow($front, $body, $locale, $base_etag, $is_create, $force);
    }

    /**
     * Apply a master-locale write (update, or create via the _new/ handshake).
     */
    private function write_master(array $front, string $body, string $locale, string $base_etag, bool $is_create, bool $force = false): array
    {
        $id = (int) ($front['id'] ?? 0);

        if (! $id && $is_create) {
            return $this->create_master($front, $body, $locale);
        }
        if (! $id) {
            throw new RuntimeException('Master frontmatter has no `id` and is not a create (etag must be `new`).');
        }

        clean_post_cache($id);
        $post = get_post($id);
        if (! $post) {
            throw new RuntimeException("Master $id not found. (Create new masters via polyglot-flat/_new/.)");
        }

        if (! $force && $base_etag !== polyglot_post_etag($post)) {
            return $this->flat_response(409, $post, $locale, null);
        }

        $post_args = [
            'ID' => $id,
            'post_title' => $front['title'] ?? $post->post_title,
            'post_name' => $front['slug'] ?? $post->post_name,
            'post_content' => $body,
        ];
        if (isset($front['short_desc'])) {
            $post_args['post_excerpt'] = $front['short_desc'];
        }

        $res = wp_update_post($post_args, true);
        if (is_wp_error($res)) {
            throw new RuntimeException('Write failed: '.$res->get_error_message());
        }
        $this->apply_post_metas($id, $front, 'product' === $post->post_type, null);

        clean_post_cache($id);

        return $this->flat_response(200, get_post($id), $locale, null);
    }

    /**
     * Apply a shadow-locale write (create or update for a given master/locale).
     */
    private function write_shadow(array $front, string $body, string $locale, string $base_etag, bool $is_create, bool $force = false): array
    {
        $master_id = (int) ($front['master_id'] ?? 0);
        if (! $master_id) {
            throw new RuntimeException('Shadow frontmatter is missing `master_id`.');
        }
        $master_post = get_post($master_id);
        if (! $master_post) {
            throw new RuntimeException("Master $master_id not found for shadow ($locale).");
        }

        $existing_id = $this->find_shadow($master_id, $locale);

        if ($existing_id) {
            clean_post_cache($existing_id);
            $current = get_post($existing_id);
            // A create against an existing shadow, or a stale etag, both conflict
            // (unless --force overrides the lock).
            if (! $force && ($is_create || $base_etag !== polyglot_post_etag($current))) {
                return $this->flat_response(409, $current, $locale, $master_id);
            }
        } elseif (! $is_create && ! $force) {
            // base etag references a shadow that no longer exists → conflict.
            return [
                'status' => 409,
                'etag' => 'new',
                'path' => $this->flat_path_for($master_post->post_type, $master_id, $locale),
                'content' => '',
                'note' => 'shadow-missing',
            ];
        }

        $is_product = ('product' === $master_post->post_type);
        $post_args = [
            'ID' => $existing_id ?: 0,
            'post_title' => $front['title'] ?? '',
            'post_name' => $front['slug'] ?? sanitize_title($front['title'] ?? ''),
            'post_excerpt' => $front['short_desc'] ?? '',
            'post_status' => $master_post->post_status,
            'post_type' => $master_post->post_type,
            'post_content' => $body,
            'menu_order' => $master_post->menu_order,
        ];

        $GLOBALS['polyglot_pending_locale'] = $locale;
        $new_id = $existing_id ? wp_update_post($post_args, true) : wp_insert_post($post_args, true);
        unset($GLOBALS['polyglot_pending_locale']);

        if (is_wp_error($new_id)) {
            throw new RuntimeException('Write failed: '.$new_id->get_error_message());
        }

        update_post_meta($new_id, '_master_id', $master_id);
        update_post_meta($new_id, '_locale', $locale);
        update_post_meta($new_id, '_src_hash', polyglot_content_hash($master_post));
        $this->apply_post_metas($new_id, $front, $is_product, $front['mode'] ?? 'auto');

        clean_post_cache($new_id);

        return $this->flat_response($existing_id ? 200 : 201, get_post($new_id), $locale, $master_id);
    }

    /**
     * Create a brand-new master from a _new/ staging file and return its
     * canonical path. Idempotent: if a master with the same slug already exists
     * (e.g. the hook re-ran on a leftover stub), resolve to it instead of
     * duplicating.
     */
    private function create_master(array $front, string $body, string $locale): array
    {
        global $wpdb;

        $post_type = (string) ($front['type'] ?? 'page');
        if (! in_array($post_type, polyglot_get_post_types(), true)) {
            throw new RuntimeException("Unknown post type '$post_type' for new master.");
        }
        $title = (string) ($front['title'] ?? '');
        if ('' === $title) {
            throw new RuntimeException('A new master needs a `title`.');
        }
        $post_name = $front['slug'] ?? sanitize_title($title);

        $dupe = $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM $wpdb->posts p
             LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_master_id'
             WHERE p.post_name = %s AND p.post_type = %s AND pm.meta_value IS NULL
             AND p.post_status NOT IN ('trash', 'auto-draft') LIMIT 1",
            sanitize_title($post_name),
            $post_type
        ));
        if ($dupe) {
            clean_post_cache((int) $dupe);

            return $this->flat_response(200, get_post((int) $dupe), $locale, null);
        }

        $new_id = wp_insert_post([
            'post_title' => $title,
            'post_name' => $post_name,
            'post_excerpt' => $front['short_desc'] ?? '',
            'post_content' => $body,
            'post_status' => $front['status'] ?? 'draft',
            'post_type' => $post_type,
        ], true);
        if (is_wp_error($new_id)) {
            throw new RuntimeException('Create master failed: '.$new_id->get_error_message());
        }
        $this->apply_post_metas($new_id, $front, 'product' === $post_type, null);

        clean_post_cache($new_id);

        return $this->flat_response(201, get_post($new_id), $locale, null);
    }

    /**
     * Apply slug/price metas and (for shadows) the Human-Lock stamp. `$mode` is
     * null for masters (no stamp); 'manual' stamps _translation_mode, anything
     * else clears it so the bulk `translate` break-glass can still touch it.
     */
    private function apply_post_metas(int $post_id, array $front, bool $is_product, ?string $mode): void
    {
        if (isset($front['slug'])) {
            update_post_meta($post_id, 'custom_permalink', $front['slug']);
        }
        if ($is_product && isset($front['price']) && '' !== $front['price']) {
            update_post_meta($post_id, '_price', $front['price']);
            update_post_meta($post_id, '_regular_price', $front['price']);
        }
        if (null === $mode) {
            return;
        }
        if ('manual' === $mode) {
            update_post_meta($post_id, '_translation_mode', 'manual');
        } else {
            delete_post_meta($post_id, '_translation_mode');
        }
    }

    /**
     * Apply a term write (taxonomy frontmatter). Master = update only (new
     * master terms are created in wp-admin); shadow = create or update. CAS on
     * the term etag unless $force. The flat body carries the term description.
     */
    private function write_term(array $front, string $body, string $locale, string $base_etag, bool $is_create, bool $is_master, bool $force): array
    {
        $taxonomy = (string) $front['taxonomy'];
        $name = (string) ($front['name'] ?? '');
        $slug = (string) ($front['slug'] ?? sanitize_title($name));
        $term_args = ['name' => $name, 'slug' => $slug, 'description' => $body];

        if ($is_master) {
            $term_id = (int) ($front['term_id'] ?? 0);
            if (! $term_id) {
                throw new RuntimeException('Master term has no `term_id` (create master terms in wp-admin).');
            }
            $term = get_term($term_id, $taxonomy);
            if (! $term instanceof WP_Term) {
                throw new RuntimeException("Master term $term_id not found in $taxonomy.");
            }
            if (! $force && $base_etag !== polyglot_term_etag($term)) {
                return $this->term_flat_response(409, $term, $locale, null, $taxonomy);
            }
            if ('' !== $name) {
                $term_args['name'] = $name;
            } else {
                unset($term_args['name']);
            }
            $res = wp_update_term($term_id, $taxonomy, $term_args);
            if (is_wp_error($res)) {
                throw new RuntimeException('Term write failed: '.$res->get_error_message());
            }
            clean_term_cache($term_id, $taxonomy);

            return $this->term_flat_response(200, get_term($term_id, $taxonomy), $locale, null, $taxonomy);
        }

        $master_term_id = (int) ($front['master_term_id'] ?? 0);
        if (! $master_term_id) {
            throw new RuntimeException('Shadow term is missing `master_term_id`.');
        }
        $existing = (int) $this->find_shadow_term($master_term_id, $locale);

        if ($existing) {
            $current = get_term($existing, $taxonomy);
            if (! $force && ($is_create || $base_etag !== polyglot_term_etag($current))) {
                return $this->term_flat_response(409, $current, $locale, $master_term_id, $taxonomy);
            }
            wp_update_term($existing, $taxonomy, $term_args);
            clean_term_cache($existing, $taxonomy);

            return $this->term_flat_response(200, get_term($existing, $taxonomy), $locale, $master_term_id, $taxonomy);
        }

        if (! $is_create && ! $force) {
            return [
                'status' => 409,
                'etag' => 'new',
                'path' => $this->term_flat_path($taxonomy, $master_term_id, $locale),
                'content' => '',
                'note' => 'shadow-missing',
            ];
        }

        $ins = wp_insert_term($name, $taxonomy, ['slug' => $slug, 'description' => $body]);
        if (is_wp_error($ins)) {
            throw new RuntimeException('Term create failed: '.$ins->get_error_message());
        }
        $new_id = (int) $ins['term_id'];
        update_term_meta($new_id, '_master_term_id', $master_term_id);
        update_term_meta($new_id, '_locale', $locale);
        clean_term_cache($new_id, $taxonomy);

        return $this->term_flat_response(201, get_term($new_id, $taxonomy), $locale, $master_term_id, $taxonomy);
    }

    private function term_flat_path(string $taxonomy, int $folder_id, string $locale): string
    {
        return polyglot_translations_dir()."/term-$taxonomy-$folder_id/$locale.html";
    }

    private function term_flat_response(int $status, WP_Term $term, string $locale, ?int $master_term_id, string $taxonomy): array
    {
        return [
            'status' => $status,
            'etag' => polyglot_term_etag($term),
            'path' => $this->term_flat_path($taxonomy, $master_term_id ?? $term->term_id, $locale),
            'content' => polyglot_term_flat_build($term, $locale, $master_term_id, $taxonomy),
        ];
    }

    /**
     * Canonical flat path for a post. The folder is master-keyed: a shadow
     * lives in its master's folder, so pass the master id as $folder_id.
     */
    private function flat_path_for(string $post_type, int $folder_id, string $locale): string
    {
        return polyglot_translations_dir()."/$post_type-$folder_id/$locale.html";
    }

    /**
     * Build a write() response: status + the canonical serialized file + its
     * fresh etag + path.
     *
     * @return array{status:int, etag:string, path:string, content:string}
     */
    private function flat_response(int $status, WP_Post $post, string $locale, ?int $master_id): array
    {
        $is_product = ('product' === $post->post_type);

        return [
            'status' => $status,
            'etag' => polyglot_post_etag($post),
            'path' => $this->flat_path_for($post->post_type, $master_id ?? $post->ID, $locale),
            'content' => polyglot_flat_build($post, $locale, $master_id, $is_product),
        ];
    }

    /**
     * Recursively delete a flat folder and its contents. Flat folders hold a
     * single level of `<locale>.html` files; recursion is defensive only.
     */
    private function rmdir_recursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $child = "$path/$entry";
            if (is_dir($child) && ! is_link($child)) {
                $this->rmdir_recursive($child);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- WP-CLI command running with admin privileges; WP_Filesystem is overkill for batch flat-file I/O.
                unlink($child);
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- WP-CLI command running with admin privileges; WP_Filesystem is overkill for batch flat-file I/O.
        rmdir($path);
    }

    private function acquire_sync_lock(string $command): void
    {
        if ($this->try_acquire_sync_lock($command)) {
            return;
        }

        $info = get_transient('polyglot_sync_lock_info');
        $detail = $info
            ? sprintf(' (running: %s, PID %d, started %s)', $info['command'], $info['pid'], $info['started'])
            : '';
        WP_CLI::error("Another polyglot import/export is already running{$detail}. Please wait and retry.");
    }

    /**
     * Grab the sync lock without erroring on failure. Returns whether it was
     * acquired; the background worker uses this to bail out silently.
     */
    private function try_acquire_sync_lock(string $command): bool
    {
        global $wpdb;

        $got = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'polyglot_sync', 0));
        if ('1' !== $got) {
            return false;
        }

        set_transient('polyglot_sync_lock_info', [
            'command' => $command,
            'pid' => getmypid(),
            'started' => gmdate('Y-m-d H:i:s'),
        ], 3600);

        return true;
    }

    private function release_sync_lock(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'polyglot_sync'));
        delete_transient('polyglot_sync_lock_info');
    }

    private function validate_shadow_locale(string $locale): void
    {
        foreach (POLYGLOT_LOCALES as $cfg) {
            if ($cfg['locale'] === $locale && empty($cfg['master'])) {
                return;
            }
        }

        $available = implode(', ', array_column(array_filter(POLYGLOT_LOCALES, static fn ($c) => empty($c['master'])), 'locale'));
        WP_CLI::error("Unknown target locale '$locale'. Available: {$available}");
    }

    private function find_shadow_comment($master_comment_id, $locale)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT cm1.comment_id FROM $wpdb->commentmeta cm1
             JOIN $wpdb->commentmeta cm2 ON cm1.comment_id = cm2.comment_id AND cm2.meta_key = '_locale'
             WHERE cm1.meta_key = '_master_comment_id' AND cm1.meta_value = %d
             AND cm2.meta_value = %s LIMIT 1",
            $master_comment_id,
            $locale
        ));
    }

    private function find_shadow($master_id, $locale)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT pm1.post_id FROM $wpdb->postmeta pm1
             JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
             WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d
             AND pm2.meta_value = %s LIMIT 1",
            $master_id,
            $locale
        ));
    }

    private function find_shadow_term($master_term_id, $locale)
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT tm1.term_id FROM $wpdb->termmeta tm1
             JOIN $wpdb->termmeta tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = '_locale'
             WHERE tm1.meta_key = '_master_term_id' AND tm1.meta_value = %d
             AND tm2.meta_value = %s LIMIT 1",
            $master_term_id,
            $locale
        ));
    }

    /**
     * Build a map of all post shadow mappings in one query.
     *
     * @param string[] $post_types post types to include
     *
     * @return array<int, array<string, int>> $map[$master_id][$locale] = $shadow_id
     */
    private function build_shadow_map(array $post_types): array
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $query = $wpdb->prepare(
            "SELECT pm1.meta_value AS master_id, pm2.meta_value AS locale, pm1.post_id AS shadow_id
             FROM $wpdb->postmeta pm1
             JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
             JOIN $wpdb->posts p ON pm1.post_id = p.ID AND p.post_type IN ($placeholders)
             WHERE pm1.meta_key = '_master_id'",
            ...$post_types
        );

        $rows = $wpdb->get_results($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $query is built from $wpdb->prepare()
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->master_id][$row->locale] = (int) $row->shadow_id;
        }

        return $map;
    }

    /**
     * Build a map of all term shadow mappings in one query.
     *
     * @return array<int, array<string, int>> $map[$master_term_id][$locale] = $shadow_term_id
     */
    private function build_term_shadow_map(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT tm1.meta_value AS master_term_id, tm2.meta_value AS locale, tm1.term_id AS shadow_term_id
             FROM $wpdb->termmeta tm1
             JOIN $wpdb->termmeta tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = '_locale'
             WHERE tm1.meta_key = '_master_term_id'"
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->master_term_id][$row->locale] = (int) $row->shadow_term_id;
        }

        return $map;
    }

    /**
     * Translate WooCommerce permalink slugs (product_base, category_base, tag_base).
     *
     * ## USAGE
     *
     *   # Fetch current master slugs (for AI to translate):
     *   wp polyglot translate-slugs --target=en_IE
     *
     *   # Save translated slugs:
     *   wp polyglot translate-slugs --target=en_IE --payload='{"product_base":"hangboard","category_base":"product-category","tag_base":"product-tag"}'
     *
     *   # Show current slugs for all locales:
     *   wp polyglot translate-slugs --status
     */
    public function translate_slugs($args, $assoc_args): void
    {
        // --- STATUS MODE ---
        if (! empty($assoc_args['status'])) {
            $master_permalinks = get_option('woocommerce_permalinks', []);
            $all = get_option(POLYGLOT_WC_SLUGS_OPTION, []);

            WP_CLI::line(polyglot_get_master_locale().' (master): '.json_encode([
                'product_base' => $master_permalinks['product_base'] ?? 'product',
                'category_base' => $master_permalinks['category_base'] ?? 'product-category',
                'tag_base' => $master_permalinks['tag_base'] ?? 'product-tag',
            ], \JSON_UNESCAPED_UNICODE));

            foreach ($all as $locale => $slugs) {
                WP_CLI::line("$locale: ".json_encode($slugs, \JSON_UNESCAPED_UNICODE));
            }

            if (empty($all)) {
                WP_CLI::line('No translated slugs configured yet.');
            }

            return;
        }

        $target_locale = $assoc_args['target'] ?? null;
        if (! $target_locale) {
            WP_CLI::error('--target=<locale> is required.');
        }
        $this->validate_shadow_locale($target_locale);

        // --- FETCH MODE ---
        if (empty($assoc_args['payload'])) {
            $master_permalinks = get_option('woocommerce_permalinks', []);
            $data = [
                'target_locale' => $target_locale,
                'product_base' => $master_permalinks['product_base'] ?? 'product',
                'category_base' => $master_permalinks['category_base'] ?? 'product-category',
                'tag_base' => $master_permalinks['tag_base'] ?? 'product-tag',
            ];
            WP_CLI::line(json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));

            return;
        }

        // --- SAVE MODE ---
        $payload = json_decode($assoc_args['payload'], true);
        if (! $payload) {
            WP_CLI::error('Invalid JSON payload.');
        }

        $slugs = [];
        foreach (['product_base', 'category_base', 'tag_base'] as $key) {
            if (! empty($payload[$key])) {
                $slugs[$key] = sanitize_title($payload[$key]);
            }
        }

        if (empty($slugs)) {
            WP_CLI::error('Payload must contain at least one of: product_base, category_base, tag_base.');
        }

        $all = get_option(POLYGLOT_WC_SLUGS_OPTION, []);
        $all[$target_locale] = $slugs;
        update_option(POLYGLOT_WC_SLUGS_OPTION, $all, false);

        // Flush rewrite rules so new bases take effect
        flush_rewrite_rules();

        WP_CLI::success("WC slugs for '$target_locale' saved: ".json_encode($slugs, \JSON_UNESCAPED_UNICODE));
    }

    /**
     * Check post content for mislocalized internal links and localhost URLs.
     *
     * Scans href attributes, Gutenberg mediaLink JSON, and any localhost
     * URLs embedded in content (excluding wp-content/ image paths).
     *
     * ## USAGE
     *
     *   wp polyglot check-links [--locale=<locale>] [--fix] [--rendered]
     *
     * ## OPTIONS
     *
     * [--locale=<locale>]
     * : Limit to one shadow locale (default: all shadows + master).
     *
     * [--fix]
     * : Replace wrong slugs/domains in post_content and save.
     *
     * [--rendered]
     * : Scan rendered HTML via HTTP instead of post_content. Report-only, no --fix.
     */
    public function check_links($args, $assoc_args): void
    {
        $filter_locale = $assoc_args['locale'] ?? null;
        $fix = ! empty($assoc_args['fix']);
        $rendered = ! empty($assoc_args['rendered']);
        if ($rendered && $fix) {
            WP_CLI::error('--fix cannot be used with --rendered.');
        }
        global $wpdb;

        $post_types = polyglot_get_post_types();

        // 1. Build authority maps from POLYGLOT_LOCALES
        $known_authorities = [];       // authority → locale
        $master_authority = null;
        $locale_to_authority = [];
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            $known_authorities[$authority] = $cfg['locale'];
            $locale_to_authority[$cfg['locale']] = $authority;
            if (! empty($cfg['master'])) {
                $master_authority = $authority;
            }
        }
        $master_locale = polyglot_get_master_locale();

        // 2. Build slug map: $slug_map[$locale][$fr_slug] = $shadow_slug
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        // All master posts (no _master_id meta)
        $masters_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_name,
                    COALESCE(pm.meta_value, '') AS custom_permalink
             FROM $wpdb->posts p
             LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id AND pm.meta_key = 'custom_permalink'
             WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'
             AND p.ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_master_id')",
            ...$post_types
        ));

        $master_slugs = []; // master_id => trimmed slug (for lookup)
        $all_known_slugs = []; // $all_known_slugs[$locale][$slug] = true
        $slug_paths = []; // $slug_paths[$locale][$slug] = raw path (ltrim only, preserves trailing slash)

        foreach ($masters_rows as $m) {
            $raw = $m->custom_permalink ?: $m->post_name;
            $slug = trim($raw, '/');
            $master_slugs[(int) $m->ID] = $slug;
            $all_known_slugs[$master_locale][$slug] = true;
            $slug_paths[$master_locale][$slug] = ltrim($raw, '/');
        }

        // All shadow posts
        $shadows_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_name,
                    COALESCE(cp.meta_value, '') AS custom_permalink,
                    loc.meta_value AS locale,
                    mid.meta_value AS master_id
             FROM $wpdb->posts p
             JOIN $wpdb->postmeta mid ON p.ID = mid.post_id AND mid.meta_key = '_master_id'
             JOIN $wpdb->postmeta loc ON p.ID = loc.post_id AND loc.meta_key = '_locale'
             LEFT JOIN $wpdb->postmeta cp ON p.ID = cp.post_id AND cp.meta_key = 'custom_permalink'
             WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'",
            ...$post_types
        ));

        $slug_map = []; // $slug_map[$locale][$fr_slug] = $shadow_slug

        foreach ($shadows_rows as $s) {
            $raw = $s->custom_permalink ?: $s->post_name;
            $shadow_slug = trim($raw, '/');
            $locale = $s->locale;
            $fr_slug = $master_slugs[(int) $s->master_id] ?? null;

            $all_known_slugs[$locale][$shadow_slug] = true;
            $slug_paths[$locale][$shadow_slug] = ltrim($raw, '/');

            if ($fr_slug && $fr_slug !== $shadow_slug) {
                $slug_map[$locale][$fr_slug] = $shadow_slug;
            }
        }

        if ($rendered) {
            $this->check_links_rendered($filter_locale, $all_known_slugs, $slug_paths, $known_authorities);

            return;
        }

        // 3. Query posts with content — build shadow + master queries, combine as needed
        $queries = [];

        $include_shadows = ! $filter_locale || $filter_locale !== $master_locale;
        $include_master = ! $filter_locale || $filter_locale === $master_locale;

        if ($include_shadows) {
            $params = $post_types;
            $locale_filter = '';
            if ($filter_locale) {
                $locale_filter = ' AND loc.meta_value = %s';
                $params[] = $filter_locale;
            }
            $queries[] = $wpdb->prepare(
                "SELECT p.ID, p.post_content, p.post_title, loc.meta_value AS locale
                 FROM $wpdb->posts p
                 JOIN $wpdb->postmeta mid ON p.ID = mid.post_id AND mid.meta_key = '_master_id'
                 JOIN $wpdb->postmeta loc ON p.ID = loc.post_id AND loc.meta_key = '_locale'
                 WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'
                 AND p.post_content != ''{$locale_filter}",
                ...$params
            );
        }

        if ($include_master) {
            $queries[] = $wpdb->prepare(
                "SELECT p.ID, p.post_content, p.post_title, %s AS locale
                 FROM $wpdb->posts p
                 WHERE p.post_type IN ($placeholders) AND p.post_status = 'publish'
                 AND p.post_content != ''
                 AND p.ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_master_id')",
                $master_locale,
                ...$post_types
            );
        }

        $sql = count($queries) > 1
            ? '('.implode(') UNION ALL (', $queries).') ORDER BY locale, ID'
            : $queries[0].' ORDER BY ID';
        $posts_to_scan = $wpdb->get_results($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is composed of prepared statements from $wpdb->prepare()

        // 4. Scan each post
        $total_issues = 0;
        $fixed_posts = 0;

        foreach ($posts_to_scan as $sp) {
            $locale = $sp->locale;
            $content = $sp->post_content;
            $issues = [];
            $new_content = $content;
            $is_master = ($locale === $master_locale);

            // A. Extract href and mediaLink URLs
            $urls_found = [];
            // href="..." or href='...'
            if (preg_match_all('/href=["\']([^"\']+)["\']/i', $content, $m)) {
                foreach ($m[1] as $url) {
                    $urls_found[] = ['href', $url];
                }
            }
            // "mediaLink":"..." in Gutenberg block JSON comments
            if (preg_match_all('/"mediaLink":"([^"]+)"/i', $content, $m)) {
                foreach ($m[1] as $url) {
                    $urls_found[] = ['mediaLink', $url];
                }
            }

            // B. Detect any remaining localhost URLs (not already captured)
            if (preg_match_all('#https?://(?:127\.0\.0\.1|localhost)[:\d]*/(?!wp-content/)[^\s"\'<>\]},)]+#i', $content, $m)) {
                $already = array_column($urls_found, 1);
                foreach ($m[0] as $url) {
                    if (! in_array($url, $already, true)) {
                        $urls_found[] = ['content', $url];
                    }
                }
            }

            if (empty($urls_found)) {
                continue;
            }

            foreach ($urls_found as [$source, $url]) {
                // Skip anchors, mailto, tel, wp-content paths
                if (preg_match('#^(mailto:|tel:|\#|/wp-content/)#i', $url)) {
                    continue;
                }

                $issue_type = null;
                $original_url = $url;

                // Parse the URL
                $parsed = wp_parse_url($url);
                $path = $parsed['path'] ?? '';
                $host = $parsed['host'] ?? null;
                $port = $parsed['port'] ?? null;
                $fragment = isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '';

                // Build authority (host:port)
                $authority = $host;
                if ($port) {
                    $authority .= ':'.$port;
                }

                if ($authority) {
                    // Localhost URL? Always flag
                    $is_localhost = str_contains($authority, '127.0.0.1') || str_contains($authority, 'localhost');

                    if (! $is_localhost && ! isset($known_authorities[$authority])) {
                        continue; // external
                    }

                    // Skip wp-content paths in absolute URLs
                    if (str_contains($path, '/wp-content/')) {
                        continue;
                    }

                    $slug = trim($path, '/');

                    if ($is_localhost) {
                        $issue_type = 'LOCALHOST';
                    } elseif ($known_authorities[$authority] !== $locale) {
                        $issue_type = 'WRONG_DOMAIN';
                    }
                } else {
                    $slug = trim($path, '/');
                }

                if (! $slug) {
                    continue;
                }

                // Check if this slug is a French master slug that should be localized (shadows only)
                $localized_slug = (! $is_master) ? ($slug_map[$locale][$slug] ?? null) : null;
                $has_wrong_slug = (null !== $localized_slug);

                // Check for trailing slash on known internal links (href only)
                // Respect user-defined trailing slash in custom_permalink
                $trailing_slash = false;
                if ('href' === $source && ! $authority) {
                    $is_known_slug = isset($all_known_slugs[$locale][$slug]);
                    $raw_path = $slug_paths[$locale][$slug] ?? null;
                    $cp_has_slash = $raw_path && str_ends_with($raw_path, '/');
                    if ($is_known_slug && str_ends_with($path, '/') && '/' !== $path && ! $cp_has_slash) {
                        $trailing_slash = true;
                    }
                }

                if (! $has_wrong_slug && ! $issue_type && ! $trailing_slash) {
                    continue;
                }

                $replacement_slug = $has_wrong_slug ? $localized_slug : $slug;

                $issue_type = implode('+', array_filter([
                    $issue_type,
                    $has_wrong_slug ? 'WRONG_SLUG' : null,
                    $trailing_slash ? 'TRAILING_SLASH' : null,
                ]));

                $issues[] = [$issue_type, $source, $original_url, $replacement_slug];

                // --fix: build replacement (use raw path to preserve user-defined trailing slash)
                if ($fix) {
                    $raw = $slug_paths[$locale][$replacement_slug] ?? $replacement_slug;
                    $new_href = '/'.$raw;
                    if ($fragment) {
                        $new_href = '/'.rtrim($raw, '/').$fragment;
                    }
                    // Replace with full attribute context to avoid substring collisions
                    if ('href' === $source) {
                        $new_content = str_replace(
                            'href="'.$original_url.'"',
                            'href="'.$new_href.'"',
                            $new_content
                        );
                        $new_content = str_replace(
                            "href='".$original_url."'",
                            "href='".$new_href."'",
                            $new_content
                        );
                    } elseif ('mediaLink' === $source) {
                        $new_content = str_replace(
                            '"mediaLink":"'.$original_url.'"',
                            '"mediaLink":"'.$new_href.'"',
                            $new_content
                        );
                    } else {
                        $new_content = str_replace($original_url, $new_href, $new_content);
                    }
                }
            }

            if (! empty($issues)) {
                WP_CLI::line("\n[$locale] Post {$sp->ID} — {$sp->post_title}");
                foreach ($issues as [$type, $source, $url, $suggestion]) {
                    WP_CLI::line("  [$type] $source=\"$url\" → /$suggestion");
                    ++$total_issues;
                }

                if ($fix && $new_content !== $content) {
                    wp_update_post([
                        'ID' => (int) $sp->ID,
                        'post_content' => $new_content,
                    ]);
                    ++$fixed_posts;
                }
            }
        }

        if (0 === $total_issues) {
            WP_CLI::success('No mislocalized links found.');
        } else {
            $msg = "$total_issues issue(s) found.";
            if ($fix) {
                $msg .= " Fixed $fixed_posts post(s).";
            }
            WP_CLI::success($msg);
        }
    }

    private function check_links_rendered(
        ?string $filter_locale,
        array $all_known_slugs,
        array $slug_paths,
        array $known_authorities,
    ): void {
        // Build list of URLs to fetch, grouped by locale
        $urls = []; // [ [url, locale, label], ... ]
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            $locale = $cfg['locale'];
            if ($filter_locale && $filter_locale !== $locale) {
                continue;
            }
            $base = polyglot_authority_to_url($authority);

            // Homepage
            $urls[] = [$base.'/', $locale, $base.'/'];

            // All known slugs for this locale
            foreach (array_keys($all_known_slugs[$locale] ?? []) as $slug) {
                $path = $slug_paths[$locale][$slug] ?? $slug;
                $urls[] = [$base.'/'.$path, $locale, $base.'/'.$path];
            }

            // Sitemaps
            $urls[] = [$base.'/wp-sitemap-posts-page-1.xml', $locale, null];
            $urls[] = [$base.'/wp-sitemap-posts-product-1.xml', $locale, null];
        }

        $progress = \WP_CLI\Utils\make_progress_bar('Scanning rendered HTML', count($urls));

        // Collect all issues with page-count tracking for classification
        // $issues[$locale][$dedup_key] = ['href' => ..., 'src' => ..., 'pages' => [...], 'source_type' => ...]
        $issues = [];

        foreach ($urls as [$url, $locale, $label]) {
            $progress->tick();

            // Only skip TLS verification for local hosts (self-signed dev certs).
            $host = (string) wp_parse_url($url, \PHP_URL_HOST);
            $is_local = str_contains($host, 'localhost')
                || str_contains($host, '127.0.0.1')
                || str_ends_with($host, '.test')
                || str_ends_with($host, '.local');

            $response = wp_remote_get($url, [
                'timeout' => 15,
                'sslverify' => ! $is_local,
                'redirection' => 0,
            ]);

            if (is_wp_error($response)) {
                WP_CLI::warning("Failed to fetch $url: ".$response->get_error_message());

                continue;
            }

            $status = wp_remote_retrieve_response_code($response);
            if (200 !== $status) {
                // Silently skip sitemaps that 404
                if (null === $label && 404 === $status) {
                    continue;
                }
                WP_CLI::warning("HTTP $status for $url — skipping.");

                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $is_xml = null === $label; // sitemap
            $source_type = $is_xml ? 'sitemap' : 'page';

            $hrefs = [];
            if ($is_xml) {
                // Extract <loc> and <xhtml:link href="..."> from sitemap XML
                if (preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $body, $m)) {
                    foreach ($m[1] as $h) {
                        $hrefs[] = ['<loc>', $h];
                    }
                }
                if (preg_match_all('#<xhtml:link[^>]+href=["\']([^"\']+)["\']#i', $body, $m)) {
                    foreach ($m[1] as $h) {
                        $hrefs[] = ['hreflang href', $h];
                    }
                }
            } else {
                // Extract href="..." from HTML
                if (preg_match_all('/href=["\']([^"\']+)["\']/i', $body, $m)) {
                    foreach ($m[1] as $h) {
                        $hrefs[] = ['href', $h];
                    }
                }
            }

            // Reset per-page dedup
            $page_seen = [];

            foreach ($hrefs as [$src_label, $href]) {
                // Skip non-relevant hrefs
                if (preg_match('#^(mailto:|tel:|javascript:|\#)#i', $href)) {
                    continue;
                }

                $parsed = wp_parse_url($href);
                $path = $parsed['path'] ?? '';
                $host = $parsed['host'] ?? null;
                $port = $parsed['port'] ?? null;
                $authority = null !== $host ? $host.(null !== $port ? ":$port" : '') : null;

                // For relative URLs, authority is null — treat as same-locale
                if ($authority && ! isset($known_authorities[$authority])) {
                    continue; // external
                }

                // Skip WP internal paths
                if (preg_match('#/wp-(?:content|admin|json|login)/#', $path)) {
                    continue;
                }

                // Only check trailing slash issues
                if ('/' === $path || ! str_ends_with($path, '/')) {
                    continue;
                }

                $slug = trim($path, '/');
                if (! $slug) {
                    continue;
                }

                // Determine which locale this href belongs to
                $href_locale = $authority ? ($known_authorities[$authority] ?? $locale) : $locale;

                if (! isset($all_known_slugs[$href_locale][$slug])) {
                    continue;
                }

                // Check if custom_permalink has trailing slash (respect it)
                $raw_path = $slug_paths[$href_locale][$slug] ?? null;
                if ($raw_path && str_ends_with($raw_path, '/')) {
                    continue;
                }

                // Dedup on path (ignore fragment variants like /slug/#a vs /slug/#b)
                $dedup_key = ($authority ?? '').$path;

                // Skip if already seen on this same page
                if (isset($page_seen[$dedup_key])) {
                    continue;
                }
                $page_seen[$dedup_key] = true;

                if (! isset($issues[$locale][$dedup_key])) {
                    $issues[$locale][$dedup_key] = [
                        'href' => $href,
                        'src' => $src_label,
                        'pages' => [],
                        'source_type' => $source_type,
                    ];
                }
                $issues[$locale][$dedup_key]['pages'][] = $label ?? $url;
            }
        }

        $progress->finish();

        // Print grouped output by locale
        $total_issues = 0;
        foreach ($issues as $locale => $locale_issues) {
            $template_issues = [];
            $sitemap_issues = [];
            $page_specific = []; // source_url => [issues]

            foreach ($locale_issues as $dedup_key => $info) {
                ++$total_issues;
                $display = 'href' === $info['src']
                    ? "href=\"{$info['href']}\""
                    : "{$info['src']} {$info['href']}";

                if ('sitemap' === $info['source_type']) {
                    $sitemap_source = $info['pages'][0] ?? 'sitemap';
                    $sitemap_issues[$sitemap_source][] = "[TRAILING_SLASH] $display";
                } elseif (count($info['pages']) > 1) {
                    $template_issues[] = "[TRAILING_SLASH] $display";
                } else {
                    $page_url = $info['pages'][0];
                    $page_specific[$page_url][] = "[TRAILING_SLASH] $display";
                }
            }

            if (empty($template_issues) && empty($sitemap_issues) && empty($page_specific)) {
                continue;
            }

            WP_CLI::line("\n— $locale —");

            if (! empty($template_issues)) {
                WP_CLI::line('  template (menu/footer — appears on every page):');
                foreach ($template_issues as $issue) {
                    WP_CLI::line("    $issue");
                }
            }

            foreach ($sitemap_issues as $source => $sitemap_list) {
                WP_CLI::line("  $source");
                foreach ($sitemap_list as $issue) {
                    WP_CLI::line("    $issue");
                }
            }

            foreach ($page_specific as $page_url => $page_list) {
                WP_CLI::line("  $page_url");
                foreach ($page_list as $issue) {
                    WP_CLI::line("    $issue");
                }
            }
        }

        if (0 === $total_issues) {
            WP_CLI::success('No trailing slash issues found in rendered HTML.');
        } else {
            WP_CLI::warning("$total_issues issue(s) found across ".count($issues).' locale(s).');
        }
    }
}

WP_CLI::add_command('polyglot', 'Polyglot_CLI');

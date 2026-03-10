<?php

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
     */
    public function translate($args, $assoc_args)
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

        $master_post = get_post($master_id);
        $post_args = [
            'ID' => $existing_id ? $existing_id : 0,
            'post_title' => $ai_data['translated_title'],
            'post_content' => $ai_data['translated_description'] ?? '',
            'post_excerpt' => $ai_data['translated_short_desc'] ?? '',
            'post_status' => 'publish',
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
    public function translate_term($args, $assoc_args)
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
     */
    public function untranslated($args, $assoc_args)
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

        $total_missing = 0;
        foreach ($shadow_locales as $locale) {
            $missing = [];
            foreach ($masters as $id) {
                $shadow = $this->find_shadow($id, $locale);
                if (! $shadow) {
                    $missing[] = $id;
                }
            }
            if (! empty($missing)) {
                WP_CLI::log("\n[$locale] ".count($missing)." untranslated $post_type(s):");
                foreach ($missing as $id) {
                    WP_CLI::log("  - ID $id: ".get_the_title($id));
                }
                $total_missing += count($missing);
            }
        }

        if (0 === $total_missing) {
            WP_CLI::success('All posts are translated!');
        }
    }

    /**
     * List all configured locales.
     *
     * ## USAGE
     *
     *   wp polyglot locales
     */
    public function locales()
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
     */
    public function export($args, $assoc_args)
    {
        $this->acquire_sync_lock('export');

        try {
            $dir = rtrim(ABSPATH.POLYGLOT_TRANSLATIONS_DIR, '/');
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
                WP_CLI::error('No shadow locales matched.');
            }

            // Post types to export
            $post_types = $filter_type ? [$filter_type] : polyglot_get_post_types();

            // Ensure output dir exists
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // --- POSTS ---
            $tsv_rows = [];
            $master_locale = polyglot_get_master_locale();
            $tsv_rows[] = array_merge(['type', 'master_id', 'field', $master_locale], $shadow_locales);

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

            foreach ($post_types as $post_type) {
                $masters = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM $wpdb->posts
                 WHERE post_type = %s AND post_status = 'publish'
                 AND ID NOT IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_master_id')
                 ORDER BY ID",
                    $post_type
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

                    // Short fields → TSV
                    $short_fields = ['title', 'slug'];
                    if ($is_product) {
                        $short_fields[] = 'short_desc';
                    }

                    foreach ($short_fields as $field) {
                        $fr_val = match ($field) {
                            'title' => $post->post_title,
                            'slug' => get_post_meta($post->ID, 'custom_permalink', true) ?: $post->post_name,
                            'short_desc' => $post->post_excerpt,
                        };

                        $row = [$post_type, $master_id, $field, $fr_val];
                        foreach ($shadow_locales as $locale) {
                            $s = $shadows[$locale];
                            $row[] = $s ? match ($field) {
                                'title' => $s->post_title,
                                'slug' => get_post_meta($s->ID, 'custom_permalink', true) ?: $s->post_name,
                                'short_desc' => $s->post_excerpt,
                            } : '';
                        }
                        $tsv_rows[] = $row;
                    }

                    // Long content → HTML files
                    $fr_content = $post->post_content;
                    $folder = "$dir/$post_type-$master_id";

                    if ('' !== trim($fr_content)) {
                        if (! is_dir($folder)) {
                            mkdir($folder, 0755, true);
                        }
                        file_put_contents("$folder/$master_locale.html", $fr_content);

                        foreach ($shadow_locales as $locale) {
                            $s = $shadows[$locale];
                            if ($s && '' !== trim($s->post_content)) {
                                file_put_contents("$folder/$locale.html", $s->post_content);
                            }
                        }
                    }
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
                        [
                            'key' => '_master_term_id',
                            'compare' => 'NOT EXISTS',
                        ],
                    ],
                ]);

                if (is_wp_error($terms)) {
                    continue;
                }

                foreach ($terms as $term) {
                    // Skip if this term IS a shadow
                    $is_shadow = get_term_meta($term->term_id, '_master_term_id', true);
                    if ($is_shadow) {
                        continue;
                    }

                    foreach (['name', 'slug'] as $field) {
                        $fr_val = ('name' === $field) ? $term->name : $term->slug;
                        $row = ["term:$taxonomy", $term->term_id, $field, $fr_val];

                        foreach ($shadow_locales as $locale) {
                            $shadow_tid = $term_shadow_map[$term->term_id][$locale] ?? null;
                            if ($shadow_tid) {
                                $st = get_term($shadow_tid);
                                $row[] = ('name' === $field) ? $st->name : $st->slug;
                            } else {
                                $row[] = '';
                            }
                        }
                        $tsv_rows[] = $row;
                    }
                }
            }

            // Write TSV
            $tsv_path = "$dir/translations.tsv";
            $fp = fopen($tsv_path, 'w');
            foreach ($tsv_rows as $row) {
                fputcsv($fp, $row, "\t");
            }
            fclose($fp);

            $post_count = count(array_unique(array_column(array_slice($tsv_rows, 1), 1)));
            $this->release_sync_lock();
            WP_CLI::success("Exported to $dir/ — $post_count entities, ".(count($tsv_rows) - 1).' TSV rows.');
        } catch (Throwable $e) {
            $this->release_sync_lock();

            throw $e;
        }
    }

    /**
     * Import translations from a directory (TSV + HTML files).
     *
     * ## USAGE
     *
     *   wp polyglot import [--dry-run] [--force]
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Preview changes without writing.
     *
     * [--force]
     * : Overwrite manually-edited shadows.
     */
    public function import($args, $assoc_args)
    {
        $this->acquire_sync_lock('import');

        try {
            $dir = rtrim(ABSPATH.POLYGLOT_TRANSLATIONS_DIR, '/');
            $dry_run = ! empty($assoc_args['dry-run']);
            $force = ! empty($assoc_args['force']);
            global $wpdb;

            $tsv_path = "$dir/translations.tsv";
            if (! file_exists($tsv_path)) {
                WP_CLI::error("File not found: $tsv_path");
            }

            $fp = fopen($tsv_path, 'r');
            $header = fgetcsv($fp, 0, "\t");
            // header: type, master_id, field, <master_locale>, locale1, locale2, ...
            $master_locale = $header[3];
            $locales = array_slice($header, 4);

            // Group rows by (type, master_id)
            $entities = [];
            while (($row = fgetcsv($fp, 0, "\t")) !== false) {
                if (count($row) < 4) {
                    continue;
                }
                $type = $row[0];
                $master_id = $row[1];
                $field = $row[2];
                $key = "$type|$master_id";

                // Master column (index 3)
                $master_val = $row[3] ?? '';
                if ('' !== $master_val) {
                    $entities[$key][$master_locale][$field] = $master_val;
                }

                foreach ($locales as $i => $locale) {
                    $val = $row[$i + 4] ?? '';
                    if ('' !== $val) {
                        $entities[$key][$locale][$field] = $val;
                    }
                }
            }
            fclose($fp);

            $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

            // Collect post types and master IDs from entity keys
            $post_types_index = [];
            $master_ids_index = [];
            foreach (array_keys($entities) as $entity_key) {
                [$type, $id] = explode('|', $entity_key);
                if (! str_starts_with($type, 'term:')) {
                    $post_types_index[$type] = true;
                    $master_ids_index[$id] = (int) $id;
                }
            }
            $post_types = array_keys($post_types_index);
            $master_ids = array_values($master_ids_index);

            // Batch-preload all shadow mappings
            $shadow_map = $post_types ? $this->build_shadow_map($post_types) : [];
            $term_shadow_map = $this->build_term_shadow_map();

            // Collect all shadow IDs for batch meta preloading
            $all_shadow_ids = [];
            foreach ($shadow_map as $per_locale) {
                foreach ($per_locale as $sid) {
                    $all_shadow_ids[] = $sid;
                }
            }

            // Batch-load _translation_mode for all existing shadows
            $translation_modes = $this->batch_load_postmeta($all_shadow_ids, '_translation_mode');

            // Prime post caches for masters
            if ($master_ids) {
                _prime_post_caches($master_ids);
            }

            // Performance: defer term counting and suspend cache invalidation during import
            if (! $dry_run) {
                wp_defer_term_counting(true);
                wp_suspend_cache_invalidation(true);
            }

            foreach ($entities as $key => $locale_data) {
                [$type, $master_id] = explode('|', $key);

                // --- TERMS ---
                if (str_starts_with($type, 'term:')) {
                    $taxonomy = substr($type, 5);
                    foreach ($locale_data as $locale => $fields) {
                        $name = $fields['name'] ?? null;
                        if (! $name) {
                            continue;
                        }

                        $slug = $fields['slug'] ?? sanitize_title($name);

                        if ($locale === $master_locale) {
                            $term = get_term((int) $master_id, $taxonomy);
                            if (! $term || is_wp_error($term)) {
                                continue;
                            }
                            if ($dry_run) {
                                WP_CLI::log("[DRY-RUN] Would update term:$taxonomy master $master_id ($locale): $name");
                                ++$stats['updated'];

                                continue;
                            }
                            wp_update_term((int) $master_id, $taxonomy, ['name' => $name, 'slug' => $slug]);
                            ++$stats['updated'];

                            continue;
                        }

                        $existing = $term_shadow_map[(int) $master_id][$locale] ?? null;

                        $action = $existing ? 'update' : 'create';
                        if ($dry_run) {
                            WP_CLI::log("[DRY-RUN] Would $action term:$taxonomy shadow for master $master_id ($locale): $name");
                            ++$stats[$action.'d'];

                            continue;
                        }

                        if ($existing) {
                            wp_update_term($existing, $taxonomy, ['name' => $name, 'slug' => $slug]);
                            ++$stats['updated'];
                        } else {
                            // Adopt orphan term if one exists with the same slug
                            $orphan = get_term_by('slug', $slug, $taxonomy);
                            if ($orphan) {
                                wp_update_term($orphan->term_id, $taxonomy, ['name' => $name, 'slug' => $slug]);
                                update_term_meta($orphan->term_id, '_master_term_id', $master_id);
                                update_term_meta($orphan->term_id, '_locale', $locale);
                                $term_shadow_map[(int) $master_id][$locale] = $orphan->term_id;
                                ++$stats['updated'];
                            } else {
                                $result = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
                                if (is_wp_error($result)) {
                                    WP_CLI::warning("Term insert failed for master $master_id ($locale): ".$result->get_error_message());
                                    ++$stats['errors'];

                                    continue;
                                }
                                update_term_meta($result['term_id'], '_master_term_id', $master_id);
                                update_term_meta($result['term_id'], '_locale', $locale);
                                $term_shadow_map[(int) $master_id][$locale] = $result['term_id'];
                                ++$stats['created'];
                            }
                        }
                    }

                    continue;
                }

                // --- POSTS / PRODUCTS ---
                $post_type = $type;
                foreach ($locale_data as $locale => $fields) {
                    $title = $fields['title'] ?? null;
                    if (! $title) {
                        continue;
                    }

                    if ($locale === $master_locale) {
                        $master_post = get_post($master_id);
                        if (! $master_post) {
                            continue;
                        }

                        $html_file = "$dir/$post_type-$master_id/$locale.html";
                        $content = file_exists($html_file) ? file_get_contents($html_file) : null;

                        if ($dry_run) {
                            $has_html = null !== $content ? ' + HTML content' : '';
                            WP_CLI::log("[DRY-RUN] Would update $post_type master $master_id ($locale): \"$title\"$has_html");
                            ++$stats['updated'];

                            continue;
                        }

                        $post_args = [
                            'ID' => (int) $master_id,
                            'post_title' => $title,
                            'post_name' => $fields['slug'] ?? $master_post->post_name,
                        ];
                        if (isset($fields['short_desc'])) {
                            $post_args['post_excerpt'] = $fields['short_desc'];
                        }
                        if (null !== $content) {
                            $post_args['post_content'] = $content;
                        }

                        $result = wp_update_post($post_args);
                        if (is_wp_error($result)) {
                            WP_CLI::warning("Failed to update master $master_id: ".$result->get_error_message());
                            ++$stats['errors'];

                            continue;
                        }

                        if (isset($fields['slug'])) {
                            update_post_meta((int) $master_id, 'custom_permalink', $fields['slug']);
                        }
                        ++$stats['updated'];

                        continue;
                    }

                    $existing_id = $shadow_map[(int) $master_id][$locale] ?? null;

                    // Check human lock
                    if ($existing_id) {
                        $mode = $translation_modes[$existing_id] ?? '';
                        if ('manual' === $mode && ! $force) {
                            WP_CLI::warning("Skipped shadow $existing_id (master $master_id, $locale): manually edited. Use --force.");
                            ++$stats['skipped'];

                            continue;
                        }
                    }

                    // Load HTML content from file if it exists
                    $html_file = "$dir/$post_type-$master_id/$locale.html";
                    $content = file_exists($html_file) ? file_get_contents($html_file) : null;

                    $action = $existing_id ? 'update' : 'create';
                    if ($dry_run) {
                        $has_html = null !== $content ? ' + HTML content' : '';
                        WP_CLI::log("[DRY-RUN] Would $action $post_type shadow for master $master_id ($locale): \"$title\"$has_html");
                        ++$stats[$action.'d'];

                        continue;
                    }

                    $master_post = get_post($master_id);
                    $post_args = [
                        'ID' => $existing_id ?: 0,
                        'post_title' => $title,
                        'post_name' => $fields['slug'] ?? sanitize_title($title),
                        'post_excerpt' => $fields['short_desc'] ?? '',
                        'post_status' => 'publish',
                        'post_type' => $post_type,
                        'menu_order' => $master_post ? $master_post->menu_order : 0,
                    ];

                    $GLOBALS['polyglot_pending_locale'] = $locale;
                    if ($existing_id && null === $content) {
                        // Updating with no HTML file — don't touch existing content
                        $shadow_id = wp_update_post($post_args);
                    } else {
                        $post_args['post_content'] = $content ?? '';
                        $shadow_id = wp_insert_post($post_args);
                    }
                    unset($GLOBALS['polyglot_pending_locale']);

                    if (is_wp_error($shadow_id)) {
                        WP_CLI::warning("Failed for master $master_id ($locale): ".$shadow_id->get_error_message());
                        ++$stats['errors'];

                        continue;
                    }

                    update_post_meta($shadow_id, '_master_id', $master_id);
                    update_post_meta($shadow_id, '_locale', $locale);
                    delete_post_meta($shadow_id, '_translation_mode');

                    if (isset($fields['slug'])) {
                        update_post_meta($shadow_id, 'custom_permalink', $fields['slug']);
                    }

                    // Update shadow map for subsequent lookups
                    $shadow_map[(int) $master_id][$locale] = $shadow_id;

                    ++$stats[$action.'d'];
                }
            }

            // Restore deferred operations
            if (! $dry_run) {
                wp_suspend_cache_invalidation(false);
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
    public function translate_comment($args, $assoc_args)
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

    private function acquire_sync_lock(string $command): void
    {
        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'polyglot_sync', 0)
        );

        if ('1' !== (string) $result) {
            $info = get_transient('polyglot_sync_lock_info');
            $detail = $info
                ? sprintf(' (running: %s, PID %d, started %s)', $info['command'], $info['pid'], $info['started'])
                : '';
            WP_CLI::error("Another polyglot import/export is already running{$detail}. Please wait and retry.");
        }

        set_transient('polyglot_sync_lock_info', [
            'command' => $command,
            'pid' => getmypid(),
            'started' => gmdate('Y-m-d H:i:s'),
        ], 3600);
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

        $available = implode(', ', array_column(array_filter(POLYGLOT_LOCALES, fn ($c) => empty($c['master'])), 'locale'));
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

    /**
     * Load a single postmeta key for a set of post IDs in one query.
     *
     * @param int[]  $ids      post IDs to query
     * @param string $meta_key meta key to fetch
     *
     * @return array<int, string> $result[$post_id] = $meta_value
     */
    private function batch_load_postmeta(array $ids, string $meta_key): array
    {
        global $wpdb;

        if (! $ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM $wpdb->postmeta
             WHERE meta_key = %s AND post_id IN ($placeholders)",
            $meta_key,
            ...$ids
        ));

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->post_id] = $row->meta_value;
        }

        return $result;
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

        $rows = $wpdb->get_results($query);
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
    public function translate_slugs($args, $assoc_args)
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
}

WP_CLI::add_command('polyglot', 'Polyglot_CLI');

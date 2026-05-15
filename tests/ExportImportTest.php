<?php

/**
 * @group exportimport
 */
class ExportImportTest extends WP_UnitTestCase
{
    private string $export_dir;
    private int $master_id;
    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        $this->export_dir = rtrim(ABSPATH . POLYGLOT_TRANSLATIONS_DIR, '/');
        $this->cli = new Polyglot_CLI();

        // Create a master page (using pages since WC is not loaded)
        $this->master_id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'À propos',
            'post_name'    => 'a-propos',
            'post_excerpt' => '',
            'post_content' => '<p>Notre histoire</p>',
            'post_status'  => 'publish',
        ]);
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    public function test_export_creates_tsv_and_html(): void
    {
        $this->run_export();

        $this->assertFileExists($this->export_dir . '/translations.tsv');
        $this->assertFileExists($this->export_dir . '/page-' . $this->master_id . '-a-propos/fr_FR.html');

        $tsv = file_get_contents($this->export_dir . '/translations.tsv');
        $this->assertStringContainsString('À propos', $tsv);
        $this->assertStringContainsString("page\t{$this->master_id}\ttitle", $tsv);
    }

    public function test_export_tsv_header_contains_locales(): void
    {
        $this->run_export();

        $fp = fopen($this->export_dir . '/translations.tsv', 'r');
        $header = fgetcsv($fp, 0, "\t");
        fclose($fp);

        $this->assertSame('type', $header[0]);
        $this->assertSame('master_id', $header[1]);
        $this->assertSame('field', $header[2]);
        $this->assertSame('fr_FR', $header[3]);
        $this->assertContains('en_IE', $header);
    }

    public function test_import_creates_shadow(): void
    {
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');

        $html_dir = $this->export_dir . '/page-' . $this->master_id . '-a-propos';
        file_put_contents($html_dir . '/en_IE.html', '<p>Our story</p>');

        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNotNull($shadow_id, 'Shadow should have been created');

        $shadow = get_post($shadow_id);
        $this->assertSame('About Us', $shadow->post_title);
        $this->assertSame('about-us', $shadow->post_name);
        $this->assertSame('<p>Our story</p>', $shadow->post_content);
        $this->assertSame((string) $this->master_id, get_post_meta($shadow_id, '_master_id', true));
        $this->assertSame('en_IE', get_post_meta($shadow_id, '_locale', true));
    }

    public function test_import_updates_existing_shadow(): void
    {
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');
        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');

        $this->recursive_rmdir($this->export_dir);
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us V2', 'about-us');
        $this->run_import();

        $shadow = get_post($shadow_id);
        $this->assertSame('About Us V2', $shadow->post_title);
    }

    public function test_import_respects_human_lock(): void
    {
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');
        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        update_post_meta($shadow_id, '_translation_mode', 'manual');

        $this->recursive_rmdir($this->export_dir);
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'Should Not Update', 'about-us');
        $this->run_import();

        $shadow = get_post($shadow_id);
        $this->assertSame('About Us', $shadow->post_title, 'Should not overwrite manually edited shadow');
    }

    public function test_import_force_overrides_human_lock(): void
    {
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');
        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        update_post_meta($shadow_id, '_translation_mode', 'manual');

        $this->recursive_rmdir($this->export_dir);
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'Forced Update', 'about-us');
        $this->run_import(['force' => true]);

        $shadow = get_post($shadow_id);
        $this->assertSame('Forced Update', $shadow->post_title);
    }

    public function test_import_skips_empty_cells(): void
    {
        $this->run_export();
        // No translation injected
        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNull($shadow_id, 'No shadow should be created for empty translations');
    }

    public function test_roundtrip_preserves_html_content(): void
    {
        $html = "<h2>Heading</h2>\n<p>Paragraph with <strong>bold</strong></p>\n<ul>\n<li>Item</li>\n</ul>";

        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');
        $html_dir = $this->export_dir . '/page-' . $this->master_id . '-a-propos';
        file_put_contents($html_dir . '/en_IE.html', $html);
        $this->run_import();

        $this->recursive_rmdir($this->export_dir);
        $this->run_export();

        $exported = file_get_contents($this->export_dir . '/page-' . $this->master_id . '-a-propos/en_IE.html');
        $this->assertSame($html, $exported);
    }

    public function test_export_term_translation(): void
    {
        register_taxonomy('product_cat', 'post');

        $result = wp_insert_term('Chaussures', 'product_cat', ['slug' => 'chaussures']);
        $master_term_id = $result['term_id'];

        $shadow_result = wp_insert_term('Shoes', 'product_cat', ['slug' => 'shoes']);
        update_term_meta($shadow_result['term_id'], '_master_term_id', $master_term_id);
        update_term_meta($shadow_result['term_id'], '_locale', 'en_IE');

        $this->run_export();

        $tsv = file_get_contents($this->export_dir . '/translations.tsv');
        $this->assertStringContainsString('Chaussures', $tsv);
        $this->assertStringContainsString('Shoes', $tsv);
        $this->assertStringContainsString('term:product_cat', $tsv);
    }

    public function test_export_renames_old_format_folder(): void
    {
        // Simulate a pre-existing old-format dir (no slug)
        $old_dir = $this->export_dir . '/page-' . $this->master_id;
        mkdir($old_dir, 0755, true);
        file_put_contents($old_dir . '/fr_FR.html', '<p>old content</p>');

        $this->run_export();

        $new_dir = $this->export_dir . '/page-' . $this->master_id . '-a-propos';
        $this->assertDirectoryExists($new_dir, 'Export should rename old-format dir to new slug-based dir');
        $this->assertDirectoryDoesNotExist($old_dir, 'Old-format dir should no longer exist after rename');
    }

    public function test_import_falls_back_to_old_format_folder(): void
    {
        $this->run_export();
        $this->inject_translation_in_tsv('en_IE', 'About Us', 'about-us');

        // Move the new-format dir back to old format to simulate pre-migration state
        $new_dir = $this->export_dir . '/page-' . $this->master_id . '-a-propos';
        $old_dir = $this->export_dir . '/page-' . $this->master_id;
        rename($new_dir, $old_dir);
        file_put_contents($old_dir . '/en_IE.html', '<p>Old story</p>');

        $this->run_import();

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNotNull($shadow_id, 'Shadow should be created even with old-format dir');
        $this->assertSame('<p>Old story</p>', get_post($shadow_id)->post_content);
    }

    // === Helpers ===

    private function run_export(array $args = []): void
    {
        $this->cli->export([], $args);
    }

    private function run_import(array $args = []): void
    {
        $assoc = [];
        if (! empty($args['force'])) {
            $assoc['force'] = true;
        }
        if (! empty($args['dry-run'])) {
            $assoc['dry-run'] = true;
        }
        $this->cli->import([], $assoc);
    }

    private function inject_translation_in_tsv(string $locale, string $title, string $slug): void
    {
        $tsv_path = $this->export_dir . '/translations.tsv';
        $fp = fopen($tsv_path, 'r');
        $header = fgetcsv($fp, 0, "\t");
        $locale_idx = array_search($locale, $header, true);

        $rows = [$header];
        while (($row = fgetcsv($fp, 0, "\t")) !== false) {
            while (count($row) < count($header)) {
                $row[] = '';
            }
            if ($row[1] == $this->master_id && $row[2] === 'title') {
                $row[$locale_idx] = $title;
            }
            if ($row[1] == $this->master_id && $row[2] === 'slug') {
                $row[$locale_idx] = $slug;
            }
            $rows[] = $row;
        }
        fclose($fp);

        $fp = fopen($tsv_path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row, "\t");
        }
        fclose($fp);
    }

    private function find_shadow(int $master_id, string $locale): ?int
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT pm1.post_id FROM $wpdb->postmeta pm1
             JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
             WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d
             AND pm2.meta_value = %s LIMIT 1",
            $master_id,
            $locale
        ));
        return $result ? (int) $result : null;
    }

    private function recursive_rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}

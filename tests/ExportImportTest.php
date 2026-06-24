<?php

/**
 * @group exportimport
 *
 * Export = DB → flat (frontmatter + body, stable {type}-{id} folders, _index.md,
 * term-{taxonomy}-{id} folders). Import = flat → DB, flat-driven, reusing the
 * optimistic-locked write path. No TSV (Option B).
 */
class ExportImportTest extends WP_UnitTestCase
{
    private string $export_dir;

    private int $master_id;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        $this->export_dir = polyglot_translations_dir();
        $this->cli = new Polyglot_CLI();

        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_name' => 'a-propos',
            'post_excerpt' => '',
            'post_content' => '<p>Notre histoire</p>',
            'post_status' => 'publish',
        ]);
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    // ===== Export =====

    public function testExportCreatesHtmlAndIndexNoTsv(): void
    {
        $this->run_export();

        $this->assertFileExists($this->master_dir().'/fr_FR.html');
        $this->assertFileExists($this->export_dir.'/_index.md');
        $this->assertFileDoesNotExist($this->export_dir.'/translations.tsv', 'TSV is retired (Option B)');

        $this->assertStringContainsString("| {$this->master_id} | page | a-propos | À propos |", file_get_contents($this->export_dir.'/_index.md'));
    }

    public function testExportRemovesStaleTsv(): void
    {
        if (! is_dir($this->export_dir)) {
            mkdir($this->export_dir, 0755, true);
        }
        file_put_contents($this->export_dir.'/translations.tsv', "stale\n");

        $this->run_export();

        $this->assertFileDoesNotExist($this->export_dir.'/translations.tsv', 'Export must drop a retired TSV');
    }

    public function testExportUsesStableIdFolder(): void
    {
        $this->run_export();

        $this->assertDirectoryExists($this->master_dir());
        $this->assertDirectoryDoesNotExist($this->export_dir.'/page-'.$this->master_id.'-a-propos');
    }

    public function testExportFrontmatterCarriesEtagAndStripsOnImport(): void
    {
        $this->run_export();

        $raw = file_get_contents($this->master_dir().'/fr_FR.html');
        $this->assertStringStartsWith("---\n", $raw);
        $this->assertStringContainsString('etag: ', $raw);
        $this->assertStringContainsString('À propos', $raw);
        $this->assertStringNotContainsString('master_id:', $raw, 'A master file must not carry master_id');

        $this->assertSame('<p>Notre histoire</p>', polyglot_flat_body($raw));
        $this->assertSame(polyglot_post_etag(get_post($this->master_id)), $this->frontmatter($raw)['etag']);
    }

    public function testExportIncludesDraftMaster(): void
    {
        $draft_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Brouillon',
            'post_name' => 'brouillon',
            'post_content' => '<p>WIP</p>',
            'post_status' => 'draft',
        ]);

        $this->run_export();

        $this->assertFileExists($this->export_dir.'/page-'.$draft_id.'/fr_FR.html');
    }

    public function testExportExcludesAutoDraftMaster(): void
    {
        $auto_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Auto',
            'post_name' => 'auto',
            'post_content' => '<p>placeholder</p>',
            'post_status' => 'auto-draft',
        ]);

        $this->run_export();

        $this->assertDirectoryDoesNotExist($this->export_dir.'/page-'.$auto_id);
    }

    public function testExportWritesTermFlatFiles(): void
    {
        register_taxonomy('product_cat', 'post');
        $r = wp_insert_term('Chaussures', 'product_cat', ['slug' => 'chaussures']);
        $master_tid = $r['term_id'];
        $shadow = wp_insert_term('Shoes', 'product_cat', ['slug' => 'shoes']);
        update_term_meta($shadow['term_id'], '_master_term_id', $master_tid);
        update_term_meta($shadow['term_id'], '_locale', 'en_IE');

        $this->run_export();

        $folder = $this->export_dir."/term-product_cat-$master_tid";
        $this->assertFileExists($folder.'/fr_FR.html');
        $this->assertFileExists($folder.'/en_IE.html');
        $fr = polyglot_flat_parse(file_get_contents($folder.'/fr_FR.html'))[0];
        $en = polyglot_flat_parse(file_get_contents($folder.'/en_IE.html'))[0];
        $this->assertSame('Chaussures', $fr['name']);
        $this->assertSame('product_cat', $fr['taxonomy']);
        $this->assertSame('Shoes', $en['name']);
        $this->assertSame((string) $master_tid, (string) $en['master_term_id']);
    }

    public function testExportStableFolderSurvivesSlugChange(): void
    {
        $this->run_export();
        wp_update_post(['ID' => $this->master_id, 'post_name' => 'a-propos-v2']);
        $this->run_export();

        $this->assertDirectoryExists($this->master_dir());
        $raw = file_get_contents($this->master_dir().'/fr_FR.html');
        $this->assertSame('a-propos-v2', $this->frontmatter($raw)['slug']);
    }

    public function testExportPurgesDeletedMasterFolder(): void
    {
        $this->run_export();
        $this->assertDirectoryExists($this->master_dir());

        wp_delete_post($this->master_id, true);
        $this->run_export();

        $this->assertDirectoryDoesNotExist($this->master_dir());
        $this->assertFileExists($this->export_dir.'/_index.md');
    }

    public function testExportPurgesOldSlugFolder(): void
    {
        $old_dir = $this->export_dir.'/page-'.$this->master_id.'-a-propos';
        mkdir($old_dir, 0755, true);
        file_put_contents($old_dir.'/fr_FR.html', '<p>stale</p>');

        $this->run_export();

        $this->assertDirectoryDoesNotExist($old_dir);
        $this->assertDirectoryExists($this->master_dir());
    }

    public function testExportPurgesEmptiedMasterFolder(): void
    {
        $this->run_export();
        $this->assertDirectoryExists($this->master_dir());

        wp_update_post(['ID' => $this->master_id, 'post_content' => '']);
        $this->run_export();

        $this->assertDirectoryDoesNotExist($this->master_dir());
    }

    public function testExportPurgeIsScopedToExportedType(): void
    {
        $product_orphan = $this->export_dir.'/product-99999-ghost';
        mkdir($product_orphan, 0755, true);
        file_put_contents($product_orphan.'/fr_FR.html', '<p>ghost</p>');

        $this->run_export(['type' => 'page']);

        $this->assertDirectoryExists($product_orphan, '--type=page must not purge product folders');
    }

    // ===== Import (flat-driven) =====

    public function testImportCreatesShadowFromFlatFile(): void
    {
        $this->run_export();
        $this->put_flat('en_IE', [
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'title' => 'About Us', 'slug' => 'about-us',
        ], '<p>Our story</p>');

        $this->run_import();

        $sid = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNotNull($sid);
        $shadow = get_post($sid);
        $this->assertSame('About Us', $shadow->post_title);
        $this->assertSame('about-us', $shadow->post_name);
        $this->assertSame('<p>Our story</p>', $shadow->post_content, 'Frontmatter must be stripped from content');
        $this->assertSame('en_IE', get_post_meta($sid, '_locale', true));
    }

    public function testImportUpdatesExistingShadow(): void
    {
        $sid = $this->create_shadow_via_import();
        $this->recursive_rmdir($this->export_dir);
        $this->run_export();

        $this->set_flat_field('en_IE', ['title' => 'About Us V2']);
        $this->run_import();

        $this->assertSame('About Us V2', get_post($sid)->post_title);
    }

    public function testImportRespectsHumanLock(): void
    {
        $sid = $this->create_shadow_via_import();
        update_post_meta($sid, '_translation_mode', 'manual');
        $this->recursive_rmdir($this->export_dir);
        $this->run_export();

        $this->set_flat_field('en_IE', ['title' => 'Should Not Update']);
        $this->run_import();

        $this->assertSame('About Us', get_post($sid)->post_title, 'A manual shadow must not be bulk-overwritten');
    }

    public function testImportForceOverridesHumanLock(): void
    {
        $sid = $this->create_shadow_via_import();
        update_post_meta($sid, '_translation_mode', 'manual');
        $this->recursive_rmdir($this->export_dir);
        $this->run_export();

        $this->set_flat_field('en_IE', ['title' => 'Forced Update']);
        $this->run_import(['force' => true]);

        $this->assertSame('Forced Update', get_post($sid)->post_title);
    }

    public function testImportSkipsStaleConflictUnlessForced(): void
    {
        $sid = $this->create_shadow_via_import();
        $this->recursive_rmdir($this->export_dir);
        $this->run_export(); // en_IE.html etag matches DB

        // DB moves on → the flat file's etag is now stale.
        wp_update_post(['ID' => $sid, 'post_content' => '<p>DB changed</p>']);
        $this->set_flat_field('en_IE', ['title' => 'Hijack']);

        $this->run_import();
        $this->assertSame('<p>DB changed</p>', get_post($sid)->post_content, 'Stale import must not clobber');
        $this->assertNotSame('Hijack', get_post($sid)->post_title);

        $this->run_import(['force' => true]);
        $this->assertSame('Hijack', get_post($sid)->post_title, '--force overrides the lock');
    }

    public function testImportAppliesTermFromFlatFile(): void
    {
        register_taxonomy('product_cat', 'post');
        $r = wp_insert_term('Chaussures', 'product_cat', ['slug' => 'chaussures']);
        $master_tid = $r['term_id'];

        $folder = $this->export_dir."/term-product_cat-$master_tid";
        mkdir($folder, 0755, true);
        file_put_contents($folder.'/en_IE.html', polyglot_flat_serialize([
            'master_term_id' => $master_tid, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => 'new', 'name' => 'Shoes', 'slug' => 'shoes',
        ], ''));

        $this->run_import();

        $stid = $this->find_shadow_term($master_tid, 'en_IE');
        $this->assertNotNull($stid);
        $this->assertSame('Shoes', get_term($stid, 'product_cat')->name);
        $this->assertSame((string) $master_tid, get_term_meta($stid, '_master_term_id', true));
    }

    public function testRoundtripPreservesHtmlContent(): void
    {
        $html = "<h2>Heading</h2>\n<p>Paragraph with <strong>bold</strong></p>\n<ul>\n<li>Item</li>\n</ul>";

        $this->run_export();
        $this->put_flat('en_IE', [
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'title' => 'About Us', 'slug' => 'about-us',
        ], $html);
        $this->run_import();

        $this->recursive_rmdir($this->export_dir);
        $this->run_export();

        $exported = file_get_contents($this->master_dir().'/en_IE.html');
        $this->assertSame($html, polyglot_flat_body($exported));
    }

    public function testImportMirrorsDraftStatusToShadow(): void
    {
        wp_update_post(['ID' => $this->master_id, 'post_status' => 'draft']);
        $this->run_export();
        $this->put_flat('en_IE', [
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'title' => 'About Us', 'slug' => 'about-us',
        ], '<p>Our story</p>');

        $this->run_import();

        $sid = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNotNull($sid);
        $this->assertSame('draft', get_post($sid)->post_status, 'Shadow of a draft master must stay draft');
    }

    // === Helpers ===

    private function master_dir(): string
    {
        return $this->export_dir.'/page-'.$this->master_id;
    }

    private function frontmatter(string $raw): array
    {
        return polyglot_flat_parse($raw)[0];
    }

    /** Create an en_IE shadow by writing a flat file and importing it. */
    private function create_shadow_via_import(): int
    {
        $this->run_export();
        $this->put_flat('en_IE', [
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'title' => 'About Us', 'slug' => 'about-us',
        ], '<p>Our story</p>');
        $this->run_import();

        return (int) $this->find_shadow($this->master_id, 'en_IE');
    }

    /** Write a frontmatter+body flat file into the master's folder. */
    private function put_flat(string $locale, array $front, string $body): void
    {
        if (! is_dir($this->master_dir())) {
            mkdir($this->master_dir(), 0755, true);
        }
        file_put_contents($this->master_dir()."/$locale.html", polyglot_flat_serialize($front, $body));
    }

    /** Edit a flat file's frontmatter field(s) in place, preserving the etag. */
    private function set_flat_field(string $locale, array $fields): void
    {
        $file = $this->master_dir()."/$locale.html";
        [$fm, $body] = polyglot_flat_parse(file_get_contents($file));
        file_put_contents($file, polyglot_flat_serialize(array_merge($fm, $fields), $body));
    }

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

    private function find_shadow_term(int $master_term_id, string $locale): ?int
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT tm1.term_id FROM $wpdb->termmeta tm1
             JOIN $wpdb->termmeta tm2 ON tm1.term_id = tm2.term_id AND tm2.meta_key = '_locale'
             WHERE tm1.meta_key = '_master_term_id' AND tm1.meta_value = %d
             AND tm2.meta_value = %s LIMIT 1",
            $master_term_id,
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

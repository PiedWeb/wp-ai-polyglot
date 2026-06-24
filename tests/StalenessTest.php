<?php

/**
 * @group write
 *
 * Covers the staleness hash: a shadow captures its master's translatable-text
 * hash (_src_hash) at write time; a later master *content* change makes it
 * stale, while a master *slug-only* change does not (decision #5).
 */
class StalenessTest extends WP_UnitTestCase
{
    private string $export_dir;

    private int $master_id;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        $this->export_dir = polyglot_translations_dir();
        $this->cli = new Polyglot_CLI();
        if (! is_dir($this->export_dir)) {
            mkdir($this->export_dir, 0755, true);
        }

        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_name' => 'a-propos',
            'post_content' => '<p>Notre histoire</p>',
            'post_status' => 'publish',
        ]);

        // Create an en_IE shadow via write (this captures _src_hash).
        $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'slug' => 'about-us', 'title' => 'About Us',
        ], '<p>Our story</p>');
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    public function testShadowCapturesMasterHashOnWrite(): void
    {
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');

        $this->assertSame(
            polyglot_content_hash(get_post($this->master_id)),
            get_post_meta($shadow_id, '_src_hash', true),
            'A freshly written shadow must be up to date'
        );
    }

    public function testMasterContentChangeMakesShadowStale(): void
    {
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $captured = get_post_meta($shadow_id, '_src_hash', true);

        // Change master *content* via write.
        $etag = polyglot_post_etag(get_post($this->master_id));
        $this->write([
            'id' => $this->master_id, 'locale' => 'fr_FR', 'etag' => $etag,
            'slug' => 'a-propos', 'title' => 'À propos',
        ], '<p>Notre histoire RÉVISÉE</p>');

        // The shadow's captured hash is untouched, but the master moved on.
        $this->assertSame($captured, get_post_meta($shadow_id, '_src_hash', true), 'A master write must not touch shadow meta');
        $this->assertNotSame(
            polyglot_content_hash(get_post($this->master_id)),
            get_post_meta($shadow_id, '_src_hash', true),
            'Shadow must be stale after a master content change'
        );
    }

    public function testMasterSlugOnlyChangeDoesNotMakeShadowStale(): void
    {
        $etag = polyglot_post_etag(get_post($this->master_id));
        // Same title + content, different slug only.
        $this->write([
            'id' => $this->master_id, 'locale' => 'fr_FR', 'etag' => $etag,
            'slug' => 'a-propos-v2', 'title' => 'À propos',
        ], '<p>Notre histoire</p>');

        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertSame('a-propos-v2', get_post_meta($this->master_id, 'custom_permalink', true), 'slug must have changed');
        $this->assertSame(
            polyglot_content_hash(get_post($this->master_id)),
            get_post_meta($shadow_id, '_src_hash', true),
            'A slug-only master change must NOT flag shadows stale'
        );
    }

    public function testIndexMdStaleColumnTracksMasterChanges(): void
    {
        $this->cli->export([], []);
        $this->assertSame('—', $this->index_stale(), 'Up-to-date shadow → no stale marker');

        // Change master content, re-export.
        $etag = polyglot_post_etag(get_post($this->master_id));
        $this->write([
            'id' => $this->master_id, 'locale' => 'fr_FR', 'etag' => $etag,
            'slug' => 'a-propos', 'title' => 'À propos',
        ], '<p>Notre histoire v3</p>');
        $this->cli->export([], []);

        $this->assertSame('1', $this->index_stale(), '_index.md must flag the stale shadow');
    }

    public function testExportBackfillsMissingSrcHash(): void
    {
        // Legacy/migration: a shadow with no baseline must be assumed current as
        // of the next export, not flagged stale forever.
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        delete_post_meta($shadow_id, '_src_hash');
        $this->assertSame('', get_post_meta($shadow_id, '_src_hash', true));

        $this->cli->export([], []);

        $this->assertSame(
            polyglot_content_hash(get_post($this->master_id)),
            get_post_meta($shadow_id, '_src_hash', true),
            'Export must backfill a missing src_hash to the current master hash'
        );
        $this->assertSame('—', $this->index_stale(), 'A backfilled shadow must not be flagged stale');
    }

    // === Helpers ===

    private function index_stale(): string
    {
        foreach (file($this->export_dir.'/_index.md') ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, "| {$this->master_id} |") && preg_match('/\|\s*([^|]+?)\s*\|\s*$/u', $line, $m)) {
                return $m[1];
            }
        }

        return '?';
    }

    private function write(array $front, string $body): array
    {
        $tmp = $this->export_dir.'/_payload-'.substr(md5($body.serialize($front)), 0, 8).'.html';
        file_put_contents($tmp, polyglot_flat_serialize($front, $body));

        return $this->cli->write([], ['file' => $tmp]);
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

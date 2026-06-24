<?php

/**
 * @group write
 *
 * Covers the term branch of `wp polyglot write` (Option B: terms join the
 * frontmatter/optimistic-lock model). The flat body carries the description.
 */
class TermWriteTest extends WP_UnitTestCase
{
    private string $export_dir;

    private int $master_term_id;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();
        register_taxonomy('product_cat', 'post');

        $this->export_dir = polyglot_translations_dir();
        if (! is_dir($this->export_dir)) {
            mkdir($this->export_dir, 0755, true);
        }
        $this->cli = new Polyglot_CLI();

        $r = wp_insert_term('Chaussures', 'product_cat', ['slug' => 'chaussures', 'description' => 'FR desc']);
        $this->master_term_id = $r['term_id'];
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    public function testCreateShadowTerm(): void
    {
        $res = $this->write([
            'master_term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => 'new', 'name' => 'Shoes', 'slug' => 'shoes',
        ], 'EN desc');

        $this->assertSame(201, $res['status']);
        $sid = $this->find_shadow_term($this->master_term_id, 'en_IE');
        $this->assertNotNull($sid);

        $term = get_term($sid, 'product_cat');
        $this->assertSame('Shoes', $term->name);
        $this->assertSame('shoes', $term->slug);
        $this->assertSame('EN desc', $term->description);
        $this->assertSame((string) $this->master_term_id, get_term_meta($sid, '_master_term_id', true));
        $this->assertSame('en_IE', get_term_meta($sid, '_locale', true));
        $this->assertStringEndsWith("/term-product_cat-{$this->master_term_id}/en_IE.html", $res['path']);
    }

    public function testUpdateShadowTermUnderLock(): void
    {
        $this->write([
            'master_term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => 'new', 'name' => 'Shoes', 'slug' => 'shoes',
        ], 'EN desc');
        $sid = $this->find_shadow_term($this->master_term_id, 'en_IE');
        $etag = polyglot_term_etag(get_term($sid, 'product_cat'));

        $res = $this->write([
            'term_id' => $sid, 'master_term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => $etag, 'name' => 'Shoes v2', 'slug' => 'shoes',
        ], 'EN desc v2');

        $this->assertSame(200, $res['status']);
        $this->assertSame('Shoes v2', get_term($sid, 'product_cat')->name);
        $this->assertSame('EN desc v2', get_term($sid, 'product_cat')->description);
    }

    public function test409OnStaleTermEtag(): void
    {
        $this->write([
            'master_term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => 'new', 'name' => 'Shoes', 'slug' => 'shoes',
        ], 'EN desc');

        $res = $this->write([
            'master_term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'en_IE', 'etag' => 'staleetag', 'name' => 'Should Not Apply', 'slug' => 'shoes',
        ], '<p>nope</p>');

        $this->assertSame(409, $res['status']);
        $sid = $this->find_shadow_term($this->master_term_id, 'en_IE');
        $this->assertSame('Shoes', get_term($sid, 'product_cat')->name, 'Stale term write must not apply');
    }

    public function testMasterTermUpdate(): void
    {
        $etag = polyglot_term_etag(get_term($this->master_term_id, 'product_cat'));

        $res = $this->write([
            'term_id' => $this->master_term_id, 'taxonomy' => 'product_cat',
            'locale' => 'fr_FR', 'etag' => $etag, 'name' => 'Chaussures v2', 'slug' => 'chaussures',
        ], 'FR desc v2');

        $this->assertSame(200, $res['status']);
        $term = get_term($this->master_term_id, 'product_cat');
        $this->assertSame('Chaussures v2', $term->name);
        $this->assertSame('FR desc v2', $term->description);
    }

    // === Helpers ===

    private function write(array $front, string $body): array
    {
        $tmp = $this->export_dir.'/_t-'.substr(md5($body.serialize($front)), 0, 8).'.html';
        file_put_contents($tmp, polyglot_flat_serialize($front, $body));

        return $this->cli->write([], ['file' => $tmp]);
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

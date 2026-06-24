<?php

/**
 * @group write
 *
 * Covers `wp polyglot write` — the optimistic-locked, single-file write path.
 */
class WriteTest extends WP_UnitTestCase
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
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    public function testWriteUpdatesMasterWithMatchingEtag(): void
    {
        $etag = polyglot_post_etag(get_post($this->master_id));

        $res = $this->write([
            'id' => $this->master_id, 'locale' => 'fr_FR', 'etag' => $etag,
            'slug' => 'a-propos', 'title' => 'À propos v2',
        ], '<p>Nouvelle histoire</p>');

        $this->assertSame(200, $res['status']);
        $post = get_post($this->master_id);
        $this->assertSame('À propos v2', $post->post_title);
        $this->assertSame('<p>Nouvelle histoire</p>', $post->post_content);
        $this->assertNotSame($etag, $res['etag'], 'etag must bump after a successful write');
        $this->assertSame(polyglot_post_etag($post), $res['etag']);
    }

    public function testWrite409OnStaleEtagLeavesDbUntouched(): void
    {
        $res = $this->write([
            'id' => $this->master_id, 'locale' => 'fr_FR', 'etag' => 'staleeeetag',
            'slug' => 'a-propos', 'title' => 'Should not apply',
        ], '<p>nope</p>');

        $this->assertSame(409, $res['status']);
        $post = get_post($this->master_id);
        $this->assertSame('À propos', $post->post_title, 'A stale write must not touch the DB');
        $this->assertSame('<p>Notre histoire</p>', $post->post_content);

        // 409 returns the current server state so the agent can re-apply.
        $this->assertSame(polyglot_post_etag($post), $res['etag']);
        $this->assertSame('<p>Notre histoire</p>', polyglot_flat_body($res['content']));
    }

    public function testWriteCreatesShadowWithoutCrossLocaleSlugSuffix(): void
    {
        // Same slug as the master: must NOT be suffixed "-2" (pending_locale).
        $res = $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'slug' => 'a-propos', 'title' => 'About Us',
        ], '<p>Our story</p>');

        $this->assertSame(201, $res['status']);
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertNotNull($shadow_id);

        $shadow = get_post($shadow_id);
        $this->assertSame('a-propos', $shadow->post_name, 'Cross-locale identical slug must not be suffixed');
        $this->assertSame('en_IE', get_post_meta($shadow_id, '_locale', true));
        $this->assertSame((string) $this->master_id, get_post_meta($shadow_id, '_master_id', true));
        $this->assertSame('<p>Our story</p>', $shadow->post_content);
        // Shadow file lives in the MASTER's folder.
        $this->assertStringEndsWith("/page-{$this->master_id}/en_IE.html", $res['path']);
    }

    public function testWriteUpdatesExistingShadowUnderLock(): void
    {
        $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'slug' => 'about-us', 'title' => 'About Us',
        ], '<p>Our story</p>');
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $etag = polyglot_post_etag(get_post($shadow_id));

        $res = $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => $etag,
            'slug' => 'about-us', 'title' => 'About Us v2',
        ], '<p>Our story v2</p>');

        $this->assertSame(200, $res['status']);
        $this->assertSame('About Us v2', get_post($shadow_id)->post_title);
        $this->assertSame('<p>Our story v2</p>', get_post($shadow_id)->post_content);
    }

    public function testWriteStampsManualModeAndClearsAuto(): void
    {
        $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => 'new',
            'slug' => 'about-us', 'title' => 'About', 'mode' => 'manual',
        ], '<p>x</p>');
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $this->assertSame('manual', get_post_meta($shadow_id, '_translation_mode', true), 'mode:manual must stamp the Human Lock');

        // A subsequent mode:auto write clears the lock (break-glass can touch it again).
        $etag = polyglot_post_etag(get_post($shadow_id));
        $this->write([
            'master_id' => $this->master_id, 'locale' => 'en_IE', 'etag' => $etag,
            'slug' => 'about-us', 'title' => 'About', 'mode' => 'auto',
        ], '<p>y</p>');
        $this->assertSame('', get_post_meta($shadow_id, '_translation_mode', true), 'mode:auto must clear the Human Lock');
    }

    public function testWriteCreatesMasterViaNewHandshake(): void
    {
        $res = $this->write([
            'locale' => 'fr_FR', 'etag' => 'new', 'type' => 'page',
            'slug' => 'nouvelle-page', 'title' => 'Nouvelle page', 'status' => 'publish',
        ], '<p>contenu</p>');

        $this->assertSame(201, $res['status']);
        $this->assertMatchesRegularExpression('#/page-\d+/fr_FR\.html$#', $res['path']);

        preg_match('#/page-(\d+)/#', $res['path'], $m);
        $id = (int) $m[1];
        $post = get_post($id);
        $this->assertInstanceOf(WP_Post::class, $post);
        $this->assertSame('Nouvelle page', $post->post_title);
        $this->assertSame('page', $post->post_type);
        $this->assertSame('publish', $post->post_status);
        $this->assertSame('', get_post_meta($id, '_master_id', true), 'A new master must not carry _master_id');
    }

    public function testWriteCreateMasterIsIdempotentOnSlug(): void
    {
        $res1 = $this->write([
            'locale' => 'fr_FR', 'etag' => 'new', 'type' => 'page',
            'slug' => 'dup-page', 'title' => 'Dup', 'status' => 'publish',
        ], '<p>a</p>');
        $res2 = $this->write([
            'locale' => 'fr_FR', 'etag' => 'new', 'type' => 'page',
            'slug' => 'dup-page', 'title' => 'Dup again', 'status' => 'publish',
        ], '<p>b</p>');

        preg_match('#/page-(\d+)/#', $res1['path'], $m1);
        preg_match('#/page-(\d+)/#', $res2['path'], $m2);
        $this->assertSame($m1[1], $m2[1], 'A re-run on a leftover _new stub must resolve to the same master, not duplicate it');
    }

    // === Helpers ===

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

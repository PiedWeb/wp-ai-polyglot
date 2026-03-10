<?php

/**
 * @group sitemap
 */
class SitemapTest extends WP_UnitTestCase
{
    private int $master_id;

    private int $shadow_en_id;

    public function set_up(): void
    {
        parent::set_up();

        $this->master_id = self::factory()->post->create([
            'post_type' => 'post',
            'post_title' => 'Article FR',
            'post_status' => 'publish',
        ]);

        $this->shadow_en_id = self::factory()->post->create([
            'post_type' => 'post',
            'post_title' => 'Article EN',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_en_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_en_id, '_locale', 'en_IE');
    }

    public function testSitemapPostsFilterMasterDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $args = polyglot_sitemap_posts_filter([]);

        $this->assertArrayHasKey('meta_query', $args);
        $this->assertSame('_master_id', $args['meta_query'][0]['key']);
        $this->assertSame('NOT EXISTS', $args['meta_query'][0]['compare']);
    }

    public function testSitemapPostsFilterShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $args = polyglot_sitemap_posts_filter([]);

        $this->assertArrayHasKey('meta_query', $args);
        $this->assertSame('_locale', $args['meta_query'][0]['key']);
        $this->assertSame('en_IE', $args['meta_query'][0]['value']);
    }

    public function testSitemapPostsFilterPreservesExistingMetaQuery(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $existing = [['key' => '_price', 'compare' => 'EXISTS']];
        $args = polyglot_sitemap_posts_filter(['meta_query' => $existing]);

        $this->assertCount(2, $args['meta_query']);
        $this->assertSame('_price', $args['meta_query'][0]['key']);
        $this->assertSame('_master_id', $args['meta_query'][1]['key']);
    }

    public function testSitemapTaxonomiesFilterMasterDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $args = polyglot_sitemap_taxonomies_filter([]);

        $this->assertArrayHasKey('meta_query', $args);
        $this->assertSame('OR', $args['meta_query'][0]['relation']);
        $this->assertSame('_master_term_id', $args['meta_query'][0][0]['key']);
        $this->assertSame('NOT EXISTS', $args['meta_query'][0][0]['compare']);
    }

    public function testSitemapTaxonomiesFilterShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $args = polyglot_sitemap_taxonomies_filter([]);

        $this->assertArrayHasKey('meta_query', $args);
        $this->assertSame('_locale', $args['meta_query'][0]['key']);
        $this->assertSame('en_IE', $args['meta_query'][0]['value']);
    }

    public function testSitemapTrackEntry(): void
    {
        global $polyglot_sitemap_url_map;
        $polyglot_sitemap_url_map = null;

        $post = get_post($this->master_id);
        $entry = ['loc' => 'http://master.test/?p='.$this->master_id];

        $result = polyglot_sitemap_track_entry($entry, $post, 'post');

        $this->assertSame($entry, $result);
        $this->assertSame($this->master_id, $polyglot_sitemap_url_map[$entry['loc']]);
    }

    public function testSitemapHreflangObCallbackEmptyMap(): void
    {
        global $polyglot_sitemap_url_map;
        $polyglot_sitemap_url_map = [];

        $xml = '<urlset><url><loc>http://example.com/</loc></url></urlset>';
        $result = polyglot_sitemap_hreflang_ob_callback($xml);

        $this->assertSame($xml, $result);
    }

    public function testSitemapHreflangObCallbackNonUrlset(): void
    {
        global $polyglot_sitemap_url_map;
        $polyglot_sitemap_url_map = ['http://example.com/' => 1];

        $xml = '<sitemapindex><sitemap><loc>http://example.com/</loc></sitemap></sitemapindex>';
        $result = polyglot_sitemap_hreflang_ob_callback($xml);

        $this->assertSame($xml, $result);
    }

    public function testSitemapHreflangObCallbackInjectsHreflang(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        global $polyglot_sitemap_url_map;

        $loc = get_permalink($this->master_id);
        $polyglot_sitemap_url_map = [$loc => $this->master_id];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>'.$loc.'</loc></url>'
            .'</urlset>';

        $result = polyglot_sitemap_hreflang_ob_callback($xml);

        $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $result);
        $this->assertStringContainsString('hreflang="fr"', $result);
        $this->assertStringContainsString('hreflang="en"', $result);
        $this->assertStringNotContainsString('x-default', $result);
    }

    public function testSitemapHreflangObCallbackNoHreflangWithoutShadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        global $polyglot_sitemap_url_map;

        $standalone = self::factory()->post->create([
            'post_type' => 'post',
            'post_title' => 'Solo',
            'post_status' => 'publish',
        ]);

        $loc = get_permalink($standalone);
        $polyglot_sitemap_url_map = [$loc => $standalone];

        $xml = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>'.$loc.'</loc></url>'
            .'</urlset>';

        $result = polyglot_sitemap_hreflang_ob_callback($xml);

        // No shadow = no hreflang injection, urlset should stay untouched
        $this->assertStringNotContainsString('xhtml:link', $result);
    }
}

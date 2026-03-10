<?php

/**
 * @group feed
 */
class FeedTest extends WP_UnitTestCase
{
    private int $master_id;

    private int $shadow_en_id;

    private int $shadow_es_id;

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

        $this->shadow_es_id = self::factory()->post->create([
            'post_type' => 'post',
            'post_title' => 'Article ES',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_es_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_es_id, '_locale', 'es_ES');
    }

    public function testFilterSetsMetaQueryForMasterOnFeed(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        // polyglot_filter_by_domain checks is_main_query(), so simulate main query
        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;

        // Set feed flag (feeds use main query)
        $query->is_feed = true;

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_master_id', $meta_query[0]['key']);
        $this->assertSame('NOT EXISTS', $meta_query[0]['compare']);
    }

    public function testFilterSetsMetaQueryForShadowOnFeed(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;
        $query->is_feed = true;

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_locale', $meta_query[0]['key']);
        $this->assertSame('en_IE', $meta_query[0]['value']);
    }

    public function testMasterDomainQueryExcludesShadows(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                ['key' => '_master_id', 'compare' => 'NOT EXISTS'],
            ],
        ]);

        $this->assertContains($this->master_id, $ids);
        $this->assertNotContains($this->shadow_en_id, $ids);
        $this->assertNotContains($this->shadow_es_id, $ids);
    }

    public function testShadowDomainQueryShowsOnlyMatchingLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'meta_query' => [
                ['key' => '_locale', 'value' => 'en_IE'],
            ],
        ]);

        $this->assertContains($this->shadow_en_id, $ids);
        $this->assertNotContains($this->master_id, $ids);
        $this->assertNotContains($this->shadow_es_id, $ids);
    }
}

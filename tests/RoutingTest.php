<?php

/**
 * @group routing
 */
class RoutingTest extends WP_UnitTestCase
{
    private int $master_id;
    private int $shadow_id;

    public function set_up(): void
    {
        parent::set_up();

        $this->master_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'À propos',
            'post_status' => 'publish',
        ]);

        $this->shadow_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'About Us',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_id, '_locale', 'en_IE');
    }

    public function test_master_domain_meta_query(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $this->assertTrue(polyglot_is_master());

        $ids = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => '_master_id', 'compare' => 'NOT EXISTS'],
            ],
        ]);

        $this->assertContains($this->master_id, $ids);
        $this->assertNotContains($this->shadow_id, $ids);
    }

    public function test_shadow_domain_meta_query(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';
        $this->assertFalse(polyglot_is_master());

        $ids = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => '_locale', 'value' => 'en_IE'],
            ],
        ]);

        $this->assertContains($this->shadow_id, $ids);
        $this->assertNotContains($this->master_id, $ids);
    }

    public function test_unrelated_shadow_domain_shows_nothing(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';

        $ids = get_posts([
            'post_type'   => 'page',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'meta_query'  => [
                ['key' => '_locale', 'value' => 'es_ES'],
            ],
        ]);

        $this->assertEmpty($ids);
    }

    public function test_filter_hook_sets_meta_query_for_master(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query = new WP_Query();
        // Force it to behave as main query
        $GLOBALS['wp_the_query'] = $query;

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_master_id', $meta_query[0]['key']);
        $this->assertSame('NOT EXISTS', $meta_query[0]['compare']);
    }

    public function test_filter_hook_sets_meta_query_for_shadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query;

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_locale', $meta_query[0]['key']);
        $this->assertSame('en_IE', $meta_query[0]['value']);
    }

    public function test_page_on_front_returns_shadow_id_on_shadow_domain(): void
    {
        update_option('page_on_front', $this->master_id);
        update_option('show_on_front', 'page');

        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertSame($this->shadow_id, (int) get_option('page_on_front'));
    }

    public function test_page_on_front_returns_master_id_on_master_domain(): void
    {
        update_option('page_on_front', $this->master_id);
        update_option('show_on_front', 'page');

        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertSame($this->master_id, (int) get_option('page_on_front'));
    }

    public function test_page_on_front_returns_master_id_when_no_shadow_exists(): void
    {
        update_option('page_on_front', $this->master_id);
        update_option('show_on_front', 'page');

        $_SERVER['HTTP_HOST'] = 'es.test';

        // No es_ES shadow exists, should fall through to real option
        $this->assertSame($this->master_id, (int) get_option('page_on_front'));
    }

    public function test_page_on_front_returns_zero_when_unset(): void
    {
        update_option('page_on_front', 0);

        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertSame(0, (int) get_option('page_on_front'));
    }
}

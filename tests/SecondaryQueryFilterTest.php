<?php

/**
 * Guards the secondary-query branch of polyglot_filter_by_domain()
 * (inc/routing.php).
 *
 * The domain filter must constrain any secondary query that targets a managed
 * (translatable) post type — INCLUDING mixed requests that also ask for an
 * unmanaged type such as 'product_variation' (WooCommerce catalog/feed
 * exporters do exactly this). Queries that touch no managed type at all must be
 * left untouched so unrelated lookups (attachments, orders, standalone
 * variation children) keep working.
 *
 * @group routing
 */
class SecondaryQueryFilterTest extends WP_UnitTestCase
{
    private int $master_id;
    private int $shadow_id;
    private int $variation_id;

    public function set_up(): void
    {
        parent::set_up();

        // Register the WC post types as plain CPTs so the base suite (no WC) can
        // build product / product_variation queries.
        foreach (['product', 'product_variation'] as $type) {
            if (! post_type_exists($type)) {
                register_post_type($type, ['public' => true]);
            }
        }

        $this->master_id = self::factory()->post->create([
            'post_type'   => 'product',
            'post_title'  => 'Origin',
            'post_status' => 'publish',
        ]);

        $this->shadow_id = self::factory()->post->create([
            'post_type'   => 'product',
            'post_title'  => 'Origin (EN)',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_id, '_locale', 'en_IE');

        // A variation of the master product: no _master_id / _locale of its own.
        $this->variation_id = self::factory()->post->create([
            'post_type'   => 'product_variation',
            'post_parent' => $this->master_id,
            'post_status' => 'publish',
        ]);
    }

    public function tear_down(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        parent::tear_down();
    }

    /** A non-main query that does NOT become the global main query. */
    private function secondaryQuery(array $args): WP_Query
    {
        $query = new WP_Query();
        $query->query_vars = $query->fill_query_vars($args);

        return $query;
    }

    // --- The fix: mixed product+variation queries are now filtered ----------

    public function test_mixed_query_is_filtered_on_master(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query = $this->secondaryQuery(['post_type' => ['product', 'product_variation']]);
        $this->assertFalse($query->is_main_query());

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_master_id', $meta_query[0]['key']);
        $this->assertSame('NOT EXISTS', $meta_query[0]['compare']);
    }

    public function test_mixed_query_keeps_masters_and_variations_drops_shadows(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $ids = get_posts([
            'post_type'        => ['product', 'product_variation'],
            'post_status'      => 'publish',
            'fields'           => 'ids',
            'posts_per_page'   => -1,
            'suppress_filters' => false, // let pre_get_posts run
        ]);

        $this->assertContains($this->master_id, $ids, 'master product kept');
        $this->assertContains($this->variation_id, $ids, 'variation of master kept (no _master_id)');
        $this->assertNotContains($this->shadow_id, $ids, 'shadow product excluded');
    }

    public function test_mixed_query_on_shadow_keeps_only_locale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $query = $this->secondaryQuery(['post_type' => ['product', 'product_variation']]);
        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertSame('_locale', $meta_query[0]['key']);
        $this->assertSame('en_IE', $meta_query[0]['value']);
    }

    // --- Still untouched: queries with no managed type ----------------------

    public function test_standalone_variation_lookup_is_untouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query = $this->secondaryQuery(['post_type' => 'product_variation']);
        polyglot_filter_by_domain($query);

        $this->assertEmpty($query->get('meta_query'), 'pure variation query must not be domain-filtered');
    }

    public function test_unmanaged_type_query_is_untouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $query = $this->secondaryQuery(['post_type' => ['attachment', 'product_variation']]);
        polyglot_filter_by_domain($query);

        $this->assertEmpty($query->get('meta_query'));
    }

    public function test_empty_post_type_secondary_query_is_untouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query = $this->secondaryQuery(['post_type' => '']);
        polyglot_filter_by_domain($query);

        $this->assertEmpty($query->get('meta_query'));
    }

    // --- Regression: single managed type and main query still filter --------

    public function test_single_managed_type_secondary_query_still_filters(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query = $this->secondaryQuery(['post_type' => 'product']);
        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertSame('_master_id', $meta_query[0]['key']);
    }

    public function test_main_query_is_still_filtered(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $query = new WP_Query();
        $GLOBALS['wp_the_query'] = $query; // force is_main_query() === true
        $query->query_vars = $query->fill_query_vars(['post_type' => 'product']);

        polyglot_filter_by_domain($query);

        $meta_query = $query->get('meta_query');
        $this->assertSame('_locale', $meta_query[0]['key']);
        $this->assertSame('en_IE', $meta_query[0]['value']);
    }
}

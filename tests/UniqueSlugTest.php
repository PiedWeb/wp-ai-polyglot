<?php

/**
 * @group slug
 */
class UniqueSlugTest extends WP_UnitTestCase
{
    private int $master_id;

    public function set_up(): void
    {
        parent::set_up();

        // Create a master page with a known slug
        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
    }

    /**
     * Shadow in a different locale can reuse the master's slug.
     */
    public function testShadowCanHaveSameSlugAsMaster(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);

        update_post_meta($shadow_id, '_master_id', $this->master_id);
        update_post_meta($shadow_id, '_locale', 'en_IE');

        $shadow = get_post($shadow_id);
        $this->assertSame('test-page', $shadow->post_name);
    }

    /**
     * Two shadows in different locales can share the same slug.
     */
    public function testTwoShadowsDifferentLocalesSameSlug(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow_en = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow_en, '_master_id', $this->master_id);
        update_post_meta($shadow_en, '_locale', 'en_IE');

        $GLOBALS['polyglot_pending_locale'] = 'es_ES';
        $shadow_es = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page ES',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow_es, '_master_id', $this->master_id);
        update_post_meta($shadow_es, '_locale', 'es_ES');

        $this->assertSame('test-page', get_post($shadow_en)->post_name);
        $this->assertSame('test-page', get_post($shadow_es)->post_name);
    }

    /**
     * Two shadows in the SAME locale cannot share the same slug — WP suffixing applies.
     */
    public function testSameLocaleSlugConflictGetsSuffixed(): void
    {
        // First shadow
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow1 = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow1, '_master_id', $this->master_id);
        update_post_meta($shadow1, '_locale', 'en_IE');

        // Second shadow, same locale — create a different master first
        $master2 = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Another Master',
            'post_name' => 'another-master',
            'post_status' => 'publish',
        ]);

        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow2 = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN 2',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow2, '_master_id', $master2);
        update_post_meta($shadow2, '_locale', 'en_IE');

        $this->assertSame('test-page', get_post($shadow1)->post_name);
        $this->assertNotSame('test-page', get_post($shadow2)->post_name, 'Same-locale conflict should be suffixed');
    }

    /**
     * Non-polyglot post types are not affected by the filter.
     */
    public function testNonPolyglotPostTypeNotAffected(): void
    {
        register_post_type('custom_type', ['public' => true]);

        $post1 = self::factory()->post->create([
            'post_type' => 'custom_type',
            'post_name' => 'duplicate',
            'post_status' => 'publish',
        ]);

        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $post2 = self::factory()->post->create([
            'post_type' => 'custom_type',
            'post_name' => 'duplicate',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);

        unregister_post_type('custom_type');

        // WP should suffix normally since custom_type is not in polyglot_get_post_types()
        $this->assertNotSame(
            get_post($post1)->post_name,
            get_post($post2)->post_name,
            'Non-polyglot post types should still get WP suffixing'
        );
    }

    /**
     * Updating a shadow preserves its slug even if it matches the master.
     */
    public function testUpdateShadowKeepsSameSlug(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow_id, '_master_id', $this->master_id);
        update_post_meta($shadow_id, '_locale', 'en_IE');

        // Update the shadow (locale meta already exists, no global needed)
        wp_update_post([
            'ID' => $shadow_id,
            'post_title' => 'Updated Test Page EN',
            'post_name' => 'test-page',
        ]);

        $this->assertSame('test-page', get_post($shadow_id)->post_name);
    }

    /**
     * The request filter resolves the correct post by slug on the shadow domain.
     */
    public function testRequestFilterResolvesShadowBySlug(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow_en = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Test Page EN',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow_en, '_master_id', $this->master_id);
        update_post_meta($shadow_en, '_locale', 'en_IE');

        $_SERVER['HTTP_HOST'] = 'en.test';

        $query_vars = apply_filters('request', ['pagename' => 'test-page']);

        $this->assertSame($shadow_en, $query_vars['page_id']);
    }

    /**
     * The request filter resolves master post by slug on master domain.
     */
    public function testRequestFilterResolvesMasterBySlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query_vars = apply_filters('request', ['pagename' => 'test-page']);

        $this->assertSame($this->master_id, $query_vars['page_id']);
    }

    /**
     * The request filter passes through when page_id is already set.
     */
    public function testRequestFilterPassthroughWithPageId(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $query_vars = apply_filters('request', ['page_id' => $this->master_id]);

        $this->assertSame($this->master_id, $query_vars['page_id']);
    }

    /**
     * Without the pending locale global, a new post with no meta falls through to WP default.
     */
    public function testNoPendingLocaleFallsThrough(): void
    {
        // No global, no _locale meta — filter returns null, WP handles normally
        $post = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'test-page',
            'post_status' => 'publish',
        ]);

        // WP should suffix since this is a duplicate with no locale context
        $this->assertNotSame('test-page', get_post($post)->post_name);
    }
}

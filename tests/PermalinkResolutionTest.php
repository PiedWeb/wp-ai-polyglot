<?php

/**
 * @group permalink
 */
class PermalinkResolutionTest extends WP_UnitTestCase
{
    private int $parent_page;    // accueil (parent=0)

    private int $child_page;     // blog (parent=accueil, custom_permalink=blog)

    private int $root_page;      // contact (parent=0, no custom_permalink)

    private int $product;        // origin (product, custom_permalink=origin)

    // EN shadows
    private int $child_shadow_en;

    private int $root_shadow_en;

    private int $product_shadow_en;

    // ES shadows (same slug as EN for blog/contact)
    private int $child_shadow_es;

    private int $root_shadow_es;

    public function set_up(): void
    {
        parent::set_up();

        // Master pages
        $this->parent_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Accueil',
            'post_name' => 'accueil',
            'post_status' => 'publish',
        ]);

        $this->child_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Blog',
            'post_name' => 'blog',
            'post_parent' => $this->parent_page,
            'post_status' => 'publish',
        ]);
        update_post_meta($this->child_page, 'custom_permalink', 'blog');

        $this->root_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contact',
            'post_name' => 'contact',
            'post_status' => 'publish',
        ]);

        // Master product
        register_post_type('product', ['public' => true]);
        $this->product = self::factory()->post->create([
            'post_type' => 'product',
            'post_title' => 'Origin',
            'post_name' => 'origin',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->product, 'custom_permalink', 'origin');

        // EN shadows
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->child_shadow_en = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Blog EN',
            'post_name' => 'blog',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->child_shadow_en, '_master_id', $this->child_page);
        update_post_meta($this->child_shadow_en, '_locale', 'en_IE');

        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->root_shadow_en = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contact EN',
            'post_name' => 'contact',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->root_shadow_en, '_master_id', $this->root_page);
        update_post_meta($this->root_shadow_en, '_locale', 'en_IE');

        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->product_shadow_en = self::factory()->post->create([
            'post_type' => 'product',
            'post_title' => 'Origin EN',
            'post_name' => 'origin',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->product_shadow_en, '_master_id', $this->product);
        update_post_meta($this->product_shadow_en, '_locale', 'en_IE');

        // ES shadows
        $GLOBALS['polyglot_pending_locale'] = 'es_ES';
        $this->child_shadow_es = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Blog ES',
            'post_name' => 'blog',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->child_shadow_es, '_master_id', $this->child_page);
        update_post_meta($this->child_shadow_es, '_locale', 'es_ES');

        $GLOBALS['polyglot_pending_locale'] = 'es_ES';
        $this->root_shadow_es = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contacto',
            'post_name' => 'contact',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->root_shadow_es, '_master_id', $this->root_page);
        update_post_meta($this->root_shadow_es, '_locale', 'es_ES');
    }

    public function tear_down(): void
    {
        unregister_post_type('product');
        parent::tear_down();
    }

    // ================================================================
    // polyglot_resolve_by_slug() — unit tests
    // ================================================================

    public function testResolveMasterPageByPostName(): void
    {
        $id = polyglot_resolve_by_slug('contact', 'page', 'fr_FR', true);
        $this->assertSame($this->root_page, $id);
    }

    public function testResolveMasterPageByCustomPermalink(): void
    {
        $id = polyglot_resolve_by_slug('blog', 'page', 'fr_FR', true);
        $this->assertSame($this->child_page, $id);
    }

    public function testResolveMasterProductBySlug(): void
    {
        $id = polyglot_resolve_by_slug('origin', 'product', 'fr_FR', true);
        $this->assertSame($this->product, $id);
    }

    public function testResolveShadowPageOnCorrectDomain(): void
    {
        $id = polyglot_resolve_by_slug('blog', 'page', 'en_IE', false);
        $this->assertSame($this->child_shadow_en, $id);
    }

    public function testResolveShadowPageNotFoundOnWrongDomain(): void
    {
        // EN shadow should not be found when querying with es_ES locale
        // (es_ES has its own shadow)
        $id = polyglot_resolve_by_slug('blog', 'page', 'es_ES', false);
        $this->assertSame($this->child_shadow_es, $id);
    }

    public function testResolveShadowWithSameSlugAsMaster(): void
    {
        $id = polyglot_resolve_by_slug('contact', 'page', 'en_IE', false);
        $this->assertSame($this->root_shadow_en, $id);
    }

    public function testResolveShadowProductOnShadowDomain(): void
    {
        $id = polyglot_resolve_by_slug('origin', 'product', 'en_IE', false);
        $this->assertSame($this->product_shadow_en, $id);
    }

    public function testResolveReturnsNullForUnknownSlug(): void
    {
        $id = polyglot_resolve_by_slug('nonexistent', 'page', 'fr_FR', true);
        $this->assertNull($id);
    }

    public function testResolveIgnoresDraftPosts(): void
    {
        $draft = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'draft-page',
            'post_status' => 'draft',
        ]);

        $id = polyglot_resolve_by_slug('draft-page', 'page', 'fr_FR', true);
        $this->assertNull($id);
    }

    public function testResolveIgnoresShadowOnMasterDomain(): void
    {
        // When querying master domain, shadows should NOT be returned
        // even if they match the slug. The resolver should find the master.
        $id = polyglot_resolve_by_slug('contact', 'page', 'fr_FR', true);
        $this->assertSame($this->root_page, $id);
        $this->assertNotSame($this->root_shadow_en, $id);
    }

    // ================================================================
    // request filter — integration tests
    // ================================================================

    public function testRequestFilterResolvesPagenameToPageId(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $result = apply_filters('request', ['pagename' => 'blog']);

        $this->assertSame($this->child_page, $result['page_id']);
    }

    public function testRequestFilterResolvesPagenameOnShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $result = apply_filters('request', ['pagename' => 'blog']);

        $this->assertSame($this->child_shadow_en, $result['page_id']);
    }

    public function testRequestFilterResolvesProductSlugOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $result = apply_filters('request', ['name' => 'origin']);

        $this->assertSame($this->product, $result['p']);
        $this->assertSame('product', $result['post_type']);
    }

    public function testRequestFilterResolvesProductSlugOnShadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $result = apply_filters('request', ['name' => 'origin']);

        $this->assertSame($this->product_shadow_en, $result['p']);
        $this->assertSame('product', $result['post_type']);
    }

    public function testRequestFilterPassthroughWhenPageIdAlreadySet(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $result = apply_filters('request', ['page_id' => 123]);

        $this->assertSame(123, $result['page_id']);
    }

    public function testRequestFilterPassthroughForUnknownSlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $input = ['pagename' => 'nonexistent-slug'];
        $result = apply_filters('request', $input);

        $this->assertSame($input, $result);
    }

    public function testRequestFilterPassthroughInAdmin(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        set_current_screen('edit-post');

        $input = ['pagename' => 'blog'];
        $result = apply_filters('request', $input);

        // Restore
        set_current_screen('front');

        $this->assertSame($input, $result);
    }

    // ================================================================
    // Import resilience
    // ================================================================

    public function testSlugPreservedAfterUpdate(): void
    {
        // Updating a shadow should keep its slug (not suffixed)
        wp_update_post([
            'ID' => $this->child_shadow_en,
            'post_title' => 'Updated Blog EN',
            'post_name' => 'blog',
        ]);

        $this->assertSame('blog', get_post($this->child_shadow_en)->post_name);
    }

    // ================================================================
    // Custom permalink meta box
    // ================================================================

    public function testMetaboxSavesCustomPermalink(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'test-save',
            'post_status' => 'publish',
        ]);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_POST['polyglot_permalink_nonce'] = wp_create_nonce('polyglot_permalink_'.$post_id);
        $_POST['polyglot_custom_permalink'] = 'mon-slug';

        polyglot_save_permalink_metabox($post_id, get_post($post_id));

        $this->assertSame('mon-slug', get_post_meta($post_id, 'custom_permalink', true));
    }

    public function testMetaboxDeletesEmptyCustomPermalink(): void
    {
        $post_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'test-delete',
            'post_status' => 'publish',
        ]);
        update_post_meta($post_id, 'custom_permalink', 'old-value');

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $_POST['polyglot_permalink_nonce'] = wp_create_nonce('polyglot_permalink_'.$post_id);
        $_POST['polyglot_custom_permalink'] = '';

        polyglot_save_permalink_metabox($post_id, get_post($post_id));

        $this->assertEmpty(get_post_meta($post_id, 'custom_permalink', true));
    }

    public function testResolveShadowByCustomPermalink(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'some-internal-name',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->root_page);
        update_post_meta($shadow, '_locale', 'en_IE');
        update_post_meta($shadow, 'custom_permalink', 'my-custom-url');

        $id = polyglot_resolve_by_slug('my-custom-url', 'page', 'en_IE', false);
        $this->assertSame($shadow, $id);
    }

    public function testCustomPermalinkPriorityOverPostName(): void
    {
        // Post has post_name=X but custom_permalink=Y → resolved by Y
        $post = self::factory()->post->create([
            'post_type' => 'page',
            'post_name' => 'internal-name',
            'post_status' => 'publish',
        ]);
        update_post_meta($post, 'custom_permalink', 'public-url');

        // Resolve by custom_permalink should work
        $id = polyglot_resolve_by_slug('public-url', 'page', 'fr_FR', true);
        $this->assertSame($post, $id);

        // Resolve by post_name should NOT work (custom_permalink takes priority,
        // and since custom_permalink IS set, post_name fallback is skipped)
        $id2 = polyglot_resolve_by_slug('internal-name', 'page', 'fr_FR', true);
        $this->assertNull($id2);
    }

    public function testPageLinkUsesCustomPermalinkOnShadow(): void
    {
        update_post_meta($this->child_shadow_en, 'custom_permalink', 'my-blog');

        $_SERVER['HTTP_HOST'] = 'en.test';

        $link = get_permalink($this->child_shadow_en);

        $this->assertStringEndsWith('/my-blog/', $link);
    }

    // ================================================================
    // Canonical URL tests
    // ================================================================

    public function testCanonicalUrlForMasterPageWithCustomPermalink(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $canonical = wp_get_canonical_url($this->child_page);
        $path = trim(parse_url($canonical, PHP_URL_PATH), '/');

        $this->assertSame('blog', $path);
    }

    public function testCanonicalUrlForMasterChildPageWithoutCustomPermalink(): void
    {
        // Create a child page with parent but no custom_permalink
        $child_no_cp = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'FAQ',
            'post_name' => 'faq',
            'post_parent' => $this->parent_page,
            'post_status' => 'publish',
        ]);

        $_SERVER['HTTP_HOST'] = 'master.test';

        $canonical = wp_get_canonical_url($child_no_cp);
        $path = trim(parse_url($canonical, PHP_URL_PATH), '/');

        // Should be flat /faq/, not hierarchical /accueil/faq/
        $this->assertSame('faq', $path);
    }

    public function testCanonicalUrlForShadowPage(): void
    {
        update_post_meta($this->child_shadow_en, 'custom_permalink', 'blog');

        $_SERVER['HTTP_HOST'] = 'en.test';

        $canonical = wp_get_canonical_url($this->child_shadow_en);
        $path = trim(parse_url($canonical, PHP_URL_PATH), '/');

        $this->assertSame('blog', $path);
    }

    public function testCanonicalUrlForProduct(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $canonical = wp_get_canonical_url($this->product);
        $path = trim(parse_url($canonical, PHP_URL_PATH), '/');

        // Product should have flat URL
        $this->assertSame('origin', $path);
    }

    public function testFrontPagePermalinkIsRoot(): void
    {
        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->parent_page);
        update_post_meta($this->parent_page, 'custom_permalink', 'accueil');

        $_SERVER['HTTP_HOST'] = 'master.test';

        $link = get_permalink($this->parent_page);
        $path = trim(parse_url($link, PHP_URL_PATH), '/');

        // Front page should be "/" not "/accueil/"
        $this->assertSame('', $path);
    }

    public function testShadowFrontPagePermalinkIsRoot(): void
    {
        // EN shadow of the front page
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow_home = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Home EN',
            'post_name' => 'home',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow_home, '_master_id', $this->parent_page);
        update_post_meta($shadow_home, '_locale', 'en_IE');
        update_post_meta($shadow_home, 'custom_permalink', 'home');

        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->parent_page);

        $_SERVER['HTTP_HOST'] = 'master.test';

        // Even when called from master domain, shadow front page should return /
        $link = get_permalink($shadow_home);
        $path = trim(parse_url($link, PHP_URL_PATH), '/');

        $this->assertSame('', $path);
    }
}

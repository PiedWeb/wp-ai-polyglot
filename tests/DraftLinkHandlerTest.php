<?php

/**
 * @group draft-links
 */
class DraftLinkHandlerTest extends WP_UnitTestCase
{
    private int $master_published;

    private int $master_draft;

    private int $shadow_en_published;

    private int $shadow_en_draft;

    public function set_up(): void
    {
        parent::set_up();

        register_post_type('product', ['public' => true]);

        $this->master_published = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_name' => 'a-propos',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->master_published, 'custom_permalink', 'a-propos');

        $this->master_draft = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Page brouillon',
            'post_name' => 'brouillon',
            'post_status' => 'draft',
        ]);
        update_post_meta($this->master_draft, 'custom_permalink', 'brouillon');

        // EN shadow of published page
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->shadow_en_published = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'About',
            'post_name' => 'about',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->shadow_en_published, '_locale', 'en_IE');
        update_post_meta($this->shadow_en_published, '_master_id', $this->master_published);
        update_post_meta($this->shadow_en_published, 'custom_permalink', 'about');

        // EN shadow of draft page
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->shadow_en_draft = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Draft EN',
            'post_name' => 'draft-en',
            'post_status' => 'draft',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->shadow_en_draft, '_locale', 'en_IE');
        update_post_meta($this->shadow_en_draft, '_master_id', $this->master_draft);
        update_post_meta($this->shadow_en_draft, 'custom_permalink', 'draft-en');
    }

    public function tear_down(): void
    {
        unset($_SERVER['HTTP_HOST']);
        unregister_post_type('product');
        parent::tear_down();
    }

    // ================================================================
    // polyglot_resolve_url_any_status()
    // ================================================================

    public function testResolvesPublishedPageByCustomPermalink(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $id = polyglot_resolve_url_any_status('http://master.test/a-propos');

        $this->assertSame($this->master_published, $id);
    }

    public function testResolvesDraftPageByCustomPermalink(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $id = polyglot_resolve_url_any_status('http://master.test/brouillon');

        $this->assertSame($this->master_draft, $id);
    }

    public function testResolvesShadowDraftFromShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $id = polyglot_resolve_url_any_status('http://en.test/draft-en');

        $this->assertSame($this->shadow_en_draft, $id);
    }

    public function testReturnsNullForExternalUrl(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertNull(polyglot_resolve_url_any_status('https://example.com/foo'));
    }

    public function testReturnsNullForHomepage(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertNull(polyglot_resolve_url_any_status('http://master.test/'));
    }

    public function testReturnsNullForUnknownSlug(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertNull(polyglot_resolve_url_any_status('http://master.test/this-does-not-exist'));
    }

    // ================================================================
    // polyglot_handle_draft_links() — the_content filter
    // ================================================================

    public function testPublishedLinkKeptOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="http://master.test/a-propos">À propos</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringContainsString('<a href="http://master.test/a-propos">', $result);
    }

    public function testDraftLinkReplacedWithSpanOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="http://master.test/brouillon">Voir la page</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringContainsString('data-draft="Page brouillon"', $result);
        $this->assertStringContainsString('data-status="draft"', $result);
        $this->assertStringContainsString('Voir la page', $result);
    }

    public function testShadowDraftLinkReplacedOnShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $content = '<p><a href="http://en.test/draft-en">Read more</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringContainsString('data-draft="Draft EN"', $result);
        $this->assertStringContainsString('Read more', $result);
    }

    public function testExternalLinksUntouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="https://example.com/foo">External</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringContainsString('<a href="https://example.com/foo">', $result);
    }

    public function testMailtoLinkUntouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="mailto:test@example.com">Contact</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringContainsString('href="mailto:test@example.com"', $result);
    }

    public function testAnchorOnlyLinkUntouched(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="#section">Section</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringContainsString('href="#section"', $result);
    }

    public function testEmptyContentReturnedUnchanged(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertSame('', polyglot_handle_draft_links(''));
    }

    public function testContentWithoutLinksReturnedUnchanged(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p>Texte sans lien.</p>';
        $this->assertSame($content, polyglot_handle_draft_links($content));
    }

    public function testDraftBecomesActiveAfterPublish(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="http://master.test/brouillon">Voir</a></p>';

        // Before publish: link replaced
        $before = apply_filters('the_content', $content);
        $this->assertStringNotContainsString('<a ', $before);

        // Transition to publish
        wp_update_post(['ID' => $this->master_draft, 'post_status' => 'publish']);
        wp_cache_flush(); // simulate cache miss

        // After publish: link restored
        $after = apply_filters('the_content', $content);
        $this->assertStringContainsString('<a href="http://master.test/brouillon">', $after);
    }

    public function testPendingAndPrivateLinksAlsoReplaced(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        foreach (['pending', 'private'] as $status) {
            $post_id = self::factory()->post->create([
                'post_type' => 'page',
                'post_name' => "page-{$status}",
                'post_title' => "Page {$status}",
                'post_status' => $status,
            ]);
            update_post_meta($post_id, 'custom_permalink', "page-{$status}");

            $content = "<p><a href=\"http://master.test/page-{$status}\">Lien</a></p>";
            $result = apply_filters('the_content', $content);

            $this->assertStringNotContainsString('<a ', $result, "Status: {$status}");
            $this->assertStringContainsString("data-status=\"{$status}\"", $result, "Status: {$status}");

            wp_cache_flush();
        }
    }

    public function testEditorUserSeesDraftLinkIntact(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $editor = self::factory()->user->create(['role' => 'editor']);
        wp_set_current_user($editor);

        $content = '<p><a href="http://master.test/brouillon">Brouillon</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringContainsString('<a href="http://master.test/brouillon">', $result);

        wp_set_current_user(0);
    }

    public function testCrossDomainDraftLinkReplaced(): void
    {
        // On master domain, a link pointing to a shadow draft domain should be replaced
        $_SERVER['HTTP_HOST'] = 'master.test';

        $content = '<p><a href="http://en.test/draft-en">English draft</a></p>';
        $result = apply_filters('the_content', $content);

        $this->assertStringNotContainsString('<a ', $result);
        $this->assertStringContainsString('data-draft=', $result);
    }
}

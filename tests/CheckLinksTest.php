<?php

/**
 * @group check-links
 */
class CheckLinksTest extends WP_UnitTestCase
{
    private int $master_page;

    private int $master_product;

    private int $master_blog;

    private int $en_page;

    private int $en_product;

    private int $en_blog;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        register_post_type('product', ['public' => true]);

        // Master page: contact
        $this->master_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contact',
            'post_name' => 'contact',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->master_page, 'custom_permalink', 'contact');

        // Master product: poutres
        $this->master_product = self::factory()->post->create([
            'post_type' => 'product',
            'post_title' => 'Poutres',
            'post_name' => 'poutres',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->master_product, 'custom_permalink', 'poutres');

        // Master page: blog (same slug across locales)
        $this->master_blog = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Blog',
            'post_name' => 'blog',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->master_blog, 'custom_permalink', 'blog');

        // EN shadow: contact → contact-us (different slug)
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->en_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contact Us',
            'post_name' => 'contact-us',
            'post_status' => 'publish',
            'post_content' => '<p>Visit our <a href="/contact">contact page</a> or <a href="/contact-us">correct link</a>.</p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->en_page, '_master_id', $this->master_page);
        update_post_meta($this->en_page, '_locale', 'en_IE');
        update_post_meta($this->en_page, 'custom_permalink', 'contact-us');

        // EN shadow: poutres → hangboards (different slug)
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->en_product = self::factory()->post->create([
            'post_type' => 'product',
            'post_title' => 'Hangboards',
            'post_name' => 'hangboards',
            'post_status' => 'publish',
            'post_content' => '<p>See our <a href="/poutres">hangboards</a>.</p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->en_product, '_master_id', $this->master_product);
        update_post_meta($this->en_product, '_locale', 'en_IE');
        update_post_meta($this->en_product, 'custom_permalink', 'hangboards');

        // EN shadow: blog → blog (same slug)
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->en_blog = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Blog EN',
            'post_name' => 'blog',
            'post_status' => 'publish',
            'post_content' => '<p>Read our <a href="/blog">blog</a>.</p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->en_blog, '_master_id', $this->master_blog);
        update_post_meta($this->en_blog, '_locale', 'en_IE');
        update_post_meta($this->en_blog, 'custom_permalink', 'blog');

        $this->cli = new Polyglot_CLI();
    }

    public function tear_down(): void
    {
        unregister_post_type('product');
        parent::tear_down();
    }

    public function test_detects_french_slug_in_shadow_content(): void
    {
        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        $this->assertStringContainsString('[WRONG_SLUG]', $output);
        $this->assertStringContainsString('"/contact"', $output);
        $this->assertStringContainsString('"/poutres"', $output);
    }

    public function test_correct_slug_without_trailing_slash_not_flagged(): void
    {
        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        // /contact-us without trailing slash is correct — not flagged
        $this->assertStringNotContainsString('href="/contact-us"', $output);
    }

    public function test_same_slug_across_locales_without_trailing_slash_not_flagged(): void
    {
        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        // /blog without trailing slash is correct — not flagged
        $this->assertStringNotContainsString('[WRONG_SLUG] href="/blog"', $output);
        $this->assertStringNotContainsString('[TRAILING_SLASH] href="/blog"', $output);
    }

    public function test_fix_replaces_french_slugs(): void
    {
        $this->cli->check_links([], ['locale' => 'en_IE', 'fix' => true]);

        $updated = get_post($this->en_page);
        $this->assertStringContainsString('href="/contact-us"', $updated->post_content);
        $this->assertStringNotContainsString('href="/contact"', $updated->post_content);

        $updated_product = get_post($this->en_product);
        $this->assertStringContainsString('href="/hangboards"', $updated_product->post_content);
        $this->assertStringNotContainsString('href="/poutres"', $updated_product->post_content);
    }

    public function test_skips_external_and_anchor_links(): void
    {
        // Create shadow with only external/anchor links
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Clean page',
            'post_name' => 'clean-page',
            'post_status' => 'publish',
            'post_content' => '<p><a href="https://example.com/foo">external</a> <a href="#section">anchor</a> <a href="mailto:a@b.com">mail</a></p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->master_page);
        update_post_meta($shadow, '_locale', 'en_IE');
        update_post_meta($shadow, 'custom_permalink', 'clean-page');

        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        // Should not flag external, anchor, or mailto links
        $this->assertStringNotContainsString('example.com', $output);
        $this->assertStringNotContainsString('#section', $output);
        $this->assertStringNotContainsString('mailto:', $output);
    }

    public function test_detects_localhost_urls(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Dev page',
            'post_name' => 'dev-page',
            'post_status' => 'publish',
            'post_content' => '<p><a href="http://127.0.0.1:9172/contact">dev link</a></p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->master_blog);
        update_post_meta($shadow, '_locale', 'en_IE');

        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        $this->assertStringContainsString('[LOCALHOST', $output);
    }

    public function test_detects_localhost_in_medialink(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Media page',
            'post_name' => 'media-page',
            'post_status' => 'publish',
            'post_content' => '<!-- wp:media-text {"mediaLink":"http://127.0.0.1:9172/some-image-page/"} --><div>content</div><!-- /wp:media-text -->',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->master_blog);
        update_post_meta($shadow, '_locale', 'en_IE');

        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        $this->assertStringContainsString('[LOCALHOST]', $output);
        $this->assertStringContainsString('mediaLink=', $output);
    }

    public function test_detects_trailing_slash_before_fragment(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Delivery info',
            'post_name' => 'delivery-info',
            'post_status' => 'publish',
            'post_content' => '<p><a href="/contact-us/#section">link</a></p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->master_page);
        update_post_meta($shadow, '_locale', 'en_IE');
        update_post_meta($shadow, 'custom_permalink', 'delivery-info');

        ob_start();
        $this->cli->check_links([], ['locale' => 'en_IE']);
        $output = ob_get_clean();

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('/contact-us/#section', $output);
    }

    public function test_fix_removes_trailing_slash_before_fragment(): void
    {
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Delivery info',
            'post_name' => 'delivery-info',
            'post_status' => 'publish',
            'post_content' => '<p><a href="/contact-us/#section">link</a> and <a href="/hangboards/#top">other</a></p>',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $this->master_page);
        update_post_meta($shadow, '_locale', 'en_IE');
        update_post_meta($shadow, 'custom_permalink', 'delivery-info');

        $this->cli->check_links([], ['locale' => 'en_IE', 'fix' => true]);

        $updated = get_post($shadow);
        $this->assertStringContainsString('href="/contact-us#section"', $updated->post_content);
        $this->assertStringContainsString('href="/hangboards#top"', $updated->post_content);
        $this->assertStringNotContainsString('/contact-us/#', $updated->post_content);
        $this->assertStringNotContainsString('/hangboards/#', $updated->post_content);
    }

    public function test_detects_localhost_in_master_content(): void
    {
        // Master post with localhost URL
        wp_update_post([
            'ID' => $this->master_page,
            'post_content' => '<p><a href="http://127.0.0.1:9172/blog">local link</a></p>',
        ]);

        ob_start();
        $this->cli->check_links([], ['locale' => 'fr_FR']);
        $output = ob_get_clean();

        $this->assertStringContainsString('[LOCALHOST]', $output);
        $this->assertStringContainsString('[fr_FR]', $output);
    }
}

<?php

/**
 * @group check-links
 * @group check-links-rendered
 */
class CheckLinksRenderedTest extends WP_UnitTestCase
{
    private int $master_page;

    private int $master_product;

    private int $en_page;

    private int $en_product;

    private Polyglot_CLI $cli;

    /** @var array<string, string> URL → body mock responses */
    private array $mock_responses = [];

    /** @var array<string, int> URL → status code (default 200) */
    private array $mock_statuses = [];

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

        // EN shadow: contact → contact-us
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->en_page = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Contact Us',
            'post_name' => 'contact-us',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->en_page, '_master_id', $this->master_page);
        update_post_meta($this->en_page, '_locale', 'en_IE');
        update_post_meta($this->en_page, 'custom_permalink', 'contact-us');

        // EN shadow: poutres → hangboards
        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $this->en_product = self::factory()->post->create([
            'post_type' => 'product',
            'post_title' => 'Hangboards',
            'post_name' => 'hangboards',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($this->en_product, '_master_id', $this->master_product);
        update_post_meta($this->en_product, '_locale', 'en_IE');
        update_post_meta($this->en_product, 'custom_permalink', 'hangboards');

        $this->cli = new Polyglot_CLI();

        // Hook into pre_http_request to mock responses
        add_filter('pre_http_request', [$this, 'mock_http'], 10, 3);
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'mock_http'], 10);
        $this->mock_responses = [];
        $this->mock_statuses = [];
        unregister_post_type('product');
        parent::tear_down();
    }

    /**
     * Mock HTTP responses based on $this->mock_responses.
     *
     * @return array|false
     */
    public function mock_http($preempt, $args, $url)
    {
        // Check for exact URL match first, then try without trailing slash
        $body = $this->mock_responses[$url] ?? null;
        if (null === $body) {
            return false; // Let it fail naturally
        }

        $status = $this->mock_statuses[$url] ?? 200;

        return [
            'response' => ['code' => $status, 'message' => 'OK'],
            'body' => $body,
            'headers' => [],
            'cookies' => [],
        ];
    }

    private function set_mock(string $url, string $body, int $status = 200): void
    {
        $this->mock_responses[$url] = $body;
        $this->mock_statuses[$url] = $status;
    }

    /**
     * Set mock 404 for all non-configured URLs (sitemaps etc).
     */
    private function set_default_404s(string $locale): void
    {
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ($cfg['locale'] !== $locale) {
                continue;
            }
            $base = polyglot_authority_to_url($authority);
            $this->set_mock($base.'/wp-sitemap-posts-page-1.xml', '', 404);
            $this->set_mock($base.'/wp-sitemap-posts-product-1.xml', '', 404);
        }
    }

    private function run_rendered(array $assoc_args = []): string
    {
        ob_start();
        $this->cli->check_links([], array_merge(['rendered' => true], $assoc_args));

        return ob_get_clean();
    }

    public function test_rendered_fix_is_incompatible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cli->check_links([], ['rendered' => true, 'fix' => true]);
    }

    public function test_detects_trailing_slash_in_rendered_html(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Homepage with a menu link that has trailing slash
        $this->set_mock($base.'/', '<html><body><nav><a href="/contact-us/">Contact</a></nav></body></html>');
        // The contact-us page itself
        $this->set_mock($base.'/contact-us', '<html><body><a href="/hangboards/">Hangboards</a></body></html>');
        // The hangboards page
        $this->set_mock($base.'/hangboards', '<html><body><p>No links</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('/contact-us/', $output);
        $this->assertStringContainsString('/hangboards/', $output);
        // Grouped by locale
        $this->assertStringContainsString('— en_IE —', $output);
    }

    public function test_no_trailing_slash_is_clean(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // All links correct — no trailing slash
        $this->set_mock($base.'/', '<html><body><a href="/contact-us">Contact</a></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('No trailing slash issues', $output);
        $this->assertStringNotContainsString('[TRAILING_SLASH]', $output);
    }

    public function test_deduplicates_menu_links_across_pages(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Same menu link on multiple pages
        $menu_html = '<nav><a href="/contact-us/">Contact</a></nav>';
        $this->set_mock($base.'/', "<html><body>$menu_html</body></html>");
        $this->set_mock($base.'/contact-us', "<html><body>$menu_html <p>Page content</p></body></html>");
        $this->set_mock($base.'/hangboards', "<html><body>$menu_html <p>Products</p></body></html>");
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        // Should only report one TRAILING_SLASH issue, not three
        $this->assertSame(1, substr_count($output, '[TRAILING_SLASH]'));
        // Classified as template since it appears on multiple pages
        $this->assertStringContainsString('template (menu/footer', $output);
    }

    public function test_detects_trailing_slash_in_sitemap(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Pages are clean
        $this->set_mock($base.'/', '<html><body><p>Home</p></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Contact</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');

        // Sitemap has trailing slashes
        $this->set_mock($base.'/wp-sitemap-posts-page-1.xml', '<?xml version="1.0"?><urlset><url><loc>'.$base.'/contact-us/</loc></url></urlset>');
        $this->set_mock($base.'/wp-sitemap-posts-product-1.xml', '', 404);

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('<loc>', $output);
        $this->assertStringContainsString('contact-us/', $output);
    }

    public function test_detects_trailing_slash_in_hreflang(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Page with hreflang in <head> that has trailing slash
        $this->set_mock($base.'/', '<html><head><link rel="alternate" hreflang="en" href="'.$base.'/contact-us/" /></head><body></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('contact-us/', $output);
    }

    public function test_skips_external_links(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        $this->set_mock($base.'/', '<html><body><a href="https://example.com/page/">external</a></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('No trailing slash issues', $output);
        $this->assertStringNotContainsString('example.com', $output);
    }

    public function test_skips_wp_internal_paths(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        $this->set_mock($base.'/', '<html><body><a href="/wp-content/uploads/image.jpg">img</a><a href="/wp-admin/edit.php">admin</a></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('No trailing slash issues', $output);
    }

    public function test_respects_custom_permalink_with_trailing_slash(): void
    {
        // Create a master+shadow where custom_permalink intentionally has trailing slash
        $master = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'FAQ',
            'post_name' => 'faq',
            'post_status' => 'publish',
        ]);
        update_post_meta($master, 'custom_permalink', 'faq/');

        $GLOBALS['polyglot_pending_locale'] = 'en_IE';
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'FAQ EN',
            'post_name' => 'faq',
            'post_status' => 'publish',
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $master);
        update_post_meta($shadow, '_locale', 'en_IE');
        update_post_meta($shadow, 'custom_permalink', 'faq/');

        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Link with trailing slash matching the custom_permalink — should NOT be flagged
        $this->set_mock($base.'/', '<html><body><a href="/faq/">FAQ</a></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_mock($base.'/faq/', '<html><body><p>FAQ</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringNotContainsString('/faq/', $output);
        $this->assertStringContainsString('No trailing slash issues', $output);
    }

    public function test_detects_trailing_slash_in_absolute_same_domain_links(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Absolute URL with trailing slash pointing to same domain
        $this->set_mock($base.'/', '<html><body><a href="'.$base.'/contact-us/">Contact</a></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('contact-us/', $output);
    }

    public function test_sitemap_hreflang_links_checked(): void
    {
        $en_authority = null;
        $fr_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;
            }
            if ('fr_FR' === $cfg['locale']) {
                $fr_authority = $authority;
            }
        }
        $en_base = polyglot_authority_to_url($en_authority);
        $fr_base = polyglot_authority_to_url($fr_authority);

        // Pages are clean
        $this->set_mock($en_base.'/', '<html><body><p>Home</p></body></html>');
        $this->set_mock($en_base.'/contact-us', '<html><body><p>Contact</p></body></html>');
        $this->set_mock($en_base.'/hangboards', '<html><body><p>Products</p></body></html>');

        // Sitemap with xhtml:link hreflang that has trailing slash
        $this->set_mock($en_base.'/wp-sitemap-posts-page-1.xml', '<?xml version="1.0"?><urlset xmlns:xhtml="http://www.w3.org/1999/xhtml"><url><loc>'.$en_base.'/contact-us</loc><xhtml:link rel="alternate" hreflang="en" href="'.$en_base.'/contact-us/" /></url></urlset>');
        $this->set_mock($en_base.'/wp-sitemap-posts-product-1.xml', '', 404);

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        $this->assertStringContainsString('hreflang href', $output);
    }

    public function test_page_specific_link_shows_source_url(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Trailing slash link only on one specific page
        $this->set_mock($base.'/', '<html><body><p>Home</p></body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><a href="/hangboards/">link</a></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        // Should show the page URL where the issue was found
        $this->assertStringContainsString($base.'/contact-us', $output);
        // Should NOT be classified as template (only 1 page)
        $this->assertStringNotContainsString('template', $output);
    }

    public function test_trailing_slash_before_fragment_detected_and_deduped(): void
    {
        $en_authority = null;
        foreach (POLYGLOT_LOCALES as $authority => $cfg) {
            if ('en_IE' === $cfg['locale']) {
                $en_authority = $authority;

                break;
            }
        }
        $base = polyglot_authority_to_url($en_authority);

        // Page with multiple links to same slug with trailing slash + different fragments
        $this->set_mock($base.'/', '<html><body>'
            .'<a href="/contact-us/#section1">Section 1</a>'
            .'<a href="/contact-us/#section2">Section 2</a>'
            .'<a href="/contact-us/">Plain</a>'
            .'</body></html>');
        $this->set_mock($base.'/contact-us', '<html><body><p>Hello</p></body></html>');
        $this->set_mock($base.'/hangboards', '<html><body><p>Products</p></body></html>');
        $this->set_default_404s('en_IE');

        $output = $this->run_rendered(['locale' => 'en_IE']);

        // Should detect the trailing slash issue
        $this->assertStringContainsString('[TRAILING_SLASH]', $output);
        // Should report only once despite 3 variants (dedup on path)
        $this->assertSame(1, substr_count($output, '[TRAILING_SLASH]'));
    }
}

<?php

/**
 * @group footer-switcher
 */
class FooterSwitcherTest extends WP_UnitTestCase
{
    private int $master_id;
    private int $shadow_id;

    public function set_up(): void
    {
        parent::set_up();

        // Pretty permalinks required for path-based assertions
        $this->set_permalink_structure('/%postname%/');

        // Default: simulate master domain
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->master_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'À propos',
            'post_name'   => 'a-propos',
            'post_status' => 'publish',
        ]);

        $this->shadow_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'About Us',
            'post_name'   => 'about-us',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_id, '_locale', 'en_IE');
    }

    public function tear_down(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        delete_option('polyglot_footer_switcher');
        parent::tear_down();
    }

    public function testFooterBarRendersWhenEnabled(): void
    {
        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        $this->assertStringContainsString('polyglot-footer-bar', $output);
        $this->assertStringContainsString('polyglot-fb-select', $output);
        $this->assertStringContainsString('en.test', $output);
        $this->assertStringContainsString('es.test', $output);
    }

    public function testFooterBarHighlightsCurrentLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';
        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        // EN should be shown in the toggle button (current locale)
        $this->assertStringContainsString('polyglot-fb-toggle', $output);
        $this->assertMatchesRegularExpression('/polyglot-fb-toggle.*?English/s', $output);
    }

    public function testFooterBarHiddenWhenDisabled(): void
    {
        update_option('polyglot_footer_switcher', '0');
        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function testFooterBarHidesAttributionByDefault(): void
    {
        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('wap.piedweb.com', $output);
        $this->assertStringNotContainsString('<a class="polyglot-fb-powered"', $output);
    }

    public function testFooterBarShowsAttributionWhenOptedIn(): void
    {
        if (! defined('POLYGLOT_FOOTER_CREDIT')) {
            define('POLYGLOT_FOOTER_CREDIT', true);
        }

        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        $this->assertStringContainsString('wap.piedweb.com', $output);
        $this->assertStringContainsString('PiedWeb AI Polyglot', $output);
    }

    public function testFooterBarUsesDropdownWithLinks(): void
    {
        $this->simulate_front_page();

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        $this->assertStringContainsString('polyglot-fb-dropdown', $output);
        $this->assertStringContainsString('<a ', $output);
        $this->assertStringContainsString('hreflang=', $output);
    }

    public function testFooterBarLinksToTranslation(): void
    {
        $this->simulate_singular($this->master_id);

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        // EN link should contain the shadow's slug, not just "/"
        $this->assertStringContainsString('about-us', $output);
    }

    public function testFooterBarFallsBackToHomepage(): void
    {
        $standalone = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Standalone',
            'post_name'   => 'standalone',
            'post_status' => 'publish',
        ]);

        $this->simulate_singular($standalone);

        ob_start();
        polyglot_footer_switcher_bar();
        $output = ob_get_clean();

        // EN and ES options should go to homepage (authority + /)
        $this->assertMatchesRegularExpression('#https?://en\.test/"#', $output);
        $this->assertMatchesRegularExpression('#https?://es\.test/"#', $output);
    }

    public function testLocalePathsHomepage(): void
    {
        $this->simulate_front_page();

        $paths = polyglot_get_locale_paths();

        $this->assertCount(count(POLYGLOT_LOCALES), $paths);
        foreach ($paths as $path) {
            $this->assertSame('/', $path);
        }
    }

    public function testLocalePathsSingular(): void
    {
        $this->simulate_singular($this->master_id);

        $paths = polyglot_get_locale_paths();

        $this->assertArrayHasKey('master.test', $paths);
        $this->assertArrayHasKey('en.test', $paths);
        $this->assertStringContainsString('a-propos', $paths['master.test']);
        $this->assertStringContainsString('about-us', $paths['en.test']);
    }

    /**
     * Simulate being on the front page.
     */
    private function simulate_front_page(): void
    {
        $page_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Home',
            'post_status' => 'publish',
        ]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);

        global $post, $wp_query;

        $post = get_post($page_id);
        $wp_query = new WP_Query(['page_id' => $page_id]);

        $wp_query->is_singular = true;
        $wp_query->is_page = true;
        $wp_query->is_home = false;
        $wp_query->is_front_page = true;
        $wp_query->is_404 = false;
        $wp_query->queried_object = $post;
        $wp_query->queried_object_id = $page_id;

        $GLOBALS['wp_the_query'] = $wp_query;
    }

    /**
     * Simulate being on a singular page (not front page).
     */
    private function simulate_singular(int $post_id): void
    {
        global $post, $wp_query;

        $post = get_post($post_id);
        $wp_query = new WP_Query(['page_id' => $post_id]);

        $wp_query->is_singular = true;
        $wp_query->is_page = true;
        $wp_query->is_home = false;
        $wp_query->is_front_page = false;
        $wp_query->is_404 = false;
        $wp_query->queried_object = $post;
        $wp_query->queried_object_id = $post_id;

        $GLOBALS['wp_the_query'] = $wp_query;
    }
}

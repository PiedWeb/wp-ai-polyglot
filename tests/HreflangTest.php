<?php

/**
 * @group hreflang
 */
class HreflangTest extends WP_UnitTestCase
{
    private int $master_id;
    private int $shadow_id;

    public function set_up(): void
    {
        parent::set_up();

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

    public function test_hreflang_on_master_singular(): void
    {
        $this->simulate_singular($this->master_id);

        ob_start();
        polyglot_inject_hreflang();
        $output = ob_get_clean();

        $this->assertStringContainsString('hreflang="fr"', $output);
        $this->assertStringContainsString('hreflang="en"', $output);
        $this->assertStringNotContainsString('x-default', $output);
    }

    public function test_hreflang_on_shadow_singular(): void
    {
        $this->simulate_singular($this->shadow_id);

        ob_start();
        polyglot_inject_hreflang();
        $output = ob_get_clean();

        $this->assertStringContainsString('hreflang="fr"', $output);
        $this->assertStringContainsString('hreflang="en"', $output);
    }

    public function test_no_hreflang_without_shadow(): void
    {
        $standalone = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Standalone',
            'post_status' => 'publish',
        ]);

        $this->simulate_singular($standalone);

        ob_start();
        polyglot_inject_hreflang();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('hreflang', $output);
    }

    public function test_homepage_hreflang(): void
    {
        // Create a static front page
        $page_id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_title'  => 'Home',
            'post_status' => 'publish',
        ]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $page_id);

        // Simulate being on front page
        $this->simulate_singular($page_id);

        // Override to make is_front_page() return true
        $GLOBALS['wp_query']->is_front_page = true;
        $GLOBALS['wp_query']->queried_object_id = $page_id;

        // Verify is_front_page() actually returns true
        $this->assertTrue(is_front_page());

        ob_start();
        polyglot_inject_hreflang();
        $output = ob_get_clean();

        $this->assertStringContainsString('hreflang="fr"', $output);
        $this->assertStringContainsString('hreflang="en"', $output);
        $this->assertStringContainsString('hreflang="es"', $output);
        $this->assertStringNotContainsString('x-default', $output);
    }

    /**
     * Simulate being on a singular page without relying on go_to() + rewrite rules.
     */
    private function simulate_singular(int $post_id): void
    {
        global $post, $wp_query;

        $post = get_post($post_id);
        $wp_query = new WP_Query(['page_id' => $post_id]);

        // Force singular flags
        $wp_query->is_singular = true;
        $wp_query->is_page = true;
        $wp_query->is_404 = false;
        $wp_query->queried_object = $post;
        $wp_query->queried_object_id = $post_id;

        $GLOBALS['wp_the_query'] = $wp_query;
    }
}

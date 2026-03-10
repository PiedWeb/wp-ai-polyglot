<?php

/**
 * @group admin-ui
 */
class AdminUITest extends WP_UnitTestCase
{
    private int $master_id;

    private int $shadow_en;

    private int $shadow_es;

    public function set_up(): void
    {
        parent::set_up();

        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_status' => 'publish',
        ]);

        $this->shadow_en = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'About Us',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_en, '_master_id', $this->master_id);
        update_post_meta($this->shadow_en, '_locale', 'en_IE');

        $this->shadow_es = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'Sobre nosotros',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_es, '_master_id', $this->master_id);
        update_post_meta($this->shadow_es, '_locale', 'es_ES');
    }

    public function testShadowLocalesHelper(): void
    {
        $locales = polyglot_get_shadow_locales();

        $this->assertContains('en_IE', $locales);
        $this->assertContains('es_ES', $locales);
        $this->assertNotContains('fr_FR', $locales);
    }

    public function testLocaleToLabel(): void
    {
        $this->assertSame('English', polyglot_locale_to_label('en_IE'));
        $this->assertSame('Español', polyglot_locale_to_label('es_ES'));
        $this->assertSame('Français', polyglot_locale_to_label('fr_FR'));
        // Unknown locale returns raw string
        $this->assertSame('xx_XX', polyglot_locale_to_label('xx_XX'));
    }

    public function testAdminFilterHidesShadowsByDefault(): void
    {
        unset($_GET['polyglot_locale']);

        $query = new WP_Query();
        $query->set('post_type', 'page');

        polyglot_admin_filter_shadows($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_master_id', $meta_query[0]['key']);
        $this->assertSame('NOT EXISTS', $meta_query[0]['compare']);
    }

    public function testAdminFilterShowsAll(): void
    {
        $_GET['polyglot_locale'] = 'all';

        $query = new WP_Query();
        $query->set('post_type', 'page');

        polyglot_admin_filter_shadows($query);

        $meta_query = $query->get('meta_query');
        // Should be empty — no filter applied
        $this->assertEmpty($meta_query);

        unset($_GET['polyglot_locale']);
    }

    public function testAdminFilterByLocale(): void
    {
        $_GET['polyglot_locale'] = 'en_IE';

        $query = new WP_Query();
        $query->set('post_type', 'page');

        polyglot_admin_filter_shadows($query);

        $meta_query = $query->get('meta_query');
        $this->assertIsArray($meta_query);
        $this->assertSame('_locale', $meta_query[0]['key']);
        $this->assertSame('en_IE', $meta_query[0]['value']);

        unset($_GET['polyglot_locale']);
    }

    public function testAdminFilterIgnoresOtherPostTypes(): void
    {
        $query = new WP_Query();
        $query->set('post_type', 'attachment');

        polyglot_admin_filter_shadows($query);

        $meta_query = $query->get('meta_query');
        $this->assertEmpty($meta_query);
    }

    public function testLangueColumnMasterBadge(): void
    {
        ob_start();
        polyglot_render_langue_column('polyglot_langue', $this->master_id);
        $output = ob_get_clean();

        // Master badge FR
        $this->assertStringContainsString('polyglot-master', $output);
        $this->assertStringContainsString('FR', $output);
        // Translation count 2/2 (en + es in test config)
        $this->assertStringContainsString('2/2', $output);
    }

    public function testLangueColumnShadowBadge(): void
    {
        ob_start();
        polyglot_render_langue_column('polyglot_langue', $this->shadow_en);
        $output = ob_get_clean();

        $this->assertStringContainsString('polyglot-shadow', $output);
        $this->assertStringContainsString('EN', $output);
    }

    public function testLangueColumnShadowManualBadge(): void
    {
        update_post_meta($this->shadow_en, '_translation_mode', 'manual');

        ob_start();
        polyglot_render_langue_column('polyglot_langue', $this->shadow_en);
        $output = ob_get_clean();

        $this->assertStringContainsString('polyglot-manual', $output);
    }

    public function testTranslationMetaboxShowsExisting(): void
    {
        ob_start();
        polyglot_render_translation_metabox(get_post($this->master_id));
        $output = ob_get_clean();

        // English exists
        $this->assertStringContainsString('English', $output);
        $this->assertStringContainsString('Edit', $output);
        // Español exists
        $this->assertStringContainsString('Español', $output);
        // Count
        $this->assertStringContainsString('2/2', $output);
    }

    public function testTranslationMetaboxMissingLocale(): void
    {
        // Delete ES shadow to test missing state
        wp_delete_post($this->shadow_es, true);

        ob_start();
        polyglot_render_translation_metabox(get_post($this->master_id));
        $output = ob_get_clean();

        // EN exists, ES missing
        $this->assertStringContainsString('1/2', $output);
        $this->assertStringContainsString('✗', $output);
    }
}

<?php

/**
 * @group order-locale
 */
class OrderLocaleTest extends WP_UnitTestCase
{
    public function testSaveOrderLocaleStoresMeta(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        // Create a dummy post to simulate an order (WC not loaded)
        $order_id = self::factory()->post->create([
            'post_type' => 'shop_order',
            'post_status' => 'wc-processing',
        ]);

        polyglot_save_order_locale($order_id);

        $this->assertSame('en_IE', get_post_meta($order_id, '_order_locale', true));
    }

    public function testSaveOrderLocaleMasterDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $order_id = self::factory()->post->create([
            'post_type' => 'shop_order',
            'post_status' => 'wc-processing',
        ]);

        polyglot_save_order_locale($order_id);

        $this->assertSame('fr_FR', get_post_meta($order_id, '_order_locale', true));
    }

    public function testEmailSwitchLocaleWithMeta(): void
    {
        $order_id = self::factory()->post->create(['post_type' => 'shop_order']);
        update_post_meta($order_id, '_order_locale', 'en_IE');

        polyglot_email_switch_locale($order_id);

        global $polyglot_email_locale_active;
        $this->assertTrue($polyglot_email_locale_active);

        // Clean up
        polyglot_email_restore_locale($order_id);
        $this->assertFalse($polyglot_email_locale_active);
    }

    public function testEmailSwitchLocaleNoopWithoutMeta(): void
    {
        $order_id = self::factory()->post->create(['post_type' => 'shop_order']);
        // No _order_locale meta

        polyglot_email_switch_locale($order_id);

        global $polyglot_email_locale_active;
        $this->assertFalse($polyglot_email_locale_active);
    }

    public function testBlockWcLocaleSwitchWhenActive(): void
    {
        global $polyglot_email_locale_active;

        $polyglot_email_locale_active = true;
        $this->assertFalse(polyglot_block_wc_locale_switch(true));

        $polyglot_email_locale_active = false;
        $this->assertTrue(polyglot_block_wc_locale_switch(true));
    }
}

<?php

/**
 * Verifies the WooCommerce-loaded harness boots and can create products.
 *
 * @group wc
 */
class WcSmokeTest extends WP_UnitTestCase
{
    public function testWooCommerceIsLoaded(): void
    {
        $this->assertTrue(function_exists('wc_get_product'), 'WooCommerce not loaded');
        $this->assertTrue(class_exists('WC_Product_Simple'), 'WC_Product_Simple missing');
    }

    public function testCanCreateAndReadProduct(): void
    {
        $product = new WC_Product_Simple();
        $product->set_name('Origin');
        $product->set_regular_price('100');
        $product->save();

        $loaded = wc_get_product($product->get_id());
        $this->assertSame('Origin', $loaded->get_name());
        $this->assertSame('100', $loaded->get_regular_price());
    }
}

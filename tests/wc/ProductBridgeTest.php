<?php

/**
 * Exercises the WooCommerce product bridge (inc/wc-product-bridge.php):
 * shadow products virtualize stock, price and images from their master,
 * and orders on a shadow decrement the master's inventory.
 *
 * @group wc
 */
class ProductBridgeTest extends WP_UnitTestCase
{
    private WC_Product_Simple $master;
    private WC_Product_Simple $shadow;
    private int $master_id;
    private int $shadow_id;

    public function set_up(): void
    {
        parent::set_up();

        $this->master = new WC_Product_Simple();
        $this->master->set_name('Origin');
        $this->master->set_regular_price('100');
        $this->master->set_manage_stock(true);
        $this->master->set_stock_quantity(10);
        $this->master->set_stock_status('instock');
        $this->master->save();
        $this->master_id = $this->master->get_id();

        $this->shadow = new WC_Product_Simple();
        $this->shadow->set_name('Origin (EN)');
        $this->shadow->update_meta_data('_master_id', $this->master_id);
        $this->shadow->update_meta_data('_locale', 'en_IE');
        $this->shadow->save();
        $this->shadow_id = $this->shadow->get_id();

        // Operate from the English shadow domain by default.
        $_SERVER['HTTP_HOST'] = 'en.test';
    }

    public function tear_down(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        parent::tear_down();
    }

    private function reloadShadow(): WC_Product
    {
        return wc_get_product($this->shadow_id);
    }

    // --- Stock virtualization ---

    public function testShadowReadsMasterStockQuantity(): void
    {
        $this->assertSame(10, $this->reloadShadow()->get_stock_quantity());
    }

    public function testShadowReadsMasterStockStatus(): void
    {
        $this->assertSame('instock', $this->reloadShadow()->get_stock_status());
    }

    public function testShadowReadsMasterInStock(): void
    {
        $this->assertTrue($this->reloadShadow()->is_in_stock());
    }

    public function testMasterStockUnchangedOnMasterDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $this->assertSame(10, wc_get_product($this->master_id)->get_stock_quantity());
    }

    // --- Price virtualization ---

    public function testShadowReadsMasterPriceWhenEmpty(): void
    {
        $this->assertSame('100', $this->reloadShadow()->get_price());
    }

    public function testShadowKeepsItsOwnPriceWhenSet(): void
    {
        $this->shadow->set_regular_price('80');
        $this->shadow->save();
        $this->assertSame('80', $this->reloadShadow()->get_price());
    }

    public function testShadowReadsMasterRegularPrice(): void
    {
        $this->assertSame('100', $this->reloadShadow()->get_regular_price());
    }

    // --- Image virtualization ---

    public function testShadowInheritsMasterImageId(): void
    {
        $attachment_id = self::factory()->attachment->create();
        $this->master->set_image_id($attachment_id);
        $this->master->save();

        // WC returns the freshly-loaded image-id meta as a numeric string.
        $this->assertEquals($attachment_id, $this->reloadShadow()->get_image_id());
    }

    public function testShadowInheritsMasterGallery(): void
    {
        $g1 = self::factory()->attachment->create();
        $g2 = self::factory()->attachment->create();
        $this->master->set_gallery_image_ids([$g1, $g2]);
        $this->master->save();

        $this->assertSame([$g1, $g2], $this->reloadShadow()->get_gallery_image_ids());
    }

    // --- Stock reduction interception ---

    public function testOrderingShadowDecrementsMasterStock(): void
    {
        $item = new WC_Order_Item_Product();
        $item->set_product($this->reloadShadow());

        $returned = polyglot_intercept_shadow_stock_reduction(3, null, $item);

        // The shadow itself must not be decremented (returns 0)...
        $this->assertSame(0, $returned);
        // ...the master's stock is reduced instead.
        $this->assertSame(7, wc_get_product($this->master_id)->get_stock_quantity());
    }

    public function testOrderingMasterProductIsNotIntercepted(): void
    {
        $item = new WC_Order_Item_Product();
        $item->set_product(wc_get_product($this->master_id));

        $returned = polyglot_intercept_shadow_stock_reduction(4, null, $item);

        // No _master_id meta → WC handles it normally (quantity passes through).
        $this->assertSame(4, $returned);
    }
}

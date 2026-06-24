<?php

/**
 * Exercises the Facebook catalog exclusion (inc/fb-feed.php).
 *
 * facebook-for-woocommerce gates every product (feed + real-time sync) on the
 * `wc_facebook_should_sync_product` filter. Polyglot must return false for any
 * shadow product so its translated duplicates never reach the Meta catalog.
 *
 * @group wc
 */
class FbFeedExclusionTest extends WP_UnitTestCase
{
    private function shouldSync(WC_Product $product): bool
    {
        return (bool) apply_filters('wc_facebook_should_sync_product', true, $product);
    }

    public function testMasterProductIsSynced(): void
    {
        $master = new WC_Product_Simple();
        $master->set_name('Origin');
        $master->set_regular_price('100');
        $master->save();

        $this->assertTrue($this->shouldSync(wc_get_product($master->get_id())));
    }

    public function testShadowProductIsExcluded(): void
    {
        $master = new WC_Product_Simple();
        $master->set_name('Origin');
        $master->save();

        $shadow = new WC_Product_Simple();
        $shadow->set_name('Origin (EN)');
        $shadow->update_meta_data('_master_id', $master->get_id());
        $shadow->update_meta_data('_locale', 'en_IE');
        $shadow->save();

        $this->assertFalse($this->shouldSync(wc_get_product($shadow->get_id())));
    }

    public function testShadowVariationIsExcludedViaParent(): void
    {
        $master = new WC_Product_Simple();
        $master->set_name('Variable master');
        $master->save();

        // A shadow variable product: the variation itself has no _master_id,
        // so exclusion must be resolved through the parent.
        $shadowParent = new WC_Product_Variable();
        $shadowParent->set_name('Variable shadow');
        $shadowParent->update_meta_data('_master_id', $master->get_id());
        $shadowParent->update_meta_data('_locale', 'en_IE');
        $shadowParent->save();

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($shadowParent->get_id());
        $variation->set_regular_price('50');
        $variation->save();

        $this->assertSame('', get_post_meta($variation->get_id(), '_master_id', true), 'variation should carry no _master_id of its own');
        $this->assertFalse($this->shouldSync(wc_get_product($variation->get_id())));
    }

    public function testEarlierExclusionIsPreserved(): void
    {
        $master = new WC_Product_Simple();
        $master->set_name('Already excluded');
        $master->save();

        // A prior filter (e.g. another plugin) already excluded the product:
        // polyglot must never re-enable it.
        $this->assertFalse(polyglot_fb_exclude_shadows(false, wc_get_product($master->get_id())));
    }

    public function testNonProductValueIsLeftUntouched(): void
    {
        $this->assertTrue(polyglot_fb_exclude_shadows(true, null));
    }
}

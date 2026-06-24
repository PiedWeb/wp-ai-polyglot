<?php

/**
 * Exercises the per-domain currency filter, FX price conversion and the Google
 * product feed (inc/exchange-rates.php, inc/feed.php) against real WC products.
 *
 * The WC test bootstrap adds a DKK locale (dk.test / da_DK) so conversion from
 * the EUR base currency can be exercised. Rate is pinned to 7.0 (margin 0).
 *
 * @group wc
 */
class GoogleFeedTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        update_option('polyglot_exchange_rates', [
            'base' => 'EUR',
            'date' => '2026-01-01',
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'margin' => 0.0,
            'rates' => ['EUR' => 1.0, 'DKK' => 7.0],
            'raw_rates' => ['DKK' => 7.0],
        ]);

        $_SERVER['HTTP_HOST'] = 'master.test';
    }

    public function tear_down(): void
    {
        delete_option('polyglot_exchange_rates');
        $_SERVER['HTTP_HOST'] = 'master.test';
        parent::tear_down();
    }

    private function makeMaster(string $price = '100', string $sku = 'ORIGIN'): WC_Product_Simple
    {
        $p = new WC_Product_Simple();
        $p->set_name('Origin');
        $p->set_sku($sku);
        $p->set_regular_price($price);
        $p->set_status('publish');
        $p->save();

        return $p;
    }

    private function makeShadow(int $master_id, string $locale, ?string $price = null): WC_Product_Simple
    {
        $p = new WC_Product_Simple();
        $p->set_name('Origin '.$locale);
        if (null !== $price) {
            $p->set_regular_price($price);
        }
        $p->update_meta_data('_master_id', $master_id);
        $p->update_meta_data('_locale', $locale);
        $p->set_status('publish');
        $p->save();

        return $p;
    }

    /**
     * @param array<string, string> $prices keyed by variation attribute value
     */
    private function makeVariableMaster(string $sku = 'BEAM', array $prices = ['s' => '100', 'l' => '200']): WC_Product_Variable
    {
        $attribute = new WC_Product_Attribute();
        $attribute->set_name('Size');
        $attribute->set_options(array_map('strtoupper', array_keys($prices)));
        $attribute->set_visible(true);
        $attribute->set_variation(true);

        $parent = new WC_Product_Variable();
        $parent->set_name('Beam');
        $parent->set_sku($sku);
        $parent->set_attributes([$attribute]);
        $parent->set_status('publish');
        $parent->save();

        foreach ($prices as $value => $price) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id($parent->get_id());
            $variation->set_attributes(['size' => $value]);
            $variation->set_regular_price($price);
            $variation->set_status('publish');
            $variation->save();
        }

        return $parent;
    }

    // --- Currency filter ---

    public function testCurrencyIsBaseOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $this->assertSame('EUR', get_woocommerce_currency());
    }

    public function testCurrencyIsLocalOnShadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame('DKK', get_woocommerce_currency());
    }

    // --- FX conversion ---

    public function testConvertUsesStoredRate(): void
    {
        $this->assertSame(700.0, polyglot_convert_price(100.0, 'DKK'));
    }

    public function testConvertBaseCurrencyIsIdentity(): void
    {
        $this->assertSame(50.0, polyglot_convert_price(50.0, 'EUR'));
    }

    public function testTargetCurrenciesExcludesBase(): void
    {
        $this->assertContains('DKK', polyglot_fx_target_currencies());
        $this->assertNotContains('EUR', polyglot_fx_target_currencies());
    }

    public function testShadowMasterPriceConvertedToLocalCurrency(): void
    {
        $master = $this->makeMaster('100');
        $shadow = $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame('700', wc_get_product($shadow->get_id())->get_price());
    }

    public function testShadowOwnPriceIsNotConverted(): void
    {
        $master = $this->makeMaster('100');
        $shadow = $this->makeShadow($master->get_id(), 'da_DK', '650');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame('650', wc_get_product($shadow->get_id())->get_price());
    }

    public function testEurShadowPriceNotConverted(): void
    {
        $master = $this->makeMaster('100');
        $shadow = $this->makeShadow($master->get_id(), 'en_IE');

        $_SERVER['HTTP_HOST'] = 'en.test';
        $this->assertSame('100', wc_get_product($shadow->get_id())->get_price());
    }

    // --- Feed (simple products) ---

    public function testFeedIsWellFormed(): void
    {
        $master = $this->makeMaster('100');
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertNotFalse(simplexml_load_string(polyglot_feed_build_xml()));
    }

    public function testFeedSimpleItemHasConvertedPriceAndCurrency(): void
    {
        $master = $this->makeMaster('100');
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $xml = polyglot_feed_build_xml();

        $this->assertStringContainsString('<g:price>700.00 DKK</g:price>', $xml);
        $this->assertStringContainsString('<g:id>ORIGIN</g:id>', $xml); // master SKU
        $this->assertStringContainsString('<g:availability>', $xml);
    }

    public function testFeedExcludesOtherLocales(): void
    {
        $master = $this->makeMaster('100');
        $this->makeShadow($master->get_id(), 'da_DK');
        $this->makeShadow($master->get_id(), 'en_IE');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(1, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    // --- Feed (variations virtualized from master) ---

    public function testVariableShadowVirtualizesMasterVariations(): void
    {
        $parent = $this->makeVariableMaster();

        $shadow = new WC_Product_Variable();
        $shadow->set_name('Beam DK');
        $shadow->set_attributes($parent->get_attributes());
        $shadow->update_meta_data('_master_id', $parent->get_id());
        $shadow->update_meta_data('_locale', 'da_DK');
        $shadow->set_status('publish');
        $shadow->save();

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $xml = polyglot_feed_build_xml();

        // One feed item per master variation, surfaced on the shadow.
        $this->assertSame(2, substr_count($xml, '<item>'));
        // Prices converted EUR→DKK (×7).
        $this->assertStringContainsString('700.00 DKK', $xml);
        $this->assertStringContainsString('1400.00 DKK', $xml);
        // Shared item_group_id anchored on the master SKU.
        $this->assertSame(2, substr_count($xml, '<g:item_group_id>BEAM</g:item_group_id>'));
    }

    public function testMasterVariableFeedUsesOwnVariations(): void
    {
        $this->makeVariableMaster();

        $_SERVER['HTTP_HOST'] = 'master.test';
        $xml = polyglot_feed_build_xml();

        // Non-virtualized branch: master variations, base currency, no conversion.
        $this->assertSame(2, substr_count($xml, '<item>'));
        $this->assertStringContainsString('<g:price>100.00 EUR</g:price>', $xml);
        $this->assertStringContainsString('<g:price>200.00 EUR</g:price>', $xml);
        $this->assertSame(2, substr_count($xml, '<g:item_group_id>BEAM</g:item_group_id>'));
    }

    // --- Edge cases ---

    public function testConvertFallsBackToIdentityWithoutRates(): void
    {
        delete_option('polyglot_exchange_rates');
        $this->assertSame(100.0, polyglot_convert_price(100.0, 'DKK'));
    }

    public function testOfferIdFallsBackToWcPrefixWithoutSku(): void
    {
        $master = new WC_Product_Simple();
        $master->set_name('No SKU');
        $master->set_regular_price('10');
        $master->set_status('publish');
        $master->save();
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertStringContainsString('<g:id>wc_'.$master->get_id().'</g:id>', polyglot_feed_build_xml());
    }

    public function testFeedItemReportsSalePrice(): void
    {
        $master = $this->makeMaster('100', 'SALE');
        $master->set_sale_price('80');
        $master->save();
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $xml = polyglot_feed_build_xml();

        $this->assertStringContainsString('<g:price>700.00 DKK</g:price>', $xml);      // 100 ×7
        $this->assertStringContainsString('<g:sale_price>560.00 DKK</g:sale_price>', $xml); // 80 ×7
    }

    public function testFeedItemReportsOutOfStock(): void
    {
        $master = $this->makeMaster('100', 'OOS');
        $master->set_stock_status('outofstock');
        $master->save();
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertStringContainsString('<g:availability>out_of_stock</g:availability>', polyglot_feed_build_xml());
    }

    public function testFeedExcludesCatalogHiddenProduct(): void
    {
        $master = $this->makeMaster('100', 'HID');
        $shadow = $this->makeShadow($master->get_id(), 'da_DK');
        $shadow->set_catalog_visibility('hidden');
        $shadow->save();

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(0, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    /**
     * A published shadow whose master was later set to `private` (e.g. a one-off
     * custom-order product hidden once fulfilled) must not leak into the feed.
     */
    public function testFeedExcludesShadowOfPrivateMaster(): void
    {
        $master = $this->makeMaster('100', 'PRIV');
        $this->makeShadow($master->get_id(), 'da_DK');
        $master->set_status('private');
        $master->save();

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(0, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    public function testFeedExcludesShadowOfDraftMaster(): void
    {
        $master = $this->makeMaster('100', 'DRFT');
        $this->makeShadow($master->get_id(), 'da_DK');
        $master->set_status('draft');
        $master->save();

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(0, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    public function testFeedExcludesVariableShadowOfPrivateMaster(): void
    {
        $parent = $this->makeVariableMaster('PRIVBEAM');

        $shadow = new WC_Product_Variable();
        $shadow->set_name('Beam DK');
        $shadow->set_attributes($parent->get_attributes());
        $shadow->update_meta_data('_master_id', $parent->get_id());
        $shadow->update_meta_data('_locale', 'da_DK');
        $shadow->set_status('publish');
        $shadow->save();

        $parent->set_status('private');
        $parent->save();

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(0, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    public function testFeedKeepsShadowOfPublishedMaster(): void
    {
        $master = $this->makeMaster('100', 'KEEP');
        $this->makeShadow($master->get_id(), 'da_DK');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $this->assertSame(1, substr_count(polyglot_feed_build_xml(), '<item>'));
    }

    // --- FX markup (covers payment-conversion cost) ---

    public function testMarkupAppliedOnTopOfRate(): void
    {
        $cb = static fn ($value, $currency) => 'DKK' === $currency ? 0.05 : $value;
        add_filter('polyglot_fx_markup', $cb, 10, 2);
        // rate 7.0 × (1 + 0.05) = 7.35 → 100 → 735
        $this->assertSame(735.0, polyglot_convert_price(100.0, 'DKK'));
        remove_filter('polyglot_fx_markup', $cb, 10);
    }

    public function testMarkupNeverAppliedToBaseCurrency(): void
    {
        $cb = static fn () => 0.20;
        add_filter('polyglot_fx_markup', $cb, 10, 2);
        $this->assertSame(100.0, polyglot_convert_price(100.0, 'EUR'));
        remove_filter('polyglot_fx_markup', $cb, 10);
    }

    // --- Shipping cost conversion ---

    public function testShippingRatesConvertedOnShadowDomain(): void
    {
        $rate = new WC_Shipping_Rate('flat', 'Flat', 10.0, [], 'flat_rate');

        $_SERVER['HTTP_HOST'] = 'dk.test';
        $converted = polyglot_convert_shipping_rates(['flat' => $rate]);

        $this->assertSame(70.0, (float) $converted['flat']->get_cost()); // 10 × 7.0
    }

    public function testShippingRatesUntouchedOnBaseDomain(): void
    {
        $rate = new WC_Shipping_Rate('flat', 'Flat', 10.0, [], 'flat_rate');

        $_SERVER['HTTP_HOST'] = 'master.test';
        $converted = polyglot_convert_shipping_rates(['flat' => $rate]);

        $this->assertSame(10.0, (float) $converted['flat']->get_cost());
    }
}

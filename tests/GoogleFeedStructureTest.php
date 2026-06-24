<?php

/**
 * Structural checks for the Google product feed that do not require WooCommerce.
 * (Item mapping / pricing is exercised in tests/wc/GoogleFeedTest.php.)
 *
 * @group feed
 */
class GoogleFeedStructureTest extends WP_UnitTestCase
{
    public function testBuildXmlIsWellFormed(): void
    {
        $xml = polyglot_feed_build_xml();

        $this->assertStringStartsWith('<?xml', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function testGoogleNamespacePresent(): void
    {
        $this->assertStringContainsString(
            'xmlns:g="http://base.google.com/ns/1.0"',
            polyglot_feed_build_xml()
        );
    }

    public function testFeedEnabledByDefault(): void
    {
        $this->assertTrue(polyglot_feed_enabled());
    }

    public function testQueryVarRegistered(): void
    {
        $this->assertContains('polyglot_feed', apply_filters('query_vars', []));
    }

    public function testFlushCacheRunsWithoutError(): void
    {
        polyglot_feed_flush_cache();
        $this->assertFalse(get_transient('polyglot_feed_google_fr_FR'));
    }

    public function testCleanTextDecodesEntitiesAndCollapsesWhitespace(): void
    {
        // &nbsp; baked into a title must not survive as a double-escaped entity.
        $this->assertSame('VARAPPE 5 - 7', polyglot_feed_clean_text('VARAPPE&nbsp;5&nbsp;-&nbsp;7'));
        $this->assertSame('a b', polyglot_feed_clean_text("a   \n  b"));
        $this->assertSame('Tom & Co', polyglot_feed_clean_text('Tom &amp; Co'));
    }
}

<?php

/**
 * Guards the cross-domain cart bridge against full-page caching
 * (polyglot_bar_session_arg in inc/frontend.php).
 *
 * The suggestion bar renders on wp_body_open, so it touches every page. Its
 * session token names one visitor's cart and the receiver
 * (polyglot_restore_session_from_url) accepts any valid signature for 60s. If a
 * cache ever froze that token into shared HTML, the next anonymous visitor
 * clicking through would adopt the first visitor's cart.
 *
 * @group wc
 */
class BarSessionLeakTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        if (null === WC()->session) {
            WC()->initialize_session();
        }
        WC()->cart->empty_cart();
    }

    public function tear_down(): void
    {
        unset($_GET['wc_session']);
        WC()->cart->empty_cart();
        parent::tear_down();
    }

    private function addProductToCart(): void
    {
        $product = new WC_Product_Simple();
        $product->set_name('Origin');
        $product->set_regular_price('100');
        $product->save();

        WC()->cart->add_to_cart($product->get_id());
    }

    /**
     * The regression that matters, walked in one test on purpose: DONOTCACHEPAGE is a
     * process-wide constant that cannot be unset, so the empty-cart half has to be
     * observed before anything defines it. Keep this method first in the class.
     */
    public function testCachingOptOutFollowsTheCart(): void
    {
        polyglot_bar_session_arg();
        $this->assertFalse(
            defined('DONOTCACHEPAGE'),
            'An empty cart emits no token, so the page must stay cacheable — this is what keeps ~all traffic cacheable.'
        );

        $this->addProductToCart();
        polyglot_bar_session_arg();
        $this->assertTrue(
            defined('DONOTCACHEPAGE'),
            'A response carrying a session token must never be storable by a page cache.'
        );
        $this->assertTrue(DONOTCACHEPAGE);
    }

    public function testEmptyCartEmitsNoToken(): void
    {
        $this->assertSame(
            '',
            polyglot_bar_session_arg(),
            'An empty cart has nothing to carry over — the HTML must stay identical for every visitor so the page remains cacheable.'
        );
    }

    public function testFilledCartEmitsASignedToken(): void
    {
        $this->addProductToCart();

        $arg = polyglot_bar_session_arg();

        $this->assertNotSame('', $arg, 'A non-empty cart must still be transferable across domains.');

        $parts = explode('|', urldecode($arg));
        $this->assertCount(3, $parts, 'Token shape must stay session_key|expires|signature.');

        [$session_key, $expires, $sig] = $parts;
        $this->assertSame(
            hash_hmac('sha256', $session_key.'|'.$expires, wp_salt('auth')),
            $sig,
            'Signature must remain verifiable by polyglot_restore_session_from_url.'
        );
        $this->assertGreaterThan(time(), (int) $expires);
    }

    public function testMalformedArraySessionTokenIsIgnored(): void
    {
        $this->expectNotToPerformAssertions();
        $_GET['wc_session'] = ['invalid'];

        polyglot_restore_session_from_url();
    }
}

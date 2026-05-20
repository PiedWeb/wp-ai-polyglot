<?php

/**
 * Covers the WooCommerce checkout + email translation maps in inc/wc-i18n.php.
 * These functions only depend on the current locale (HTTP_HOST), not on
 * WooCommerce being loaded, so they can be unit-tested directly.
 *
 * @group wc-i18n
 */
class CheckoutI18nTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        $_SERVER['HTTP_HOST'] = 'en.test'; // shadow (en_IE) by default
    }

    public function tear_down(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        parent::tear_down();
    }

    // --- Checkout privacy text ---

    public function testCheckoutPrivacyUnchangedOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $original = 'Texte FR original';
        $this->assertSame($original, polyglot_translate_checkout_privacy($original));
    }

    public function testCheckoutPrivacyTranslatedOnEnglishShadow(): void
    {
        $out = polyglot_translate_checkout_privacy('Texte FR original');
        $this->assertStringContainsString('Your personal data will be used', $out);
        $this->assertStringContainsString('[privacy_policy]', $out);
        $this->assertStringNotContainsString('Texte FR original', $out);
    }

    public function testCheckoutPrivacyTranslatedOnSpanishShadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';
        $out = polyglot_translate_checkout_privacy('Texte FR original');
        $this->assertStringContainsString('Tus datos personales', $out);
        $this->assertStringContainsString('[privacy_policy]', $out);
    }

    // --- Checkout terms text ---

    public function testCheckoutTermsUnchangedOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $original = 'Conditions FR';
        $this->assertSame($original, polyglot_translate_checkout_terms($original));
    }

    public function testCheckoutTermsTranslatedOnShadow(): void
    {
        $out = polyglot_translate_checkout_terms('Conditions FR');
        $this->assertStringContainsString('I have read and agree', $out);
        $this->assertStringContainsString('[terms]', $out);
    }

    public function testCheckoutTermsSpanishShadow(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';
        $out = polyglot_translate_checkout_terms('Conditions FR');
        $this->assertStringContainsString('He leído y acepto', $out);
        $this->assertStringContainsString('[terms]', $out);
    }

    // --- Email additional content ---

    public function testEmailAdditionalContentUnchangedOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $email = (object) ['id' => 'customer_completed_order'];
        $this->assertSame('FR content', polyglot_translate_email_additional_content('FR content', null, $email));
    }

    public function testEmailAdditionalContentTranslatedOnShadow(): void
    {
        $email = (object) ['id' => 'customer_completed_order'];
        $out = polyglot_translate_email_additional_content('FR content', null, $email);
        $this->assertSame('Thanks for your purchase.', $out);
    }

    public function testEmailAdditionalContentUnknownEmailFallsBack(): void
    {
        $email = (object) ['id' => 'some_unmapped_email'];
        $this->assertSame('FR content', polyglot_translate_email_additional_content('FR content', null, $email));
    }

    public function testEmailAdditionalContentSiteUrlPlaceholderReplaced(): void
    {
        // customer_processing_order uses the {site_url} placeholder map.
        $email = (object) ['id' => 'customer_processing_order'];
        $out = polyglot_translate_email_additional_content('FR content', null, $email);
        $this->assertStringContainsString('en.test', $out);
        $this->assertStringNotContainsString('{site_url}', $out);
    }

    // --- Email footer ---

    public function testEmailFooterUnchangedOnMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';
        $text = '<a href="https://master.test">master.test</a>';
        $this->assertSame($text, polyglot_translate_email_footer($text));
    }

    public function testEmailFooterRewritesDomainOnShadow(): void
    {
        $text = 'Visit <a href="https://master.test">master.test</a> today';
        $out = polyglot_translate_email_footer($text);
        $this->assertStringContainsString('en.test', $out);
        $this->assertStringNotContainsString('master.test', $out);
    }
}

<?php

/**
 * @group helpers
 */
class HelpersTest extends WP_UnitTestCase
{
    public function test_get_current_locale_defaults_to_french(): void
    {
        $_SERVER['HTTP_HOST'] = 'unknown.test';

        $this->assertSame('fr_FR', polyglot_get_current_locale());
    }

    public function test_get_current_locale_returns_shadow_locale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertSame('en_IE', polyglot_get_current_locale());
    }

    public function test_is_master_on_master_domain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertTrue(polyglot_is_master());
    }

    public function test_is_master_on_shadow_domain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertFalse(polyglot_is_master());
    }

    public function test_get_master_authority(): void
    {
        $this->assertSame('master.test', polyglot_get_master_authority());
    }

    public function test_authority_to_url_local(): void
    {
        $this->assertSame('http://127.0.0.1:9172', polyglot_authority_to_url('127.0.0.1:9172'));
        $this->assertSame('http://localhost:8080', polyglot_authority_to_url('localhost:8080'));
    }

    public function test_authority_to_url_production(): void
    {
        $this->assertSame('https://www.woodrock.fr', polyglot_authority_to_url('www.woodrock.fr'));
        $this->assertSame('https://woodrockclimbing.com', polyglot_authority_to_url('woodrockclimbing.com'));
    }

    public function test_get_current_entry_falls_back_to_master_in_cli(): void
    {
        $_SERVER['HTTP_HOST'] = 'unknown.test';

        $entry = polyglot_get_current_entry();
        $this->assertSame('fr_FR', $entry['locale']);
        $this->assertTrue($entry['master']);
    }

    public function test_get_current_entry_returns_config(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';

        $entry = polyglot_get_current_entry();
        $this->assertSame('es_ES', $entry['locale']);
        $this->assertSame('es', $entry['hreflang']);
        $this->assertSame('EUR', $entry['currency']);
    }
}

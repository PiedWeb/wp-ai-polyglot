<?php

/**
 * @group helpers
 */
class HelpersTest extends WP_UnitTestCase
{
    public function testGetCurrentLocaleDefaultsToFrench(): void
    {
        $_SERVER['HTTP_HOST'] = 'unknown.test';

        $this->assertSame('fr_FR', polyglot_get_current_locale());
    }

    public function testGetCurrentLocaleReturnsShadowLocale(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertSame('en_IE', polyglot_get_current_locale());
    }

    public function testIsMasterOnMasterDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $this->assertTrue(polyglot_is_master());
    }

    public function testIsMasterOnShadowDomain(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $this->assertFalse(polyglot_is_master());
    }

    public function testGetMasterAuthority(): void
    {
        $this->assertSame('master.test', polyglot_get_master_authority());
    }

    public function testAuthorityToUrlLocal(): void
    {
        $this->assertSame('http://127.0.0.1:9172', polyglot_authority_to_url('127.0.0.1:9172'));
        $this->assertSame('http://localhost:8080', polyglot_authority_to_url('localhost:8080'));
    }

    public function testAuthorityToUrlProduction(): void
    {
        $this->assertSame('https://www.woodrock.fr', polyglot_authority_to_url('www.woodrock.fr'));
        $this->assertSame('https://woodrockclimbing.com', polyglot_authority_to_url('woodrockclimbing.com'));
    }

    public function testGetCurrentEntryFallsBackToMasterInCli(): void
    {
        $_SERVER['HTTP_HOST'] = 'unknown.test';

        $entry = polyglot_get_current_entry();
        $this->assertSame('fr_FR', $entry['locale']);
        $this->assertTrue($entry['master']);
    }

    public function testGetCurrentEntryReturnsConfig(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';

        $entry = polyglot_get_current_entry();
        $this->assertSame('es_ES', $entry['locale']);
        $this->assertSame('es', $entry['hreflang']);
        $this->assertSame('EUR', $entry['currency']);
    }

    public function testExportableStatusesIncludesPublishAndDraft(): void
    {
        $statuses = polyglot_exportable_statuses();

        $this->assertContains('publish', $statuses);
        $this->assertContains('draft', $statuses);
    }

    public function testExportableStatusesExcludesNonContentStatuses(): void
    {
        $statuses = polyglot_exportable_statuses();

        $this->assertNotContains('auto-draft', $statuses);
        $this->assertNotContains('trash', $statuses);
        $this->assertNotContains('inherit', $statuses);
    }

    public function testExportableStatusesIsFilterable(): void
    {
        $filter = static fn (): array => ['publish'];
        add_filter('polyglot_exportable_statuses', $filter);

        $this->assertSame(['publish'], polyglot_exportable_statuses());

        remove_filter('polyglot_exportable_statuses', $filter);
    }
}

<?php

/**
 * @group doctor
 */
class DoctorTest extends WP_UnitTestCase
{
    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();
        $this->cli = new Polyglot_CLI();
    }

    /**
     * Run the offline doctor checks and index the rows by name.
     *
     * @return array<string, array{name:string, status:string, detail:string}>
     */
    private function checks(): array
    {
        $method = new ReflectionMethod(Polyglot_CLI::class, 'run_doctor_checks');
        $method->setAccessible(true);
        $by_name = [];
        foreach ($method->invoke($this->cli, true) as $row) {
            $by_name[$row['name']] = $row;
        }

        return $by_name;
    }

    private function make_master(string $status = 'publish'): int
    {
        return self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => $status,
            'post_title' => 'Master',
        ]);
    }

    private function make_shadow(int $master_id, string $locale, string $status = 'publish'): int
    {
        $GLOBALS['polyglot_pending_locale'] = $locale;
        $sid = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => $status,
            'post_title' => 'Shadow '.$locale,
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($sid, '_master_id', $master_id);
        update_post_meta($sid, '_locale', $locale);

        return $sid;
    }

    public function test_clean_setup_passes(): void
    {
        $master = $this->make_master();
        $this->make_shadow($master, 'en_IE');

        $checks = $this->checks();
        $this->assertSame('ok', $checks['Locale config']['status']);
        $this->assertSame('ok', $checks['Orphan shadows']['status']);
        $this->assertSame('ok', $checks['Shadow locales']['status']);
        $this->assertSame('ok', $checks['Status parity']['status']);
        $this->assertSame('ok', $checks['hreflang uniqueness']['status']);
        // Test locales are all EUR → no conversion needed.
        $this->assertSame('ok', $checks['Exchange rates']['status']);
    }

    public function test_orphan_shadow_fails(): void
    {
        // _master_id points to a post that does not exist.
        $this->make_shadow(999999, 'en_IE');

        $this->assertSame('fail', $this->checks()['Orphan shadows']['status']);
    }

    public function test_invalid_shadow_locale_fails(): void
    {
        $master = $this->make_master();
        $this->make_shadow($master, 'xx_XX'); // not in POLYGLOT_LOCALES

        $this->assertSame('fail', $this->checks()['Shadow locales']['status']);
    }

    public function test_status_drift_warns(): void
    {
        $master = $this->make_master('publish');
        $this->make_shadow($master, 'en_IE', 'draft');

        $this->assertSame('warn', $this->checks()['Status parity']['status']);
    }

    public function test_duplicate_hreflang_pair_fails(): void
    {
        $master = $this->make_master();
        $this->make_shadow($master, 'en_IE');
        $this->make_shadow($master, 'en_IE'); // two EN shadows for one master

        $this->assertSame('fail', $this->checks()['hreflang uniqueness']['status']);
    }
}

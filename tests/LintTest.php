<?php

/**
 * @group lint
 */
class LintTest extends WP_UnitTestCase
{
    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();
        $this->cli = new Polyglot_CLI();
    }

    /** @return array<int, array<string, mixed>> */
    private function findings(): array
    {
        $method = new ReflectionMethod(Polyglot_CLI::class, 'run_lint');
        $method->setAccessible(true);

        return $method->invoke($this->cli, null);
    }

    /** @return array{0:int, 1:int} [master_id, shadow_id] */
    private function make_pair(string $master_html, string $shadow_html, string $locale = 'en_IE'): array
    {
        $master = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Master',
            'post_content' => $master_html,
        ]);

        $GLOBALS['polyglot_pending_locale'] = $locale;
        $shadow = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Shadow',
            'post_content' => $shadow_html,
        ]);
        unset($GLOBALS['polyglot_pending_locale']);
        update_post_meta($shadow, '_master_id', $master);
        update_post_meta($shadow, '_locale', $locale);

        return [$master, $shadow];
    }

    public function test_faithful_translation_has_no_findings(): void
    {
        $this->make_pair(
            '<p><a href="/a">x</a> [breadcrumb]</p>',
            '<p><a href="/b">y</a> [breadcrumb]</p>'
        );

        $this->assertSame([], $this->findings());
    }

    public function test_missing_shortcode_is_a_failure(): void
    {
        $this->make_pair('<p>[wr_calcul_charge]</p>', '<p>tool gone</p>');

        $fails = array_values(array_filter($this->findings(), static fn ($f) => 'fail' === $f['status']));
        $this->assertNotEmpty($fails);
        $this->assertSame('parity', $fails[0]['type']);
    }

    public function test_missing_placeholder_is_a_failure(): void
    {
        $this->make_pair('<p>Total %s due</p>', '<p>Total due</p>');

        $this->assertContains('fail', array_column($this->findings(), 'status'));
    }

    public function test_link_drift_is_a_warning_not_a_failure(): void
    {
        $this->make_pair(
            '<p><a href="/a">1</a><a href="/b">2</a></p>',
            '<p><a href="/a">1</a></p>'
        );

        $statuses = array_column($this->findings(), 'status');
        $this->assertContains('warn', $statuses);
        $this->assertNotContains('fail', $statuses);
    }

    public function test_residual_french_is_a_warning(): void
    {
        $this->make_pair(
            '<p>with for you we your our this also</p>',
            '<p>avec pour vous nous votre notre cette aussi</p>'
        );

        $this->assertContains('residual-fr', array_column($this->findings(), 'type'));
    }

    public function test_stale_src_hash_is_a_warning(): void
    {
        [, $shadow] = $this->make_pair('<p>same body</p>', '<p>same body</p>');
        update_post_meta($shadow, '_src_hash', 'stale-hash-value');

        $this->assertContains('stale', array_column($this->findings(), 'type'));
    }

    public function test_length_anomaly_is_a_warning(): void
    {
        // Master ~320 visible chars, shadow a handful → ratio well below 0.5.
        $this->make_pair('<p>'.str_repeat('mot ', 80).'</p>', '<p>court</p>');

        $this->assertContains('length', array_column($this->findings(), 'type'));
    }
}

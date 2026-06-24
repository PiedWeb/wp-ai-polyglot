<?php

/**
 * Auto-export-on-save: local gate, save-handler guards, and the background
 * drain worker (single-flight + coalescing).
 *
 * @group autoexport
 */
class AutoExportTest extends WP_UnitTestCase
{
    private string $export_dir;
    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();
        $this->export_dir = polyglot_translations_dir();
        $this->cli = new Polyglot_CLI();
        delete_transient('polyglot_export_pending');
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'polyglot_sync'));
        delete_transient('polyglot_sync_lock_info');
        delete_transient('polyglot_export_pending');
        delete_transient('polyglot_export_spawn_guard');
        remove_all_filters('home_url');
        remove_all_filters('polyglot_exportable_statuses');
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    // ----- Local-only gate (prod safety) -----

    public function test_enabled_false_on_non_local_home(): void
    {
        add_filter('home_url', static fn () => 'https://www.woodrock.fr');

        $this->assertFalse(polyglot_autoexport_enabled());
    }

    public function test_enabled_true_on_loopback_home(): void
    {
        add_filter('home_url', static fn () => 'http://127.0.0.1:9172');
        $this->assertTrue(polyglot_autoexport_enabled());

        remove_all_filters('home_url');
        add_filter('home_url', static fn () => 'http://localhost:9173');
        $this->assertTrue(polyglot_autoexport_enabled());
    }

    // ----- Save handler: recursion guard under WP-CLI -----

    public function test_on_save_does_not_arm_under_wp_cli(): void
    {
        // The plugin test bootstrap defines WP_CLI = true; the first guard in
        // polyglot_autoexport_on_save() must bail so CLI imports never recurse
        // into an export (no spawn, no queue armed).
        $this->assertTrue(defined('WP_CLI') && WP_CLI, 'precondition: running as WP_CLI');

        $id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);

        polyglot_autoexport_on_save($id, get_post($id), true, null);

        $this->assertFalse(get_transient('polyglot_export_pending'));
    }

    public function test_on_delete_does_not_arm_under_wp_cli(): void
    {
        // Same recursion guard as the save handler: CLI deletes (e.g. during an
        // import that prunes shadows) must never spawn a background export.
        $this->assertTrue(defined('WP_CLI') && WP_CLI, 'precondition: running as WP_CLI');

        $id = self::factory()->post->create([
            'post_type'   => 'page',
            'post_status' => 'publish',
        ]);

        polyglot_autoexport_on_delete($id, get_post($id));

        $this->assertFalse(get_transient('polyglot_export_pending'));
    }

    // ----- Drain worker: single-flight -----

    public function test_worker_bails_and_writes_nothing_when_lock_held(): void
    {
        // Another process holds the sync lock via a separate connection.
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $conn->query("SELECT GET_LOCK('polyglot_sync', 0)");

        set_transient('polyglot_export_pending', time(), 600);

        try {
            $this->invoke_drain();

            $this->assertFileDoesNotExist(
                $this->export_dir . '/translations.tsv',
                'Worker must not run an export while the lock is held'
            );
            $this->assertNotFalse(
                get_transient('polyglot_export_pending'),
                'Pending flag must survive a bail so the lock holder drains it'
            );
        } finally {
            $conn->query("SELECT RELEASE_LOCK('polyglot_sync')");
            $conn->close();
        }
    }

    // ----- Drain worker: happy path -----

    public function test_worker_runs_export_clears_pending_releases_lock(): void
    {
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'Auto Export',
            'post_name'    => 'auto-export',
            'post_content' => '<p>contenu</p>',
            'post_status'  => 'publish',
        ]);
        set_transient('polyglot_export_pending', time(), 600);

        $this->invoke_drain();

        $this->assertFileExists($this->export_dir . '/translations.tsv');
        $this->assertFalse(get_transient('polyglot_export_pending'), 'Pending flag should be consumed');
        $this->assertFalse(get_transient('polyglot_sync_lock_info'), 'Lock info transient should be cleared');

        // Lock must be free again.
        global $wpdb;
        $reacquired = (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', 'polyglot_sync', 0));
        $this->assertSame('1', $reacquired, 'Sync lock should be released after the worker finishes');
    }

    // ----- Drain worker: coalescing (trailing export) -----

    public function test_worker_loops_again_and_trailing_pass_exports_fresh_content(): void
    {
        global $wpdb;

        $id = self::factory()->post->create([
            'post_type'    => 'page',
            'post_title'   => 'Coalesce',
            'post_name'    => 'coalesce',
            'post_content' => '<p>V1</p>',
            'post_status'  => 'publish',
        ]);
        $file = $this->export_dir . '/page-' . $id . '-coalesce/fr_FR.html';

        // Warm this process's object cache with V1, mimicking the first pass
        // having already loaded the post before the "save" lands.
        get_post($id);

        // run_export_core() calls polyglot_exportable_statuses() once per pass.
        // On the FIRST pass, simulate a Gutenberg save in another process:
        // change the DB row directly (so THIS process's cache is NOT cleaned)
        // and re-arm the pending flag. The worker must run a trailing pass AND
        // that pass must read the fresh row — which only works if it flushes
        // the warm cache between passes.
        $passes = 0;
        add_filter('polyglot_exportable_statuses', function (array $statuses) use (&$passes, $wpdb, $id): array {
            if (0 === $passes++) {
                $wpdb->update($wpdb->posts, ['post_content' => '<p>V2</p>'], ['ID' => $id]);
                set_transient('polyglot_export_pending', time(), 600);
            }

            return $statuses;
        });

        $this->invoke_drain();

        $this->assertSame(2, $passes, 'A save mid-export must trigger exactly one trailing export');
        $this->assertStringContainsString(
            '<p>V2</p>',
            file_get_contents($file),
            'Trailing pass must export the fresh DB content, not the cached pass-1 version'
        );
        $this->assertFalse(get_transient('polyglot_export_pending'), 'Trailing pass should consume the flag');
    }

    // ----- Drain queued save after a manual import / export -----

    public function test_manual_export_drains_queued_save_when_local(): void
    {
        add_filter('home_url', static fn () => 'http://127.0.0.1:9172');
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_name'    => 'drain-x',
            'post_content' => '<p>x</p>',
            'post_status'  => 'publish',
        ]);
        // A wp-admin save armed the queue while the (manual) export held the lock.
        set_transient('polyglot_export_pending', time(), 600);

        $this->cli->export([], []);

        $this->assertFalse(
            get_transient('polyglot_export_pending'),
            'A manual export must drain a save queued during it (export alone never clears the flag)'
        );
    }

    public function test_import_drains_queued_save_when_local(): void
    {
        add_filter('home_url', static fn () => 'http://127.0.0.1:9172');
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_name'    => 'drain-y',
            'post_content' => '<p>y</p>',
            'post_status'  => 'publish',
        ]);
        $this->cli->export([], []);                       // produce a TSV for import to read
        set_transient('polyglot_export_pending', time(), 600);

        $this->cli->import([], []);

        $this->assertFalse(
            get_transient('polyglot_export_pending'),
            'An import must drain a save queued while it held the lock'
        );
    }

    public function test_command_does_not_drain_when_disabled(): void
    {
        // home_url defaults to example.org in tests => auto-export is disabled,
        // so a manual export must NOT spawn a drain (prod safety).
        self::factory()->post->create([
            'post_type'    => 'page',
            'post_name'    => 'drain-z',
            'post_content' => '<p>z</p>',
            'post_status'  => 'publish',
        ]);
        set_transient('polyglot_export_pending', time(), 600);

        $this->cli->export([], []);

        $this->assertNotFalse(
            get_transient('polyglot_export_pending'),
            'Must not drain when auto-export is disabled (non-local environment)'
        );
    }

    private function invoke_drain(): void
    {
        $method = new ReflectionMethod(Polyglot_CLI::class, 'export_worker_drain');
        $method->setAccessible(true);
        $method->invoke($this->cli, []);
    }

    private function recursive_rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}

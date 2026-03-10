<?php

/**
 * @group synclock
 */
class SyncLockTest extends WP_UnitTestCase
{
    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();
        $this->cli = new Polyglot_CLI();
    }

    public function tear_down(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'polyglot_sync'));
        delete_transient('polyglot_sync_lock_info');
        parent::tear_down();
    }

    public function test_acquire_lock_succeeds_when_free(): void
    {
        $method = new ReflectionMethod(Polyglot_CLI::class, 'acquire_sync_lock');
        $method->setAccessible(true);

        // Should not throw
        $method->invoke($this->cli, 'export');

        $info = get_transient('polyglot_sync_lock_info');
        $this->assertIsArray($info);
        $this->assertSame('export', $info['command']);
        $this->assertSame(getmypid(), $info['pid']);
    }

    public function test_acquire_lock_fails_when_held(): void
    {
        global $wpdb;

        // Simulate another process holding the lock via a separate connection
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        $conn->query("SELECT GET_LOCK('polyglot_sync', 0)");

        // Store diagnostic info like a real caller would
        set_transient('polyglot_sync_lock_info', [
            'command' => 'import',
            'pid' => 99999,
            'started' => '2026-01-01 00:00:00',
        ], 3600);

        $method = new ReflectionMethod(Polyglot_CLI::class, 'acquire_sync_lock');
        $method->setAccessible(true);

        try {
            $method->invoke($this->cli, 'export');
            $this->fail('Expected RuntimeException from WP_CLI::error()');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        } finally {
            $conn->query("SELECT RELEASE_LOCK('polyglot_sync')");
            $conn->close();
        }
    }

    public function test_release_lock_clears_transient(): void
    {
        $acquire = new ReflectionMethod(Polyglot_CLI::class, 'acquire_sync_lock');
        $acquire->setAccessible(true);
        $acquire->invoke($this->cli, 'export');

        $release = new ReflectionMethod(Polyglot_CLI::class, 'release_sync_lock');
        $release->setAccessible(true);
        $release->invoke($this->cli);

        $this->assertFalse(get_transient('polyglot_sync_lock_info'));
    }

    public function test_lock_reacquirable_after_release(): void
    {
        $acquire = new ReflectionMethod(Polyglot_CLI::class, 'acquire_sync_lock');
        $acquire->setAccessible(true);
        $release = new ReflectionMethod(Polyglot_CLI::class, 'release_sync_lock');
        $release->setAccessible(true);

        $acquire->invoke($this->cli, 'export');
        $release->invoke($this->cli);

        // Should succeed again
        $acquire->invoke($this->cli, 'import');
        $info = get_transient('polyglot_sync_lock_info');
        $this->assertSame('import', $info['command']);
    }
}

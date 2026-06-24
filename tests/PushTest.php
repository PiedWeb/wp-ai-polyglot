<?php

/**
 * @group write
 *
 * Covers `wp polyglot push` — the out-of-band reconciler for flat edits the
 * PostToolUse hook didn't see (vim, git pull, batch).
 */
class PushTest extends WP_UnitTestCase
{
    private string $export_dir;

    private int $master_id;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        $this->export_dir = polyglot_translations_dir();
        $this->cli = new Polyglot_CLI();

        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_name' => 'a-propos',
            'post_content' => '<p>Notre histoire</p>',
            'post_status' => 'publish',
        ]);
    }

    public function tear_down(): void
    {
        if (is_dir($this->export_dir)) {
            $this->recursive_rmdir($this->export_dir);
        }
        parent::tear_down();
    }

    public function testPushAllAppliesFlatEditAndConvergesFile(): void
    {
        $this->cli->export([], []);
        $file = $this->export_dir.'/page-'.$this->master_id.'/fr_FR.html';

        // Edit the body in place, keeping the (valid) base etag — as a vim user would.
        [$fm] = polyglot_flat_parse(file_get_contents($file));
        file_put_contents($file, polyglot_flat_serialize($fm, '<p>Édité via flat</p>'));

        $this->cli->push([], ['all' => true]);

        $this->assertSame('<p>Édité via flat</p>', get_post($this->master_id)->post_content, 'push must apply the flat edit to the DB');

        // The file self-converged: its etag now matches the new DB state.
        $after = polyglot_flat_parse(file_get_contents($file))[0];
        $this->assertSame(polyglot_post_etag(get_post($this->master_id)), $after['etag'], 'push must write the canonical etag back');
    }

    public function testPushReportsConflictAndLeavesFileAndDbUntouched(): void
    {
        $this->cli->export([], []);
        $file = $this->export_dir.'/page-'.$this->master_id.'/fr_FR.html';

        // The DB changes after export (the flat file's etag is now stale).
        wp_update_post(['ID' => $this->master_id, 'post_content' => '<p>DB a changé</p>']);
        $before = file_get_contents($file);

        $this->cli->push([], ['all' => true]);

        $this->assertSame('<p>DB a changé</p>', get_post($this->master_id)->post_content, 'A conflicting push must not clobber the DB');
        $this->assertSame($before, file_get_contents($file), 'A conflicting push must leave the file for manual resolution');
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

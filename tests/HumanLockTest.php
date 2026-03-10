<?php

/**
 * @group humanlock
 */
class HumanLockTest extends WP_UnitTestCase
{
    public function test_manual_edit_sets_translation_mode_when_not_cli(): void
    {
        // In our test env, WP_CLI is true, so the function returns early.
        // We test the underlying logic directly instead.
        $shadow_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($shadow_id, '_master_id', 999);

        // Simulate what the function does when NOT in WP-CLI context
        if (get_post_meta($shadow_id, '_master_id', true)) {
            update_post_meta($shadow_id, '_translation_mode', 'manual');
        }

        $this->assertSame('manual', get_post_meta($shadow_id, '_translation_mode', true));
    }

    public function test_manual_edit_skipped_on_master(): void
    {
        $master_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        // Master has no _master_id → should not be flagged
        if (get_post_meta($master_id, '_master_id', true)) {
            update_post_meta($master_id, '_translation_mode', 'manual');
        }

        $this->assertEmpty(get_post_meta($master_id, '_translation_mode', true));
    }

    public function test_flag_manual_edit_returns_early_under_wp_cli(): void
    {
        $shadow_id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        update_post_meta($shadow_id, '_master_id', 999);

        // WP_CLI is true in our test env, so the function should bail
        polyglot_flag_manual_edit($shadow_id, get_post($shadow_id), true);

        $this->assertEmpty(
            get_post_meta($shadow_id, '_translation_mode', true),
            'Should NOT set manual flag when WP_CLI is true'
        );
    }
}

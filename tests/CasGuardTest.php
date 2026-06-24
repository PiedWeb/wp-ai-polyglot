<?php

/**
 * @group write
 *
 * Decision #9: the break-glass path `translate --payload` is optimistic-locked
 * via --if-match, so a concurrent change isn't silently clobbered. (Bulk
 * `import` CAS is covered in ExportImportTest::testImportSkipsStaleConflict…)
 */
class CasGuardTest extends WP_UnitTestCase
{
    private int $master_id;

    private Polyglot_CLI $cli;

    public function set_up(): void
    {
        parent::set_up();

        $this->cli = new Polyglot_CLI();
        $this->master_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_title' => 'À propos',
            'post_name' => 'a-propos',
            'post_content' => '<p>Notre histoire</p>',
            'post_status' => 'publish',
        ]);
    }

    public function testTranslateIfMatchAppliesOnMatch(): void
    {
        $this->translate('About Us', '<p>v1</p>');
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');
        $etag = polyglot_post_etag(get_post($shadow_id));

        $this->translate('About Us v2', '<p>v2</p>', $etag);

        $this->assertSame('About Us v2', get_post($shadow_id)->post_title);
        $this->assertSame('<p>v2</p>', get_post($shadow_id)->post_content);
    }

    public function testTranslateIfMatchSkipsOnMismatch(): void
    {
        $this->translate('About Us', '<p>v1</p>');
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');

        $this->translate('Should Not Apply', '<p>nope</p>', 'staleetag');

        $this->assertSame('About Us', get_post($shadow_id)->post_title, 'Stale If-Match must skip');
        $this->assertSame('<p>v1</p>', get_post($shadow_id)->post_content);
    }

    public function testTranslateIfMatchNewButShadowExistsSkips(): void
    {
        $this->translate('About Us', '<p>v1</p>');
        $shadow_id = $this->find_shadow($this->master_id, 'en_IE');

        $this->translate('Should Not Apply', '<p>nope</p>', 'new');

        $this->assertSame('About Us', get_post($shadow_id)->post_title, 'If-Match=new on an existing shadow must skip');
    }

    // === Helpers ===

    private function translate(string $title, string $desc, ?string $if_match = null): void
    {
        $assoc = [
            'target' => 'en_IE',
            'payload' => json_encode([
                'translated_title' => $title,
                'translated_description' => $desc,
                'translated_short_desc' => '',
                'post_type' => 'page',
            ]),
        ];
        if (null !== $if_match) {
            $assoc['if-match'] = $if_match;
        }
        $this->cli->translate([$this->master_id], $assoc);
    }

    private function find_shadow(int $master_id, string $locale): ?int
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare(
            "SELECT pm1.post_id FROM $wpdb->postmeta pm1
             JOIN $wpdb->postmeta pm2 ON pm1.post_id = pm2.post_id AND pm2.meta_key = '_locale'
             WHERE pm1.meta_key = '_master_id' AND pm1.meta_value = %d
             AND pm2.meta_value = %s LIMIT 1",
            $master_id,
            $locale
        ));

        return $result ? (int) $result : null;
    }
}

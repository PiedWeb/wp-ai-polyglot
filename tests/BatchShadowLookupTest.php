<?php

/**
 * @group helpers
 */
class BatchShadowLookupTest extends WP_UnitTestCase
{
    public function testFindShadowIdsReturnsCorrectMapping(): void
    {
        $master1 = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        $master2 = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        $master3 = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);

        $shadow1_en = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        update_post_meta($shadow1_en, '_master_id', $master1);
        update_post_meta($shadow1_en, '_locale', 'en_IE');

        $shadow2_en = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        update_post_meta($shadow2_en, '_master_id', $master2);
        update_post_meta($shadow2_en, '_locale', 'en_IE');

        // master3 has no English shadow

        $result = polyglot_find_shadow_ids([$master1, $master2, $master3], 'en_IE');

        $this->assertSame($shadow1_en, $result[$master1]);
        $this->assertSame($shadow2_en, $result[$master2]);
        $this->assertArrayNotHasKey($master3, $result);
    }

    public function testFindShadowIdsReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], polyglot_find_shadow_ids([], 'en_IE'));
    }

    public function testFindShadowIdsFiltersLocaleCorrectly(): void
    {
        $master = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);

        $shadow_en = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        update_post_meta($shadow_en, '_master_id', $master);
        update_post_meta($shadow_en, '_locale', 'en_IE');

        $shadow_es = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        update_post_meta($shadow_es, '_master_id', $master);
        update_post_meta($shadow_es, '_locale', 'es_ES');

        $result_en = polyglot_find_shadow_ids([$master], 'en_IE');
        $this->assertSame($shadow_en, $result_en[$master]);

        $result_es = polyglot_find_shadow_ids([$master], 'es_ES');
        $this->assertSame($shadow_es, $result_es[$master]);
    }
}

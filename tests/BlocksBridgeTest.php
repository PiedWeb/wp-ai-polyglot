<?php

/**
 * @group blocks-bridge
 */
class BlocksBridgeTest extends WP_UnitTestCase
{
    private int $master_product;

    private int $shadow_product;

    public function set_up(): void
    {
        parent::set_up();

        $this->master_product = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);
        $this->shadow_product = self::factory()->post->create(['post_type' => 'product', 'post_status' => 'publish']);

        update_post_meta($this->shadow_product, '_master_id', $this->master_product);
        update_post_meta($this->shadow_product, '_locale', 'en_IE');
    }

    public function testMasterDomainNoSwap(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        $block = $this->make_block([$this->master_product]);
        $result = polyglot_swap_block_product_ids($block);

        $this->assertSame([$this->master_product], $result['attrs']['products']);
    }

    public function testShadowDomainSwapsIds(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $block = $this->make_block([$this->master_product]);
        $result = polyglot_swap_block_product_ids($block);

        $this->assertSame([$this->shadow_product], $result['attrs']['products']);
    }

    public function testMissingShadowFallsBackToMaster(): void
    {
        $_SERVER['HTTP_HOST'] = 'es.test';

        $block = $this->make_block([$this->master_product]);
        $result = polyglot_swap_block_product_ids($block);

        // No es_ES shadow exists → falls back to master ID
        $this->assertSame([$this->master_product], $result['attrs']['products']);
    }

    public function testNonTargetBlockUnchanged(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $block = [
            'blockName' => 'core/paragraph',
            'attrs' => ['content' => 'hello'],
        ];
        $result = polyglot_swap_block_product_ids($block);

        $this->assertSame($block, $result);
    }

    public function testEmptyProductsUnchanged(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $block = [
            'blockName' => 'woocommerce/handpicked-products',
            'attrs' => [],
        ];
        $result = polyglot_swap_block_product_ids($block);

        $this->assertSame($block, $result);
    }

    private function make_block(array $ids): array
    {
        return [
            'blockName' => 'woocommerce/handpicked-products',
            'attrs' => [
                'products' => $ids,
                'columns' => 3,
            ],
        ];
    }
}

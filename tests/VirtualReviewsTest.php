<?php

/**
 * @group reviews
 */
class VirtualReviewsTest extends WP_UnitTestCase
{
    private int $master_id;

    private int $shadow_id;

    public function set_up(): void
    {
        parent::set_up();

        $this->master_id = self::factory()->post->create([
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);

        $this->shadow_id = self::factory()->post->create([
            'post_type' => 'product',
            'post_status' => 'publish',
        ]);
        update_post_meta($this->shadow_id, '_master_id', $this->master_id);
        update_post_meta($this->shadow_id, '_locale', 'en_IE');
    }

    public function testReviewCountEqualsRatingCountsSum(): void
    {
        $_SERVER['HTTP_HOST'] = 'master.test';

        // Create two approved reviews with ratings on the master product
        $c1 = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_approved' => 1,
            'comment_type' => 'review',
        ]);
        update_comment_meta($c1, 'rating', 5);
        update_comment_meta($c1, '_source_locale', 'fr_FR');

        $c2 = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_approved' => 1,
            'comment_type' => 'review',
        ]);
        update_comment_meta($c2, 'rating', 3);
        update_comment_meta($c2, '_source_locale', 'fr_FR');

        $counts = polyglot_locale_rating_counts($this->master_id);
        $review_count = polyglot_count_locale_reviews($this->master_id);

        $this->assertSame(array_sum($counts), $review_count);
    }

    public function testReviewSubmissionRedirectsToMaster(): void
    {
        $commentdata = [
            'comment_post_ID' => $this->shadow_id,
            'comment_content' => 'Great product!',
            'comment_author' => 'Test User',
        ];

        $result = polyglot_redirect_review_submission($commentdata);

        $this->assertSame($this->master_id, $result['comment_post_ID']);
    }

    public function testReviewSubmissionNoopOnMaster(): void
    {
        $commentdata = [
            'comment_post_ID' => $this->master_id,
            'comment_content' => 'Super produit !',
            'comment_author' => 'Test User',
        ];

        $result = polyglot_redirect_review_submission($commentdata);

        $this->assertSame($this->master_id, $result['comment_post_ID']);
    }

    public function testReviewSubmissionIgnoresNonProducts(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $commentdata = [
            'comment_post_ID' => $page_id,
            'comment_content' => 'Nice page!',
            'comment_author' => 'Test User',
        ];

        $result = polyglot_redirect_review_submission($commentdata);

        $this->assertSame($page_id, $result['comment_post_ID']);
    }

    public function testSourceLocaleTaggedOnNewReview(): void
    {
        $_SERVER['HTTP_HOST'] = 'en.test';

        $comment_id = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_content' => 'Great product!',
            'comment_approved' => 1,
        ]);

        polyglot_tag_review_source_locale($comment_id, 1);

        $this->assertSame('en_IE', get_comment_meta($comment_id, '_source_locale', true));
    }

    public function testSourceLocaleNotTaggedOnShadowComment(): void
    {
        $comment_id = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_content' => 'Translated review',
            'comment_approved' => 1,
        ]);
        update_comment_meta($comment_id, '_master_comment_id', 999);

        polyglot_tag_review_source_locale($comment_id, 1);

        $this->assertSame('', get_comment_meta($comment_id, '_source_locale', true));
    }

    public function testCommentsClausesRewritesShadowToMaster(): void
    {
        $clauses = [
            'where' => "comment_post_ID = {$this->shadow_id} AND comment_approved = '1'",
            'join' => '',
            'groupby' => '',
            'orderby' => '',
            'limits' => '',
            'fields' => '',
        ];

        $query = new WP_Comment_Query();
        $query->query_vars['post_id'] = $this->shadow_id;

        $_SERVER['HTTP_HOST'] = 'en.test';
        $result = polyglot_virtual_review_comments_clauses($clauses, $query);

        $this->assertStringContainsString("comment_post_ID = {$this->master_id}", $result['where']);
    }

    public function testCommentsClausesFiltersByLocale(): void
    {
        $clauses = [
            'where' => "comment_post_ID = {$this->master_id} AND comment_approved = '1'",
            'join' => '',
            'groupby' => '',
            'orderby' => '',
            'limits' => '',
            'fields' => '',
        ];

        $query = new WP_Comment_Query();
        $query->query_vars['post_id'] = $this->master_id;

        $_SERVER['HTTP_HOST'] = 'en.test';
        $result = polyglot_virtual_review_comments_clauses($clauses, $query);

        $this->assertStringContainsString('_source_locale', $result['join']);
        $this->assertStringContainsString('_master_comment_id', $result['join']);
        $this->assertStringContainsString('en_IE', $result['where']);
    }

    public function testCommentsClausesNoopOnNonProducts(): void
    {
        $page_id = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $clauses = [
            'where' => "comment_post_ID = {$page_id} AND comment_approved = '1'",
            'join' => '',
            'groupby' => '',
            'orderby' => '',
            'limits' => '',
            'fields' => '',
        ];

        $query = new WP_Comment_Query();
        $query->query_vars['post_id'] = $page_id;

        $result = polyglot_virtual_review_comments_clauses($clauses, $query);

        $this->assertSame($clauses, $result);
    }

    public function testTranslateCommentFindShadow(): void
    {
        $original_comment_id = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_content' => 'Original review',
            'comment_approved' => 1,
        ]);

        $shadow_comment_id = self::factory()->comment->create([
            'comment_post_ID' => $this->master_id,
            'comment_content' => 'Translated review',
            'comment_approved' => 1,
        ]);
        update_comment_meta($shadow_comment_id, '_master_comment_id', $original_comment_id);
        update_comment_meta($shadow_comment_id, '_locale', 'en_IE');

        $cli = new Polyglot_CLI();
        $reflection = new ReflectionMethod($cli, 'find_shadow_comment');
        $reflection->setAccessible(true);

        $found = $reflection->invoke($cli, $original_comment_id, 'en_IE');
        $this->assertEquals($shadow_comment_id, $found);

        $not_found = $reflection->invoke($cli, $original_comment_id, 'es_ES');
        $this->assertNull($not_found);
    }
}

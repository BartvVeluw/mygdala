<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Blog 2.0: a blog post can carry content blocks, through the SAME owner
 * model a product and a portfolio project use (CONTENT-BLOCKS.md, "Blokken op
 * een product of project"; db/migrations/20260930100000).
 *
 *   blog_post_content_pages   post -> its content page, one each way. REAL
 *                             foreign keys on both sides, RESTRICT: a post
 *                             cannot be deleted while it still has a content
 *                             page, nor the page while it is linked. Deleting
 *                             goes through ContentPages::deleteFor() first
 *                             (api/admin/delete-blog-post.php).
 *
 *   blog_posts.content_mode   WHICH body a post shows, explicitly
 *                             (App\Service\Blog\BlogContentMode):
 *                               legacy  its rich-text body per language, as
 *                                       every post did before Blog 2.0
 *                               blocks  its content blocks
 *                             NOT NULL DEFAULT 'legacy', so every existing
 *                             post — on any installation, with any number of
 *                             posts — reads exactly as before, and its body is
 *                             not touched. A new post is created as 'blocks'
 *                             by the CMS. Switching is an editor's deliberate
 *                             act, and never deletes the other half: a
 *                             converted post keeps its body, a post switched
 *                             back keeps its blocks.
 *
 * No row is rewritten. Idempotent; forward-only (db/migrations/CLAUDE.md).
 */
final class GiveBlogPostsContentBlocks extends AbstractMigration
{
    public function up(): void
    {
        $posts = $this->table('blog_posts');
        if (!$posts->hasColumn('content_mode')) {
            $posts->addColumn('content_mode', 'string', [
                'limit' => 10,
                'null' => false,
                'default' => 'legacy',
                'after' => 'og_media_id',
                'comment' => 'legacy = the rich-text body; blocks = the content blocks (App\\Service\\Blog\\BlogContentMode)',
            ])->update();
        }

        if (!$this->hasTable('blog_post_content_pages')) {
            $this->table('blog_post_content_pages', ['id' => false, 'primary_key' => ['blog_post_id']])
                ->addColumn('blog_post_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('page_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['page_id'], ['unique' => true, 'name' => 'uq_blog_post_content_pages_page'])
                ->addForeignKey('blog_post_id', 'blog_posts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_blog_post_content_pages_post'])
                ->addForeignKey('page_id', 'pages', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_blog_post_content_pages_page'])
                ->create();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

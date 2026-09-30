<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Reviews block (`reviews`, App\Service\Blocks\ReviewsBlock): customer
 * reviews, testimonials or experiences an editor types in by hand, shown as a
 * calm quote, a grid of cards, one featured review or a carousel
 * (CONTENT-BLOCKS.md, "Reviews"). Two tables, the shape of every block with a
 * repeater.
 *
 * The tables are called review_blocks and review_block_items, not `reviews`:
 * that name stays free for a later, optional Reviews module that keeps
 * reviews centrally (CONTENT-BLOCKS.md, "Reviews", "Later: een Reviews-module").
 * These rows are the reviews typed into ONE block.
 *
 * review_blocks, one row per instance, what the whole block chooses:
 *
 *   layout            'cards' (the start), 'minimal', 'featured' or 'carousel'
 *                     — App\Service\ReviewsContent::LAYOUTS
 *   featured_item_id  the review "Uitgelicht" shows; NULL or a review that is
 *                     gone means the first one. Deliberately no foreign key:
 *                     the items already cascade from this row, and a key back
 *                     would make the two tables point at each other. The
 *                     read model and the endpoint only ever accept an id of
 *                     this block's own reviews.
 *   header_align      'left' (the start) or 'center' — ::HEADER_ALIGNMENTS
 *   link_type, link_target_id, link_url
 *                     the optional button under the reviews: LinkChoice's
 *                     three values, NULL type = no button
 *   button_style_id   Button Styles 2.0: NULL = the default button, else
 *                     button_styles.id (RESTRICT, like every button slot)
 *
 * review_block_items, the reviews in their order:
 *
 *   media_id          an optional portrait or picture, a Media Library item
 *   image_*           how that picture sits in its round or square frame
 *                     (Responsive Media: App\Service\ReviewsContent::imageSlot(),
 *                     prefix `image_`, no fit and no phone height), with a
 *                     phone picture of its own
 *   rating            1 to 5 stars, NULL for none (the start)
 *   review_date       an optional date, NULL for none
 *   source_url        an optional web address where the review comes from
 *
 * Every word — the block's eyebrow, title, lead and button label, and each
 * review's text, name, description and source label — is in
 * block_translations (ReviewsBlock::translatableFields()), never a column
 * here. There is no alt-text column: the library's alt text is used.
 *
 * ON DELETE RESTRICT on both media columns, like every block since
 * 20260909260000: a picture in use cannot be deleted from the library.
 * App\Service\Media\Usage\ContentBlockMediaUsage is the first line of
 * defence, these constraints the second.
 *
 * New tables with nothing to take over: no backfill, no fresh-install guard,
 * idempotent. Forward-only (db/migrations/CLAUDE.md).
 */
final class CreateReviewBlocks extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('review_blocks')) {
            $this->table('review_blocks', ['id' => true])
                ->addColumn('page_slug', 'string', ['limit' => 100])
                ->addColumn('section_key', 'string', ['limit' => 100])
                ->addColumn('layout', 'string', ['limit' => 10, 'null' => false, 'default' => 'cards', 'comment' => 'App\Service\ReviewsContent::LAYOUTS'])
                ->addColumn('featured_item_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'review_block_items.id shown by "featured"; NULL or gone = the first'])
                ->addColumn('header_align', 'string', ['limit' => 10, 'null' => false, 'default' => 'left', 'comment' => 'App\Service\ReviewsContent::HEADER_ALIGNMENTS'])
                ->addColumn('link_type', 'string', ['limit' => 20, 'null' => true, 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no button'])
                ->addColumn('link_target_id', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('link_url', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('button_style_id', 'integer', ['signed' => false, 'null' => true, 'default' => null, 'comment' => 'NULL = the default button; else button_styles.id'])
                ->addColumn('is_active', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['page_slug', 'section_key'], ['unique' => true])
                ->addIndex(['button_style_id'], ['name' => 'idx_review_blocks_button_style'])
                ->addForeignKey('button_style_id', 'button_styles', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_review_blocks_button_style'])
                ->create();
        }

        if (!$this->hasTable('review_block_items')) {
            $this->table('review_block_items', ['id' => true])
                ->addColumn('review_block_id', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: an optional portrait'])
                ->addColumn('image_focus_x', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 50, 'comment' => 'App\Service\Media\ResponsiveImage: focus point, percent 0-100 (object-position)'])
                ->addColumn('image_focus_y', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 50, 'comment' => 'App\Service\Media\ResponsiveImage: focus point, percent 0-100 (object-position)'])
                ->addColumn('image_mobile_media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own picture; NULL = the desktop picture'])
                ->addColumn('image_mobile_focus_x', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point'])
                ->addColumn('image_mobile_focus_y', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: a phone\'s own focus point, percent; NULL = the desktop point'])
                ->addColumn('image_zoom', 'tinyinteger', ['signed' => false, 'null' => false, 'default' => 100, 'comment' => 'App\Service\Media\ResponsiveImage: zoom, percent 100-200 (100 = cover as it is)'])
                ->addColumn('image_mobile_zoom', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media\ResponsiveImage: the phone point\'s own zoom, percent; NULL = no phone point of its own'])
                ->addColumn('rating', 'tinyinteger', ['signed' => false, 'null' => true, 'comment' => '1-5 stars; NULL = no stars'])
                ->addColumn('review_date', 'date', ['null' => true])
                ->addColumn('source_url', 'string', ['limit' => 500, 'null' => true, 'comment' => 'App\Service\Routing\SafeUrl, http(s) only'])
                ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addIndex(['review_block_id', 'sort_order'])
                ->addForeignKey('review_block_id', 'review_blocks', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
                ->addForeignKey('media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('image_mobile_media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->create();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

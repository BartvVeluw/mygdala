<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Content Blocks Polish 1: display choices for four existing blocks, each a
 * word from a closed list in the block's content class (CONTENT-BLOCKS.md,
 * "Een weergavekeuze is een woord uit een gesloten lijst"), plus a typed
 * button destination for a Tekst met afbeelding item.
 *
 *   rich_text_sections.content_width     'medium' (the narrow reading column
 *                                         every text block has today) or
 *                                         'large' (the site's normal container)
 *                                         — App\Service\RichTextContent::WIDTHS
 *   form_blocks.header_align             'left' (today), 'center', 'right'
 *                                         — App\Service\FormBlockContent::HEADER_ALIGNMENTS
 *   card_carousels.header_align          'left' (today), 'center', 'right'
 *                                         — App\Service\CardCarouselContent::HEADER_ALIGNMENTS
 *   card_carousels.image_height          'small', 'medium' (today), 'large'
 *                                         — App\Service\CardCarouselContent::IMAGE_HEIGHTS
 *   text_image_split_items.button_link_type + button_link_target_id
 *                                         the shape every block button has
 *                                         (App\Service\Routing\LinkChoice): NULL
 *                                         with a button_url is the typed address
 *                                         the item always had, 'url' a typed
 *                                         address, or an item of the website by
 *                                         id ('page', 'blog_post', 'product').
 *
 * ADDITIVE, AND EVERY EXISTING BLOCK RENDERS AS BEFORE: each new column's
 * default is today's presentation, and an item's existing button_url stays
 * where it is. LinkChoice::storedType() reads a row without a type but with an
 * address as an address, so no row is rewritten. No foreign key on the link
 * target, as on the carousel cards and the Tekstblok: it names a row in one of
 * three tables, and a target that is gone renders no button.
 *
 * Schema only, no fresh-install guard, idempotent (db/migrations/CLAUDE.md).
 */
final class GiveSimpleBlocksTheirPolishSettings extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'rich_text_sections' => [
                'content_width' => ['string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'after' => 'text_align', 'comment' => 'App\Service\RichTextContent::WIDTHS']],
            ],
            'form_blocks' => [
                'header_align' => ['string', ['limit' => 10, 'null' => false, 'default' => 'left', 'after' => 'section_key', 'comment' => 'App\Service\FormBlockContent::HEADER_ALIGNMENTS']],
            ],
            'card_carousels' => [
                'header_align' => ['string', ['limit' => 10, 'null' => false, 'default' => 'left', 'after' => 'desktop_layout', 'comment' => 'App\Service\CardCarouselContent::HEADER_ALIGNMENTS']],
                'image_height' => ['string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'after' => 'header_align', 'comment' => 'App\Service\CardCarouselContent::IMAGE_HEIGHTS']],
            ],
            'text_image_split_items' => [
                'button_link_type' => ['string', ['limit' => 20, 'null' => true, 'after' => 'image_focus', 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = the button_url as it was']],
                'button_link_target_id' => ['integer', ['signed' => false, 'null' => true, 'after' => 'button_link_type']],
            ],
        ];

        foreach ($columns as $tableName => $tableColumns) {
            foreach ($tableColumns as $column => [$type, $options]) {
                if (!$this->table($tableName)->hasColumn($column)) {
                    $this->table($tableName)->addColumn($column, $type, $options)->update();
                }
            }
        }
    }

    public function down(): void
    {
        $columns = [
            'text_image_split_items' => ['button_link_target_id', 'button_link_type'],
            'card_carousels' => ['image_height', 'header_align'],
            'form_blocks' => ['header_align'],
            'rich_text_sections' => ['content_width'],
        ];

        foreach ($columns as $tableName => $tableColumns) {
            foreach ($tableColumns as $column) {
                if ($this->table($tableName)->hasColumn($column)) {
                    $this->table($tableName)->removeColumn($column)->update();
                }
            }
        }
    }
}

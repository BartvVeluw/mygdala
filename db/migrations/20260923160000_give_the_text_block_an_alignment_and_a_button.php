<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Two additions to the Tekstblok (`rich_text_sections`, CONTENT-BLOCKS.md):
 *
 *   text_align      'left' (the default, how every text block looks today),
 *                   'center' or 'right' (App\Service\RichTextContent::ALIGNMENTS)
 *   button_link_type + button_link_target_id + button_url
 *                   an optional button under the text, in the shape every
 *                   block button has (App\Service\Routing\LinkChoice): NULL
 *                   is no button, 'url' the typed address, or an item of the
 *                   website by id ('page', 'blog_post', 'product').
 *
 * The button's label is a word and gets no column: it is `button_label` in
 * block_translations (RichTextBlock::translatableFields()).
 *
 * ADDITIVE, AND TODAY'S TEXT BLOCKS ARE UNCHANGED: every existing row gets
 * 'left' and no button. No foreign key on the link target, as on the
 * carousel cards and the Homepage Hero: it names a row in one of three
 * tables, and a target that is gone renders no button.
 *
 * Schema only, no fresh-install guard, idempotent (db/migrations/CLAUDE.md).
 */
final class GiveTheTextBlockAnAlignmentAndAButton extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'text_align' => ['string', ['limit' => 10, 'null' => false, 'default' => 'left', 'after' => 'section_key', 'comment' => 'App\Service\RichTextContent::ALIGNMENTS']],
            'button_link_type' => ['string', ['limit' => 20, 'null' => true, 'after' => 'text_align', 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no button']],
            'button_link_target_id' => ['integer', ['signed' => false, 'null' => true, 'after' => 'button_link_type']],
            'button_url' => ['string', ['limit' => 500, 'null' => true, 'after' => 'button_link_target_id']],
        ];

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('rich_text_sections')->hasColumn($column)) {
                $this->table('rich_text_sections')->addColumn($column, $type, $options)->update();
            }
        }
    }

    public function down(): void
    {
        foreach (['button_url', 'button_link_target_id', 'button_link_type', 'text_align'] as $column) {
            if ($this->table('rich_text_sections')->hasColumn($column)) {
                $this->table('rich_text_sections')->removeColumn($column)->update();
            }
        }
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CTA 2.0: the Oproep met knop (`cta_bands`, CONTENT-BLOCKS.md) gets a layout,
 * an optional background picture and typed button destinations. Every new
 * display column is a word from a closed list in App\Service\CtaBandContent
 * (CONTENT-BLOCKS.md, "Een weergavekeuze is een woord uit een gesloten lijst"):
 *
 *   content_align        'center' (today), 'left', 'right' — CtaBandContent::ALIGNMENTS
 *   lead_width           'narrow' (today's 46ch), 'medium', 'wide', 'full' — ::LEAD_WIDTHS
 *   full_width           0 (today: a card inside the container) or 1 (the
 *                        band's background runs the width of the page, the
 *                        text stays in the container)
 *   background_media_id  a picture from the Media Library behind the text
 *                        (MEDIA.md), decorative; NULL = none (today)
 *   background_focus     which part of that picture stays in view
 *                        (App\Service\Media\ImageFocus), 'center' by default
 *   background_overlay   'none', 'light', 'medium', 'dark' — ::OVERLAYS; only
 *                        drawn over a picture, so no band changes by it today
 *   text_panel           0 (today) or 1: a surface behind the words
 *   text_panel_opacity   'subtle', 'medium', 'strong', 'solid' — ::PANEL_OPACITIES
 *   primary_link_type + primary_link_target_id,
 *   secondary_link_type + secondary_link_target_id
 *                        where each button goes, the shape every block button
 *                        has (App\Service\Routing\LinkChoice): 'url' (the
 *                        address in primary_url / secondary_url, as before) or
 *                        an item of the website by id ('page', 'blog_post',
 *                        'product'); NULL with an empty address is no button.
 *
 * EVERY EXISTING BAND RENDERS AS BEFORE. Each default is today's presentation,
 * and a button with an address is backfilled to 'url', so it keeps going
 * exactly where it went. That alone does not switch a button on: a button
 * still needs its label in the default language (CtaBandContent), which is
 * the rule the second button always had. A stale address without a label
 * therefore stays what it was, no button, and no address is rewritten.
 *
 * ON DELETE RESTRICT on the picture, for the reason 20260909260000 gives: an
 * item that is still used must not be deletable.
 * App\Service\Media\Usage\ContentBlockMediaUsage is the first line of defence,
 * this constraint the second. No foreign key on the link targets: one names a
 * row in one of three tables, and a target that is gone renders no button.
 *
 * Schema before data and no fresh-install guard: a new installation and an
 * upgraded one end on the same table (db/migrations/CLAUDE.md). Idempotent:
 * every column is checked first, and the backfill only fills NULLs.
 */
final class GiveTheCtaBandLayoutBackgroundAndLinkTargets extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'content_align' => ['string', ['limit' => 10, 'null' => false, 'default' => 'center', 'after' => 'secondary_url', 'comment' => 'App\Service\CtaBandContent::ALIGNMENTS']],
            'lead_width' => ['string', ['limit' => 10, 'null' => false, 'default' => 'narrow', 'after' => 'content_align', 'comment' => 'App\Service\CtaBandContent::LEAD_WIDTHS']],
            'full_width' => ['boolean', ['null' => false, 'default' => 0, 'after' => 'lead_width', 'comment' => 'The background runs the width of the page']],
            'background_focus' => ['string', ['limit' => 12, 'null' => false, 'default' => 'center', 'after' => 'full_width', 'comment' => 'App\Service\Media\ImageFocus']],
            'background_overlay' => ['string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'after' => 'background_focus', 'comment' => 'App\Service\CtaBandContent::OVERLAYS']],
            'text_panel' => ['boolean', ['null' => false, 'default' => 0, 'after' => 'background_overlay', 'comment' => 'A surface behind the words']],
            'text_panel_opacity' => ['string', ['limit' => 10, 'null' => false, 'default' => 'strong', 'after' => 'text_panel', 'comment' => 'App\Service\CtaBandContent::PANEL_OPACITIES']],
            'primary_link_type' => ['string', ['limit' => 20, 'null' => true, 'after' => 'primary_url', 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no button']],
            'primary_link_target_id' => ['integer', ['signed' => false, 'null' => true, 'after' => 'primary_link_type']],
            'secondary_link_type' => ['string', ['limit' => 20, 'null' => true, 'after' => 'secondary_url', 'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no button']],
            'secondary_link_target_id' => ['integer', ['signed' => false, 'null' => true, 'after' => 'secondary_link_type']],
        ];

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('cta_bands')->hasColumn($column)) {
                $this->table('cta_bands')->addColumn($column, $type, $options)->update();
            }
        }

        if (!$this->table('cta_bands')->hasColumn('background_media_id')) {
            $this->table('cta_bands')
                ->addColumn('background_media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'full_width',
                    'comment' => 'App\Service\Media reference: the decorative background picture',
                ])
                ->addForeignKey('background_media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
        }

        foreach (['primary', 'secondary'] as $button) {
            $this->execute(
                "UPDATE cta_bands SET {$button}_link_type = 'url'
                 WHERE {$button}_link_type IS NULL AND {$button}_url IS NOT NULL AND TRIM({$button}_url) <> ''"
            );
        }
    }

    public function down(): void
    {
        $table = $this->table('cta_bands');

        if ($table->hasColumn('background_media_id')) {
            if ($table->hasForeignKey('background_media_id')) {
                $table->dropForeignKey('background_media_id')->update();
            }

            $this->table('cta_bands')->removeColumn('background_media_id')->update();
        }

        foreach ([
            'secondary_link_target_id', 'secondary_link_type', 'primary_link_target_id', 'primary_link_type',
            'text_panel_opacity', 'text_panel', 'background_overlay', 'background_focus', 'full_width',
            'lead_width', 'content_align',
        ] as $column) {
            if ($this->table('cta_bands')->hasColumn($column)) {
                $this->table('cta_bands')->removeColumn($column)->update();
            }
        }
    }
}

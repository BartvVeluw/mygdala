<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Contentblock Styling 1.0 ("Extra vormgeving", CONTENT-BLOCKS.md): the look
 * of ONE block instance — its background, the lines above and below it, the
 * room around it and a decorative effect — stored on that instance's own
 * page_sections row, whatever the block type. One place for every block,
 * instead of the same four columns in twenty content tables.
 *
 *   appearance_background   'default', 'page', 'subtle', 'primary',
 *                           'secondary', 'transparent' — BlockAppearance::BACKGROUNDS
 *   appearance_border       'default', 'none', 'top', 'bottom', 'both' — BORDERS
 *   appearance_border_tone  'subtle', 'normal', 'accent' — BORDER_TONES
 *   appearance_spacing      'default', 'compact', 'normal', 'spacious', 'extra' — SPACINGS
 *   appearance_decoration   'none', 'sparks', 'glow', 'pattern' — DECORATIONS
 *
 * EVERY EXISTING BLOCK RENDERS AS BEFORE: every default is "what the block
 * already did", and a block with nothing but defaults prints exactly the
 * markup it printed before this migration.
 *
 * ONE OLD CHOICE MOVES HERE. The gallery (`item_galleries.background`, used by
 * the Portfolio-/collectiegalerij and the Projecten block) was the only block
 * with a background setting of its own. Its 'soft' is the site's `.bg-soft`:
 * the soft wash plus a hairline above and below. That is exactly the shared
 * 'subtle' background with a 'subtle' line on both sides, so a placed gallery
 * on 'soft' gets those three values here and 'default' in its own column. The
 * page looks the same, and there is one background setting instead of two.
 * A gallery still in draft (content_block_drafts, no page_sections row yet)
 * keeps its own value: it has no instance row to carry the new one.
 *
 * Idempotent: every column is checked first, and the gallery step only moves
 * rows that still say 'soft'. Forward-only (db/migrations/CLAUDE.md).
 */
final class GivePageSectionsAnAppearance extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'appearance_background' => ['string', ['limit' => 16, 'null' => false, 'default' => 'default', 'after' => 'is_active', 'comment' => 'App\Service\Blocks\BlockAppearance::BACKGROUNDS']],
            'appearance_border' => ['string', ['limit' => 16, 'null' => false, 'default' => 'default', 'after' => 'appearance_background', 'comment' => 'App\Service\Blocks\BlockAppearance::BORDERS']],
            'appearance_border_tone' => ['string', ['limit' => 16, 'null' => false, 'default' => 'subtle', 'after' => 'appearance_border', 'comment' => 'App\Service\Blocks\BlockAppearance::BORDER_TONES']],
            'appearance_spacing' => ['string', ['limit' => 16, 'null' => false, 'default' => 'default', 'after' => 'appearance_border_tone', 'comment' => 'App\Service\Blocks\BlockAppearance::SPACINGS']],
            'appearance_decoration' => ['string', ['limit' => 16, 'null' => false, 'default' => 'none', 'after' => 'appearance_spacing', 'comment' => 'App\Service\Blocks\BlockAppearance::DECORATIONS']],
        ];

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('page_sections')->hasColumn($column)) {
                $this->table('page_sections')->addColumn($column, $type, $options)->update();
            }
        }

        if ($this->hasTable('item_galleries') && $this->table('item_galleries')->hasColumn('background')) {
            $this->execute(
                "UPDATE page_sections ps
                   JOIN item_galleries ig ON ig.id = ps.section_id
                    SET ps.appearance_background = 'subtle',
                        ps.appearance_border = 'both',
                        ps.appearance_border_tone = 'subtle'
                  WHERE ps.section_type IN ('item_gallery', 'project_cards')
                    AND ig.background = 'soft'
                    AND ps.appearance_background = 'default'
                    AND ps.appearance_border = 'default'"
            );

            $this->execute(
                "UPDATE item_galleries ig
                   JOIN page_sections ps ON ps.section_id = ig.id
                        AND ps.section_type IN ('item_gallery', 'project_cards')
                    SET ig.background = 'default'
                  WHERE ig.background = 'soft'
                    AND ps.appearance_background = 'subtle'"
            );
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

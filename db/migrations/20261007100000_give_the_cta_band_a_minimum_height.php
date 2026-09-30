<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CTA Background Height: the Oproep met knop (`cta_bands`, CONTENT-BLOCKS.md,
 * "De hoogte van het achtergrondvlak") gets a MINIMUM height for the box that
 * carries its background, on a large screen and on a phone. It is never a
 * fixed height: more words make the band taller than the chosen minimum.
 *
 *   min_height            'auto' (today: as tall as the words), 'compact',
 *                         'normal', 'tall', 'custom' — CtaBandContent::HEIGHTS
 *   min_height_px         the pixels of 'custom' (200–1000), else NULL
 *   mobile_min_height     'auto' (follows the large screen, capped for a
 *                         phone), 'text', 'compact', 'normal', 'tall',
 *                         'custom' — CtaBandContent::MOBILE_HEIGHTS
 *   mobile_min_height_px  the pixels of the phone's 'custom' (160–800), else NULL
 *
 * EVERY EXISTING BAND RENDERS AS BEFORE: both defaults are 'auto', and 'auto'
 * on both prints no class and no style at all. No row is rewritten.
 *
 * Idempotent: every column is checked first. Forward-only (db/migrations/CLAUDE.md).
 */
final class GiveTheCtaBandAMinimumHeight extends AbstractMigration
{
    public function up(): void
    {
        $columns = [
            'min_height' => ['string', ['limit' => 10, 'null' => false, 'default' => 'auto', 'after' => 'text_panel_opacity', 'comment' => 'App\Service\CtaBandContent::HEIGHTS']],
            'min_height_px' => ['smallinteger', ['signed' => false, 'null' => true, 'after' => 'min_height', 'comment' => 'Pixels of min_height = custom']],
            'mobile_min_height' => ['string', ['limit' => 10, 'null' => false, 'default' => 'auto', 'after' => 'min_height_px', 'comment' => 'App\Service\CtaBandContent::MOBILE_HEIGHTS']],
            'mobile_min_height_px' => ['smallinteger', ['signed' => false, 'null' => true, 'after' => 'mobile_min_height', 'comment' => 'Pixels of mobile_min_height = custom']],
        ];

        foreach ($columns as $column => [$type, $options]) {
            if (!$this->table('cta_bands')->hasColumn($column)) {
                $this->table('cta_bands')->addColumn($column, $type, $options)->update();
            }
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

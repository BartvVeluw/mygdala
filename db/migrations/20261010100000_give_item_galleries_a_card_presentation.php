<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Card Presentation 2.0: the gallery and the Portfolio's Projecten
 * (`item_galleries`, CONTENT-BLOCKS.md "Kaartweergave") get a choice of how
 * their cards look, for the whole block at once:
 *
 *   card_presentation  'default' (the cards as they are: a 4/5 photo with
 *                      its words on hover), 'compact', 'wide' —
 *                      App\Service\Blocks\CardPresentation::ALL
 *
 * EVERY EXISTING BLOCK RENDERS AS BEFORE: the default is 'default', and
 * 'default' prints no class and no element of its own. No row is rewritten.
 *
 * Idempotent: the column is checked first. Forward-only (db/migrations/CLAUDE.md).
 */
final class GiveItemGalleriesACardPresentation extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('item_galleries')->hasColumn('card_presentation')) {
            $this->table('item_galleries')
                ->addColumn('card_presentation', 'string', [
                    'limit' => 10,
                    'null' => false,
                    'default' => 'default',
                    'after' => 'tight_top',
                    'comment' => 'App\Service\Blocks\CardPresentation::ALL',
                ])
                ->update();
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }
}

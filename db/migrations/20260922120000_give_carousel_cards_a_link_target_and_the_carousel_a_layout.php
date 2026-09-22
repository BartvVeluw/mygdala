<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Two additions to the Kaarten-carrousel (card_carousels / carousel_cards,
 * CONTENT-BLOCKS.md):
 *
 *   carousel_cards.link_type + link_target_id   what a card's button points at:
 *       'url' (the address typed in link_url, as before), or an item of the
 *       website itself: 'page', 'blog_post', 'product'
 *       (App\Service\Routing\LinkTargets). An internal target is stored as
 *       its id, never as an address, so a later slug change or a new language
 *       follows through by itself: the address is resolved per render in the
 *       language being read.
 *   card_carousels.desktop_layout   'orbit' (the rotating carousel every
 *       carousel had) or 'row' (the cards side by side, as on a phone).
 *
 * ADDITIVE, AND TODAY'S CAROUSEL IS THE DEFAULT. `desktop_layout` is NOT NULL
 * DEFAULT 'orbit', which MySQL writes into every existing row as part of ADD
 * COLUMN. A card that has an address in link_url is backfilled to 'url', so
 * its button keeps going exactly where it went; a card without one stays NULL,
 * which reads as "no button". link_url itself is not touched.
 *
 * No foreign key on link_target_id: it names a row in one of three tables,
 * chosen by link_type, and a target that is gone (or whose module is off)
 * simply renders no button, the rule App\Service\LinkResolver follows for a
 * menu item.
 *
 * Idempotent: every step checks first, and the backfill only fills NULLs.
 */
final class GiveCarouselCardsALinkTargetAndTheCarouselALayout extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('carousel_cards')->hasColumn('link_type')) {
            $this->table('carousel_cards')
                ->addColumn('link_type', 'string', [
                    'limit' => 20,
                    'null' => true,
                    'after' => 'link_url',
                    'comment' => 'App\Service\Routing\LinkTargets type: url, page, blog_post, product; NULL = no button',
                ])
                ->update();
        }

        if (!$this->table('carousel_cards')->hasColumn('link_target_id')) {
            $this->table('carousel_cards')
                ->addColumn('link_target_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'after' => 'link_type',
                ])
                ->update();
        }

        $this->execute(
            "UPDATE carousel_cards SET link_type = 'url'
             WHERE link_type IS NULL AND link_url IS NOT NULL AND TRIM(link_url) <> ''"
        );

        if (!$this->table('card_carousels')->hasColumn('desktop_layout')) {
            $this->table('card_carousels')
                ->addColumn('desktop_layout', 'string', [
                    'limit' => 20,
                    'null' => false,
                    'default' => 'orbit',
                    'after' => 'is_active',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (['carousel_cards' => ['link_target_id', 'link_type'], 'card_carousels' => ['desktop_layout']] as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->table($table)->hasColumn($column)) {
                    $this->table($table)->removeColumn($column)->update();
                }
            }
        }
    }
}

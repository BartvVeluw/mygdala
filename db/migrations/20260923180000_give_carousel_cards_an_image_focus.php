<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The picture of a Kaarten-carrousel card is cropped to fill its frame
 * (object-fit: cover). Which part stays in view is now the card's own choice:
 *
 *   image_focus   one of nine points, 'top-left' ... 'center' ... 'bottom-right'
 *                 (App\Service\Media\ImageFocus), turned into object-position
 *                 on the website and in the editor's preview alike.
 *
 * Every existing card gets 'center', which is what the browser used until
 * now: nothing moves.
 *
 * Schema only, no fresh-install guard, idempotent (db/migrations/CLAUDE.md).
 */
final class GiveCarouselCardsAnImageFocus extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('carousel_cards')->hasColumn('image_focus')) {
            return;
        }

        $this->table('carousel_cards')
            ->addColumn('image_focus', 'string', [
                'limit' => 20,
                'null' => false,
                'default' => 'center',
                'after' => 'image_path',
                'comment' => 'App\Service\Media\ImageFocus: which part of a cropped picture stays in view',
            ])
            ->update();
    }

    public function down(): void
    {
        if ($this->table('carousel_cards')->hasColumn('image_focus')) {
            $this->table('carousel_cards')->removeColumn('image_focus')->update();
        }
    }
}

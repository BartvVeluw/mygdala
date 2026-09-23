<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A card of "Kenmerken in kaartjes" (`feature_grid_items`, CONTENT-BLOCKS.md)
 * can show its own icon: an SVG from the Iconen section of the Media Library
 * (App\Service\Media\MediaType::ICON), chosen with the shared picker.
 *
 *   icon_media_id   the media item, when icon_key is 'custom'
 *
 * icon_key itself needs no change: it is a string, and gains two values next
 * to the four standard icons (App\Service\FeatureGridContent): 'none' (no
 * icon at all) and 'custom' (the item in icon_media_id). Every existing card
 * keeps its key and so looks exactly as it did.
 *
 * ON DELETE RESTRICT, for the reason 20260909260000 gives: an item that is
 * still used must not be deletable. App\Service\Media\Usage\ContentBlockMediaUsage
 * is the first line of defence, this constraint the second.
 *
 * Schema only, no fresh-install guard, idempotent (db/migrations/CLAUDE.md).
 */
final class GiveFeatureCardsACustomIcon extends AbstractMigration
{
    public function up(): void
    {
        if ($this->table('feature_grid_items')->hasColumn('icon_media_id')) {
            return;
        }

        $this->table('feature_grid_items')
            ->addColumn('icon_media_id', 'integer', [
                'signed' => false,
                'null' => true,
                'after' => 'icon_key',
                'comment' => 'App\Service\Media reference: the SVG of a custom icon (icon_key = custom)',
            ])
            ->addForeignKey('icon_media_id', 'media', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
            ])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('feature_grid_items');

        if (!$table->hasColumn('icon_media_id')) {
            return;
        }

        if ($table->hasForeignKey('icon_media_id')) {
            $table->dropForeignKey('icon_media_id')->update();
        }

        $this->table('feature_grid_items')->removeColumn('icon_media_id')->update();
    }
}

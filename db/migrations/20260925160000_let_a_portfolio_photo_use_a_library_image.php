<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Portfolio 2.0 (MODULES.md, "Portfolio"): the extra photos of a project page
 * come from the Media Library, like the item's own picture since
 * 20260925130000.
 *
 *   portfolio_item_images.media_id   the library item; NULL for a photo that
 *                                    was uploaded before this step and still
 *                                    lives on Portfolio's own path.
 *
 * THE SAME TABLE, NOT A NEW ONE. portfolio_item_images already is "the extra
 * photos of one item, in their own order" (20260906060000), and its alt texts
 * already live per website language in portfolio_item_image_translations. A
 * second relation table would leave two lists of photos for one item.
 *
 * image_path and thumbnail_path STAY and are written along with the chosen
 * library item, the pattern every integrated table follows (MEDIA.md, "Hoe een
 * feature naar media verwijst"): the project page keeps reading the same two
 * columns, and a photo from before this step renders exactly as it did.
 *
 * NOTHING IS MIGRATED. An existing photo keeps its own file and media_id NULL.
 *
 * ON DELETE RESTRICT, like every other key onto media: a library item that a
 * project page shows cannot disappear from under it
 * (App\Service\PortfolioMediaUsage reports the use, and the library refuses
 * the delete before the key has to). Deleting the portfolio item still takes
 * its photo rows along (the existing CASCADE on portfolio_item_id), never the
 * library item.
 *
 * Schema only, so no fresh-install guard (db/migrations/CLAUDE.md), and each
 * step checks before it acts.
 */
final class LetAPortfolioPhotoUseALibraryImage extends AbstractMigration
{
    private const TABLE = 'portfolio_item_images';
    private const FOREIGN_KEY = 'fk_portfolio_item_images_media';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE) || !$this->hasTable('media')) {
            return;
        }

        if (!$this->table(self::TABLE)->hasColumn('media_id')) {
            $this->table(self::TABLE)
                ->addColumn('media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'portfolio_item_id',
                    'comment' => 'The Media Library item; NULL = a photo on Portfolio\'s own path from before the library',
                ])
                ->update();
        }

        if (!$this->table(self::TABLE)->hasIndex(['media_id'])) {
            $this->table(self::TABLE)->addIndex(['media_id'], ['name' => 'idx_portfolio_item_images_media'])->update();
        }

        if (!$this->table(self::TABLE)->hasForeignKey('media_id')) {
            $this->table(self::TABLE)
                ->addForeignKey('media_id', 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => self::FOREIGN_KEY,
                ])
                ->update();
        }
    }

    public function down(): void
    {
        // Forward-only (db/migrations/CLAUDE.md): nothing to undo on purpose.
    }
}

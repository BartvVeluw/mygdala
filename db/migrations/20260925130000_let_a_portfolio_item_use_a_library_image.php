<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Media Library 2.0 (MEDIA.md, "Wat er is aangesloten"): a Portfolio item
 * chooses its picture from the Media Library, like a block, a product and a
 * blog post already do.
 *
 *   portfolio_gallery_items.media_id   the library item; NULL for an item
 *                                      whose picture was uploaded before this
 *                                      step and still lives on Portfolio's
 *                                      own path.
 *
 * image_path and thumbnail_path STAY, and are written along with the chosen
 * item (its path and its thumbnail), the pattern every integrated table
 * follows ("Hoe een feature naar media verwijst"): every public reader keeps
 * reading the same two columns, so nothing on the site has to change for an
 * item to switch to the library, and an item from before this step renders
 * exactly as it did.
 *
 * NOTHING IS MIGRATED. An existing item keeps its own file and media_id NULL;
 * it moves to the library only when an editor chooses another picture for
 * it. Copying legacy files into the library behind an editor's back would
 * duplicate every picture on disk and give items names nobody chose.
 *
 * ON DELETE RESTRICT, like every other key onto media: a library item that a
 * Portfolio item shows cannot disappear from under it
 * (App\Service\PortfolioMediaUsage reports the use, and the library refuses
 * the delete before the key has to).
 *
 * Schema only, so no fresh-install guard (db/migrations/CLAUDE.md), and each
 * step checks before it acts.
 */
final class LetAPortfolioItemUseALibraryImage extends AbstractMigration
{
    private const FOREIGN_KEY = 'fk_portfolio_gallery_items_media';

    public function up(): void
    {
        if (!$this->hasTable('portfolio_gallery_items') || !$this->hasTable('media')) {
            return;
        }

        if (!$this->table('portfolio_gallery_items')->hasColumn('media_id')) {
            $this->table('portfolio_gallery_items')
                ->addColumn('media_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'page_id',
                    'comment' => 'The Media Library item; NULL = a picture on Portfolio\'s own path from before the library',
                ])
                ->update();
        }

        if (!$this->table('portfolio_gallery_items')->hasIndex(['media_id'])) {
            $this->table('portfolio_gallery_items')->addIndex(['media_id'], ['name' => 'idx_portfolio_gallery_items_media'])->update();
        }

        if (!$this->table('portfolio_gallery_items')->hasForeignKey('media_id')) {
            $this->table('portfolio_gallery_items')
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

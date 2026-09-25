<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Media Library 2.0 (MEDIA.md, "Mappen"): virtual folders.
 *
 *   media_folders      id, name, created_at, updated_at. One level: a folder
 *                      holds media, never another folder.
 *   media.folder_id    NULL = "Geen map"; otherwise the folder the item is
 *                      filed under. One folder per item.
 *
 * A FOLDER IS A LABEL IN THE DATABASE, NEVER A DIRECTORY. Nothing on disk
 * moves when an item changes folder: media.path, the thumbnail, every
 * media_id reference and every public URL stay exactly what they were. The
 * name is never part of a path.
 *
 * ON DELETE SET NULL, unlike every other key onto media (those RESTRICT, so
 * an item in use cannot disappear): deleting a folder must never take an
 * item with it. App\Service\Media\MediaFolderService moves the items to "Geen
 * map" itself first; the key is the guarantee behind it, for a script or a
 * forged request.
 *
 * The name is unique in the column's own collation (utf8mb4_unicode_ci), so
 * "Kerst" and "kerst" are one folder, the same rule the library already
 * applies to media names. The service says so with a message before the
 * index refuses it.
 *
 * EVERY EXISTING ITEM STAYS IN "GEEN MAP": folder_id is added as NULL and
 * nothing is classified from a name or a path. Schema only, so no
 * fresh-install guard (db/migrations/CLAUDE.md); each step checks before it
 * acts, so a second run, or one that stopped halfway, ends with one table,
 * one column, one index and one key.
 */
final class GiveTheMediaLibraryFolders extends AbstractMigration
{
    private const FOREIGN_KEY = 'fk_media_folder';

    public function up(): void
    {
        if (!$this->hasTable('media_folders')) {
            $this->table('media_folders', ['signed' => false, 'collation' => 'utf8mb4_unicode_ci', 'encoding' => 'utf8mb4'])
                ->addColumn('name', 'string', [
                    'limit' => 100,
                    'null' => false,
                    'comment' => 'What the library shows; a label, never a directory',
                ])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uniq_media_folders_name'])
                ->create();
        }

        if (!$this->hasTable('media')) {
            return;
        }

        if (!$this->table('media')->hasColumn('folder_id')) {
            $this->table('media')
                ->addColumn('folder_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'display_name',
                    'comment' => 'NULL = Geen map; a virtual folder, nothing on disk follows it',
                ])
                ->update();
        }

        if (!$this->table('media')->hasIndex(['folder_id'])) {
            $this->table('media')->addIndex(['folder_id'], ['name' => 'idx_media_folder'])->update();
        }

        if (!$this->table('media')->hasForeignKey('folder_id')) {
            $this->table('media')
                ->addForeignKey('folder_id', 'media_folders', 'id', [
                    'delete' => 'SET_NULL',
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

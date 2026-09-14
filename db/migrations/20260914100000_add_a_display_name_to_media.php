<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * A name for every media item that an editor may change, next to the name
 * the file had when it was uploaded. MEDIA.md, "Bestandsnaam".
 *
 * WHY A SECOND COLUMN, rather than rewriting `original_filename`. That column
 * promises what the file was called on the editor's own computer
 * (20260909250000_create_media_table), and search leans on it: somebody who
 * uploaded IMG_2231.jpg looks for IMG_2231 again. Overwriting it on a rename
 * would make the row lie about where the file came from, which is exactly
 * what the columns of this table are written never to do. The label an
 * editor owns gets its own column; the provenance stays true.
 *
 * NOTHING POINTS AT A NAME. Every feature references media by id, and the file
 * on disk keeps its random name, so a new name cannot break a page. That is
 * why this is a column and not a file rename.
 *
 * BACKFILL. Every existing row gets the name the library already showed for
 * it (App\Service\Media\MediaItem::displayName() before this migration): the
 * original filename, or the stored file's own name when there was none. An
 * editor sees no difference the day this runs. The UPDATE only touches rows
 * that still have no name, so running it again changes nothing, and a fresh
 * install — which has no media yet — ends on the same schema with nothing to
 * fill.
 *
 * NOT UNIQUE in the schema. Adopted legacy images can share a name, and a
 * migration must never fail over existing data. The library keeps NEW names
 * distinct itself (App\Service\Media\MediaService); the index is what makes
 * that lookup cheap.
 */
final class AddADisplayNameToMedia extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        $table = $this->table('media');

        if (!$table->hasColumn('display_name')) {
            $table
                ->addColumn('display_name', 'string', [
                    'limit' => 255,
                    'default' => '',
                    'comment' => 'The name an editor sees and may change; a label, never a path',
                    'after' => 'original_filename',
                ])
                ->update();
        }

        // Checked on its own: a run that stopped between the column and the
        // index must still end with both.
        if (!$this->table('media')->hasIndex(['display_name'])) {
            $this->table('media')->addIndex(['display_name'])->update();
        }

        $this->execute(
            "UPDATE media
                SET display_name = CASE
                        WHEN original_filename <> '' THEN original_filename
                        ELSE SUBSTRING_INDEX(path, '/', -1)
                    END
              WHERE display_name = ''"
        );
    }

    public function down(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        $table = $this->table('media');

        if ($table->hasIndex(['display_name'])) {
            $table->removeIndex(['display_name'])->update();
        }

        if ($this->table('media')->hasColumn('display_name')) {
            $this->table('media')->removeColumn('display_name')->update();
        }
    }
}

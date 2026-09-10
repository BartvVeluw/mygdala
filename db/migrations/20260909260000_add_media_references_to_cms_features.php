<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Gives the CMS features that join the Media Library in V1 a way to point at
 * a `media` row instead of owning a path string of their own.
 *
 * ADDITIVE ON PURPOSE. Every existing `*_image_path` column stays exactly
 * where it is and keeps its value. A feature reads the media item when
 * `media_id` is set and falls back to its stored path when it is not — the
 * precedence written down in MEDIA.md. That is what lets the library arrive
 * without a single breaking change, and what lets the legacy columns be
 * retired later as a deliberate step rather than as a side effect of this
 * one.
 *
 * ON DELETE RESTRICT, not SET NULL. A media item that is still used must not
 * be deletable, and if it somehow reaches the database anyway the right
 * outcome is a refused write, not a page that silently loses its image.
 * App\Service\Media\MediaService checks usage before it ever gets here; this
 * constraint is the second line of defence, not the first.
 *
 * The site's branding assets (logo, alternate logo, favicon, default social
 * image) are NOT here: they live in the key/value `site_settings` table and
 * get their media ids as ordinary settings rows in the migration that
 * follows. Which media item is the site's logo stays a Site Setting; the
 * Media Library only owns the file's identity.
 */
final class AddMediaReferencesToCmsFeatures extends AbstractMigration
{
    /**
     * table => [column, the existing path column it may override]. The
     * second value is documentation rather than schema: it records, in the
     * migration itself, which legacy column each new reference is allowed to
     * take precedence over.
     */
    private const REFERENCES = [
        'pages' => ['og_media_id', 'og_image_path'],
        'text_image_split_images' => ['media_id', 'image_path'],
        'detail_sections' => ['main_media_id', 'main_image_path'],
        'detail_section_images' => ['media_id', 'image_path'],
        'carousel_cards' => ['media_id', 'image_path'],
    ];

    public function up(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        foreach (self::REFERENCES as $tableName => [$column, $legacyColumn]) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

            if ($table->hasColumn($column)) {
                continue;
            }

            $table
                ->addColumn($column, 'integer', [
                    'signed' => false,
                    'null' => true,
                    'comment' => 'App\Service\Media reference; wins over ' . $legacyColumn . ' when set',
                ])
                ->addForeignKey($column, 'media', 'id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (self::REFERENCES as $tableName => [$column, $legacyColumn]) {
            if (!$this->hasTable($tableName)) {
                continue;
            }

            $table = $this->table($tableName);

            if (!$table->hasColumn($column)) {
                continue;
            }

            if ($table->hasForeignKey($column)) {
                $table->dropForeignKey($column)->update();
            }

            $table->removeColumn($column)->update();
        }
    }
}

<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Two additions to the Homepage Hero (`homepage_hero`, CONTENT-BLOCKS.md):
 *
 *   media_id, video_media_id   its image and its video from the Media Library
 *       (MEDIA.md), chosen with the shared picker instead of an upload field
 *       of its own. The Hero's video field is the library's first video
 *       field.
 *   primary_link_type + primary_link_target_id,
 *   secondary_link_type + secondary_link_target_id   where each button goes:
 *       'url' (the address typed in primary_url / secondary_url, as before),
 *       or an item of the website itself — 'page', 'blog_post', 'product'
 *       (App\Service\Routing\LinkTargets, App\Service\Routing\LinkChoice) —
 *       stored as its id, so a later slug change or a new language follows by
 *       itself. The same shape carousel_cards got in 20260922120000.
 *
 * ADDITIVE, AND TODAY'S HERO IS UNCHANGED. Nothing is converted or copied:
 *
 *   - image_path and video_path stay exactly as they are and stay the
 *     fallback, the rule every block that joined the library follows
 *     (App\Service\Media\BlockImage): a media id wins when it is set and
 *     names an item, the stored path otherwise. No media row is created for
 *     the existing files; the Hero keeps showing them until an editor picks
 *     another one.
 *   - A button with an address is backfilled to 'url', so it keeps going
 *     exactly where it went; one without stays NULL. The URL columns are
 *     not touched.
 *
 * ON DELETE RESTRICT on both media columns, for the reason 20260909260000
 * gives: an item that is still used must not be deletable.
 * App\Service\Media\Usage\ContentBlockMediaUsage is the first line of defence,
 * this constraint the second. No foreign key on the link targets: one names a
 * row in one of three tables, chosen by its type, and a target that is gone
 * (or whose module is off) simply renders no button.
 *
 * Schema before data and no fresh-install guard: a new installation and an
 * upgraded one end on the same table (db/migrations/CLAUDE.md). Idempotent:
 * every step checks first, and the backfill only fills NULLs.
 */
final class GiveTheHomepageHeroLibraryMediaAndLinkTargets extends AbstractMigration
{
    public function up(): void
    {
        foreach (['media_id' => 'image_path', 'video_media_id' => 'video_path'] as $column => $after) {
            if (!$this->table('homepage_hero')->hasColumn($column)) {
                $this->table('homepage_hero')
                    ->addColumn($column, 'integer', [
                        'signed' => false,
                        'null' => true,
                        'after' => $after,
                        'comment' => 'App\Service\Media reference; the path column beside it is the fallback',
                    ])
                    ->addForeignKey($column, 'media', 'id', [
                        'delete' => 'RESTRICT',
                        'update' => 'CASCADE',
                    ])
                    ->update();
            }
        }

        foreach (['primary', 'secondary'] as $button) {
            if (!$this->table('homepage_hero')->hasColumn($button . '_link_type')) {
                $this->table('homepage_hero')
                    ->addColumn($button . '_link_type', 'string', [
                        'limit' => 20,
                        'null' => true,
                        'after' => $button . '_url',
                        'comment' => 'App\Service\Routing\LinkChoice: url, page, blog_post, product; NULL = no button',
                    ])
                    ->update();
            }

            if (!$this->table('homepage_hero')->hasColumn($button . '_link_target_id')) {
                $this->table('homepage_hero')
                    ->addColumn($button . '_link_target_id', 'integer', [
                        'signed' => false,
                        'null' => true,
                        'after' => $button . '_link_type',
                    ])
                    ->update();
            }

            $this->execute(
                "UPDATE homepage_hero SET {$button}_link_type = 'url'
                 WHERE {$button}_link_type IS NULL AND {$button}_url IS NOT NULL AND TRIM({$button}_url) <> ''"
            );
        }
    }

    public function down(): void
    {
        $table = $this->table('homepage_hero');

        foreach (['secondary_link_target_id', 'secondary_link_type', 'primary_link_target_id', 'primary_link_type'] as $column) {
            if ($table->hasColumn($column)) {
                $this->table('homepage_hero')->removeColumn($column)->update();
            }
        }

        foreach (['video_media_id', 'media_id'] as $column) {
            $table = $this->table('homepage_hero');

            if (!$table->hasColumn($column)) {
                continue;
            }

            if ($table->hasForeignKey($column)) {
                $table->dropForeignKey($column)->update();
            }

            $this->table('homepage_hero')->removeColumn($column)->update();
        }
    }
}

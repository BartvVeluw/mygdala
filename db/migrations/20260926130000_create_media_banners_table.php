<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The Mediabanner block (`media_banner`, App\Service\Blocks\MediaBannerBlock):
 * one picture or one video from the Media Library as a section of its own,
 * without words (CONTENT-BLOCKS.md, "Mediabanner"). One row per instance.
 *
 *   media_id         the picture or video (MEDIA.md); NULL while none is
 *                    chosen yet, which renders nothing. Whether it is a
 *                    picture or a video is the library item's own MIME type,
 *                    never a column here
 *   width            'content' (inside the container, a new banner's start)
 *                    or 'full' (the width of the page) —
 *                    App\Service\MediaBannerContent::WIDTHS
 *   height           'small', 'medium' (the start), 'large', 'xlarge' —
 *                    ::HEIGHTS; the heights themselves are in
 *                    assets/css/blocks/media-banner.css, never a number here
 *   image_focus      which part of a picture stays in view
 *                    (App\Service\Media\ImageFocus), 'center' by default;
 *                    a video is always centred
 *   video_autoplay   0/1; a video that plays by itself is always muted, so
 *                    there is no separate "muted" column
 *   video_loop       0/1
 *   video_controls   0/1, 1 by default; a video that does not play by itself
 *                    always has them (MediaBannerContent)
 *   poster_media_id  an optional picture shown before a video plays; NULL for
 *                    a picture banner
 *
 * The video columns are prefixed because LOOP is a reserved word in MySQL.
 *
 * ON DELETE RESTRICT on both media columns, for the reason 20260909260000
 * gives: an item that is still used must not be deletable.
 * App\Service\Media\Usage\ContentBlockMediaUsage is the first line of
 * defence, these constraints the second.
 *
 * A banner has no words, so nothing of it goes into block_translations. A new
 * table with nothing to take over: no backfill, no fresh-install guard,
 * idempotent (db/migrations/CLAUDE.md).
 */
final class CreateMediaBannersTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('media_banners')) {
            return;
        }

        $this->table('media_banners', ['id' => true])
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: the picture or video'])
            ->addColumn('width', 'string', ['limit' => 10, 'null' => false, 'default' => 'content', 'comment' => 'App\Service\MediaBannerContent::WIDTHS'])
            ->addColumn('height', 'string', ['limit' => 10, 'null' => false, 'default' => 'medium', 'comment' => 'App\Service\MediaBannerContent::HEIGHTS'])
            ->addColumn('image_focus', 'string', ['limit' => 12, 'null' => false, 'default' => 'center', 'comment' => 'App\Service\Media\ImageFocus'])
            ->addColumn('video_autoplay', 'boolean', ['null' => false, 'default' => 0, 'comment' => 'Plays by itself, always muted'])
            ->addColumn('video_loop', 'boolean', ['null' => false, 'default' => 0])
            ->addColumn('video_controls', 'boolean', ['null' => false, 'default' => 1])
            ->addColumn('poster_media_id', 'integer', ['signed' => false, 'null' => true, 'comment' => 'App\Service\Media reference: the picture before a video plays'])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->addForeignKey('media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('poster_media_id', 'media', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('media_banners')) {
            $this->table('media_banners')->drop()->save();
        }
    }
}

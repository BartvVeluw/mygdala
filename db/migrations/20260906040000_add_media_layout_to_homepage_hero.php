<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Extends `homepage_hero` (see 20260905120000_create_homepage_hero_table.php)
 * with image/video media type support and a choice of three hero layouts.
 * Purely additive, backwards-compatible columns — no existing column is
 * touched, and every new column has a default that reproduces exactly
 * today's rendering (an image, laid out media-right), so the single existing
 * row keeps rendering identically the moment this migration runs. MySQL
 * fills the new columns on the existing row with these defaults as part of
 * the ADD COLUMN itself — no separate UPDATE needed.
 *
 * - `media_type` ('image'|'video'): which of image_path/video_path the Hero
 *   renders. Deliberately reuses the EXISTING image_path/image_alt_nl/
 *   image_alt_en columns for the image case, and — when media_type is
 *   'video' — as the <video>'s poster/fallback image, rather than adding a
 *   second, duplicate image upload just for a poster. See
 *   App\Service\HomepageHeroContent for the validated string constants and
 *   the defensive fallback to 'image' when video_path is empty.
 * - `video_path`: nullable, mirrors image_path's shape but for an uploaded
 *   MP4/WEBM file (see App\Service\SectionVideoUploader). NULL/empty is
 *   valid — a Hero only ever has a video once one is actually uploaded.
 * - `layout` ('media_right'|'media_left'|'background'): purely a CSS
 *   presentation choice on the frontend (index.php), no other content
 *   depends on it. 'media_right' is both the default and the exact layout
 *   the Hero already had before this migration.
 */
final class AddMediaLayoutToHomepageHero extends AbstractMigration
{
    public function up(): void
    {
        $this->table('homepage_hero')
            ->addColumn('media_type', 'string', ['limit' => 20, 'default' => 'image'])
            ->addColumn('video_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('layout', 'string', ['limit' => 20, 'default' => 'media_right'])
            ->update();
    }

    public function down(): void
    {
        $this->table('homepage_hero')
            ->removeColumn('media_type')
            ->removeColumn('video_path')
            ->removeColumn('layout')
            ->update();
    }
}

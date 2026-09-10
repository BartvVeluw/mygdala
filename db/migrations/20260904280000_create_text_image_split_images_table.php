<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Images for the text_image_splits repeater (see
 * 20260904260000_create_text_image_splits_table.php's docblock). Modeled as
 * a small ordered child table (image_path, alt_nl, alt_en, sort_order)
 * rather than a fixed image_path/image_path_2 pair, because the current
 * site genuinely has both a 1-image instance ("intro") and a 2-image
 * mini-gallery instance ("idee-naar-product") — and this keeps a future
 * 3rd image possible without another schema change.
 *
 * No markup/layout is stored here — App\Service\TextImageSplitContent /
 * the over-mij.php template decide, purely from `count($images)`, which
 * fixed markup branch to render: exactly 1 image -> `.hero__media-frame`
 * (single framed photo), exactly 2 images -> `.service-detail__gallery`
 * with its `grid-template-columns:1fr 1fr` override, 3+ -> the gallery's
 * own default 3-col grid (not currently used, but keeps the type reusable).
 *
 * image_path values are seeded with the site's EXISTING shared image
 * paths (assets/images/hero-collage-*.webp) — these are not admin-uploaded
 * files, so App\Service\SectionImageUploader::delete() (which only ever
 * touches files under assets/images/sections/, same convention as
 * ProductImageUploader) will never remove them, even if an admin later
 * replaces one of these images with an upload.
 *
 * No is_active column, same reasoning as text_image_split_paragraphs:
 * deleting an image row is enough, there is no meaningful "hidden but
 * kept" state for a single image.
 */
final class CreateTextImageSplitImagesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('text_image_split_images', ['id' => true]);
        $table
            ->addColumn('text_image_split_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('alt_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('alt_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('text_image_split_id', 'text_image_splits', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['text_image_split_id'])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        $now = date('Y-m-d H:i:s');

        $introId = (int) $this->fetchRow(
            "SELECT id FROM text_image_splits WHERE page_slug = 'over-mij' AND section_key = 'intro'"
        )['id'];
        $ideeId = (int) $this->fetchRow(
            "SELECT id FROM text_image_splits WHERE page_slug = 'over-mij' AND section_key = 'idee-naar-product'"
        )['id'];

        $rows = [
            [
                'text_image_split_id' => $introId,
                'image_path' => 'assets/images/hero-collage-a.webp',
                'alt_nl' => 'MOPA-laser graveert een naam in een stalen hamer, met vonken',
                'alt_en' => 'MOPA laser engraving a name into a steel hammer, sparks flying',
                'sort_order' => 0,
            ],
            [
                'text_image_split_id' => $ideeId,
                'image_path' => 'assets/images/hero-collage-b.webp',
                'alt_nl' => 'Gegraveerde hamer met tekst Van Leo Afblijven',
                'alt_en' => 'Engraved hammer reading Van Leo Hands Off',
                'sort_order' => 0,
            ],
            [
                'text_image_split_id' => $ideeId,
                'image_path' => 'assets/images/hero-collage-c.webp',
                'alt_nl' => 'Set gereedschap gegraveerd met naam en logo',
                'alt_en' => 'Set of tools engraved with a name and logo',
                'sort_order' => 1,
            ],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }
        unset($row);

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('text_image_split_images')->drop()->save();
    }
}

<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Fifth CMS-editable page-section type: "Text + image split"
 * (`.service-detail__head`, text column + image(s) column) — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #7. Same parent/child repeater
 * shape and conventions as faq_sections/faq_items (see those migrations):
 * rows are keyed by (page_slug, section_key) so a page can have more than
 * one Text + image split section without a schema rewrite.
 *
 * Audit (over-mij.php, the only page currently using this pattern; every
 * other page's `.service-detail__head` usage is the bigger, unrelated
 * "Material/service detail" type on diensten.php — points-lists, not plain
 * text+image, out of scope here) found two structurally different
 * instances, both preserved by this schema:
 *
 *   - "intro" section (first `.service-detail__head`): no eyebrow, no
 *     title, 3 body paragraphs, 1 image, image on the right, no button.
 *   - "idee-naar-product" section (second `.service-detail__head`, mirrored
 *     column order): eyebrow + title, 1 body paragraph, 2 images (a mini
 *     gallery), image side on the LEFT, and a button.
 *
 * `layout` ('image_left' | 'image_right') is the only theme-controlled
 * "variant" field this type gets — it picks between two fixed markup
 * branches the template already has, it never becomes free CSS/layout
 * configuration. eyebrow_nl/en, title_nl/en and the button fields are all
 * nullable because the "intro" section genuinely has none of them.
 * button_label_nl/en/button_url are all-or-nothing: a half-filled button
 * (label without URL or vice versa) is treated as "no button" by
 * App\Service\TextImageSplitContent, same convention as
 * CtaBandContent's secondary button.
 *
 * Paragraphs and images are normalized child tables (next two migrations)
 * rather than paragraph_1/paragraph_2 or image_path/image_path_2 columns —
 * the two seeded instances genuinely need a different paragraph count (3
 * vs. 1) and image count (1 vs. 2), and the type must stay reusable for a
 * future 3rd image without another schema change.
 */
final class CreateTextImageSplitsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('text_image_splits', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('section_key', 'string', ['limit' => 100])
            ->addColumn('layout', 'string', ['limit' => 20, 'default' => 'image_right'])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('button_label_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('button_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('button_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug', 'section_key'], ['unique' => true])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        // Seed the two currently-existing sections (both on over-mij.php) so
        // the public site keeps rendering identical output the moment this
        // migration runs. Paragraphs/images are seeded by the next two
        // migrations, once this table's ids exist.
        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'over-mij',
                'section_key' => 'intro',
                'layout' => 'image_right',
                'eyebrow_nl' => null,
                'eyebrow_en' => null,
                'title_nl' => null,
                'title_en' => null,
                'button_label_nl' => null,
                'button_label_en' => null,
                'button_url' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'page_slug' => 'over-mij',
                'section_key' => 'idee-naar-product',
                'layout' => 'image_left',
                'eyebrow_nl' => "Waar het om draait",
                'eyebrow_en' => "What it's about",
                'title_nl' => 'Van idee naar zorgvuldig gemaakt product',
                'title_en' => 'From idea to carefully made product',
                'button_label_nl' => 'Vertel me over jouw idee',
                'button_label_en' => 'Tell me about your idea',
                'button_url' => 'contact.php',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('text_image_splits')->drop()->save();
    }
}

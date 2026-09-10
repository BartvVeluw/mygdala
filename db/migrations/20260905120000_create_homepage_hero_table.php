<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Dedicated "Homepage Hero" section (`.hero` on index.php) — deliberately NOT
 * the generic "Page hero" type (`page_heroes` / App\Service\PageHeroContent)
 * used on diensten/portfolio/over-mij/contact/shop. The Homepage Hero has a
 * fundamentally different, richer shape (image, badge, two CTAs, an inline
 * title highlight, a stats repeater) that the generic Page hero's
 * eyebrow/H1/lead/breadcrumb shape does not cover, so this is its own table
 * and its own Content/Repository pair — see App\Service\HomepageHeroContent.
 * The generic Page hero system (table, repository, service, admin editor)
 * is untouched by this migration.
 *
 * A page-bound singleton, exactly like page_heroes: one row per page_slug,
 * but in practice only ever 'index' (the homepage) — the unique index on
 * page_slug keeps the same shape available to a future second page without
 * a schema change, without this being a generic page-builder table.
 *
 * `title_highlight_nl`/`title_highlight_en` hold the exact plain-text
 * substring of `title_nl`/`title_en` that should be wrapped in `<em>` for the
 * existing Hero heading emphasis style — never stored HTML. An empty
 * highlight is valid (no emphasis); a non-empty highlight must occur
 * verbatim in its title — enforced by App\Service\HomepageHeroContent at
 * save time, not by a DB constraint.
 *
 * `image_path`/`image_alt_nl` are NOT NULL: the Hero always shows an image,
 * unlike the fully-optional images on other section types. `image_alt_en`
 * falls back to `image_alt_nl` (same NL-fallback convention as every other
 * bilingual field here) so it can stay nullable.
 *
 * The secondary CTA and the badge are both optional and all-or-nothing at
 * render time (see HomepageHeroContent) — a half-filled secondary button or
 * a half-filled badge is treated as "not set", never rendered broken.
 *
 * `is_active` exists for the same Content-service state-model consistency
 * every other section type has (STATE_FALLBACK/STATE_ACTIVE/STATE_HIDDEN),
 * but the admin editor deliberately does not expose a checkbox for it — the
 * Homepage Hero is never meant to be toggled off entirely from the admin UI;
 * this column only guards against an unreachable DB / missing row and keeps
 * the fallback architecture uniform with every other section.
 */
final class CreateHomepageHeroTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('homepage_hero', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_highlight_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_highlight_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'text', ['null' => true])
            ->addColumn('lead_en', 'text', ['null' => true])
            ->addColumn('primary_label_nl', 'string', ['limit' => 150])
            ->addColumn('primary_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('primary_url', 'string', ['limit' => 255])
            ->addColumn('secondary_label_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('secondary_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('secondary_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('image_alt_nl', 'string', ['limit' => 255])
            ->addColumn('image_alt_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('badge_title_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('badge_title_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('badge_text_nl', 'text', ['null' => true])
            ->addColumn('badge_text_en', 'text', ['null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['page_slug'], ['unique' => true])
            ->create();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        // Seed the exact content currently hardcoded on index.php's Hero so
        // the public site keeps rendering identical output the moment this
        // migration runs.
        $now = date('Y-m-d H:i:s');
        $table->insert([
            [
                'page_slug' => 'index',
                'eyebrow_nl' => 'Lasergravure · Nijmegen',
                'eyebrow_en' => 'Laser engraving · Nijmegen',
                'title_nl' => 'Precisie die persoonlijk aanvoelt.',
                'title_en' => 'Precision that feels personal.',
                'title_highlight_nl' => 'persoonlijk',
                'title_highlight_en' => 'personal',
                'lead_nl' => 'Van een enkele naam tot een compleet ontwerp op maat: ik graveer met de laser in hout, metaal, acryl en glas — voor cadeaus, herinneringen en bedrijven die iets bijzonders willen achterlaten.',
                'lead_en' => "From a single name to a complete custom design: I laser-engrave wood, metal, acrylic and glass — for gifts, keepsakes and businesses that want to leave a lasting impression.",
                'primary_label_nl' => 'Vraag een offerte aan',
                'primary_label_en' => 'Request a quote',
                'primary_url' => 'contact.php',
                'secondary_label_nl' => 'Bekijk portfolio',
                'secondary_label_en' => 'View portfolio',
                'secondary_url' => 'portfolio.php',
                'image_path' => 'assets/images/hero-collage-a.webp',
                'image_alt_nl' => 'MOPA-laser graveert een naam in een stalen hamer, met vonken',
                'image_alt_en' => 'MOPA laser engraving a name into a steel hammer, sparks flying',
                'badge_title_nl' => 'Live gegraveerd',
                'badge_title_en' => 'Engraved live',
                'badge_text_nl' => 'Elke gravure wordt zorgvuldig voorbereid en stap voor stap met de laser aangebracht.',
                'badge_text_en' => 'Every piece is carefully prepared, then engraved step by step with the laser.',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('homepage_hero')->drop()->save();
    }
}

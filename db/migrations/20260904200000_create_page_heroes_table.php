<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * First CMS-editable page-section type: the "Page hero" pattern that recurs
 * identically (same markup/CSS) at the top of diensten.php, portfolio.php,
 * over-mij.php, contact.php and shop.php — see docs/CMS_CONTENT_AUDIT.md,
 * "Recommended smallest next step".
 *
 * One fixed-column row per page (not a generic key/value store like
 * site_settings, because this section has a known, bounded shape) keyed by
 * `page_slug` — the same table shape can be reused as-is by a future,
 * separate website installation; that install just seeds its own rows under
 * its own page slugs.
 *
 * App\Service\PageHeroContent is the only thing that should read this table;
 * it also owns the per-slug fallback defaults (the current hardcoded
 * content) so a missing row/slug or an unreachable database never breaks a
 * page — see App\Service\SiteSettings for the same pattern.
 */
final class CreatePageHeroesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('page_heroes', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'text', ['null' => true])
            ->addColumn('lead_en', 'text', ['null' => true])
            ->addColumn('breadcrumb_label_nl', 'string', ['limit' => 150])
            ->addColumn('breadcrumb_label_en', 'string', ['limit' => 150, 'null' => true])
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

        // Seed the values currently hardcoded in each page's markup so the
        // public site keeps rendering identical output the moment this
        // migration runs.
        $now = date('Y-m-d H:i:s');
        $pages = [
            [
                'page_slug' => 'diensten',
                'eyebrow_nl' => 'Diensten',
                'eyebrow_en' => 'Services',
                'title_nl' => 'Lasergravure voor elk materiaal',
                'title_en' => 'Laser engraving for every material',
                'lead_nl' => 'Hout, metaal, acryl of glas — elk materiaal vraagt om een andere laser, instelling en afwerking. Hieronder lees je per materiaal wat er mogelijk is, met voorbeelden uit eerder werk.',
                'lead_en' => "Wood, metal, acrylic or glass — every material calls for a different laser, setting and finish. Below you'll find what's possible per material, with examples from past work.",
                'breadcrumb_label_nl' => 'Diensten',
                'breadcrumb_label_en' => 'Services',
            ],
            [
                'page_slug' => 'portfolio',
                'eyebrow_nl' => 'Portfolio',
                'eyebrow_en' => 'Portfolio',
                'title_nl' => 'Een greep uit eerder werk',
                'title_en' => 'A glimpse of past work',
                'lead_nl' => 'Van een gegraveerde snijplank voor een bruiloft tot een aluminium visitekaartje voor een barbershop — hieronder een selectie van wat er allemaal mogelijk is.',
                'lead_en' => "From an engraved cutting board for a wedding to an aluminium business card for a barbershop — below is a selection of what's possible.",
                'breadcrumb_label_nl' => 'Portfolio',
                'breadcrumb_label_en' => 'Portfolio',
            ],
            [
                'page_slug' => 'over-mij',
                'eyebrow_nl' => 'Over mij',
                'eyebrow_en' => 'About',
                'title_nl' => 'Ontwerp en ambacht, samen in één gravure',
                'title_en' => 'Design and craft, together in one engraving',
                'lead_nl' => null,
                'lead_en' => null,
                'breadcrumb_label_nl' => 'Over mij',
                'breadcrumb_label_en' => 'About',
            ],
            [
                'page_slug' => 'contact',
                'eyebrow_nl' => 'Contact',
                'eyebrow_en' => 'Contact',
                'title_nl' => 'Vertel me over jouw idee',
                'title_en' => 'Tell me about your idea',
                'lead_nl' => 'Heb je interesse in een gepersonaliseerde bestelling of zakelijke opdracht? Vul het formulier in en ik denk graag met je mee over ontwerp, materiaal en mogelijkheden.',
                'lead_en' => "Interested in a personalised order or business commission? Fill in the form and I'll be happy to think along about design, material and options.",
                'breadcrumb_label_nl' => 'Contact',
                'breadcrumb_label_en' => 'Contact',
            ],
            [
                'page_slug' => 'shop',
                'eyebrow_nl' => 'Shop',
                'eyebrow_en' => 'Shop',
                'title_nl' => 'Gegraveerde producten, klaar om te bestellen',
                'title_en' => 'Engraved products, ready to order',
                'lead_nl' => 'Naast maatwerk op aanvraag komen hier ook kant-en-klare producten die je direct kunt bestellen — de webshop wordt geleidelijk uitgebreid.',
                'lead_en' => 'Alongside custom commissions, this is where ready-made products you can order directly will appear — the shop is gradually being expanded.',
                'breadcrumb_label_nl' => 'Shop',
                'breadcrumb_label_en' => 'Shop',
            ],
        ];

        $rows = [];
        foreach ($pages as $page) {
            $rows[] = $page + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('page_heroes')->drop()->save();
    }
}

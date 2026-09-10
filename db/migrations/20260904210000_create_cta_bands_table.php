<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Second CMS-editable page-section type: the "CTA band" pattern
 * (`.cta-band.cta-band--card`) that recurs identically (same markup/CSS) at
 * the bottom of index.php, diensten.php, portfolio.php and shop.php — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #2. Same shape/rationale as
 * `page_heroes` (see that migration's docblock): one fixed-column row per
 * page, keyed by `page_slug`, because each page currently has exactly one
 * CTA band (verified by inspecting the markup before writing this schema).
 *
 * The primary button is required (every current usage has one); the
 * secondary button is fully optional (only index.php and diensten.php use
 * one) — `secondary_label_nl`/`secondary_url` being empty means "no second
 * button", not "use a default second button".
 *
 * App\Service\CtaBandContent is the only thing that should read this table;
 * it owns the per-slug fallback defaults so a missing row/slug or an
 * unreachable database never breaks a page.
 */
final class CreateCtaBandsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('cta_bands', ['id' => true]);
        $table
            ->addColumn('page_slug', 'string', ['limit' => 100])
            ->addColumn('eyebrow_nl', 'string', ['limit' => 150])
            ->addColumn('eyebrow_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'text', ['null' => true])
            ->addColumn('lead_en', 'text', ['null' => true])
            ->addColumn('primary_label_nl', 'string', ['limit' => 150])
            ->addColumn('primary_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('primary_url', 'string', ['limit' => 255])
            ->addColumn('secondary_label_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('secondary_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('secondary_url', 'string', ['limit' => 255, 'null' => true])
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
                'page_slug' => 'index',
                'eyebrow_nl' => 'Aan de slag',
                'eyebrow_en' => 'Get started',
                'title_nl' => 'Heb je een idee voor een gegraveerd product?',
                'title_en' => 'Have an idea for an engraved piece?',
                'lead_nl' => 'Stuur je wens, foto of ontwerp door — ik denk graag met je mee over materiaal, formaat en uitvoering.',
                'lead_en' => "Send over your idea, photo or design — I'm happy to help think through material, size and finish.",
                'primary_label_nl' => 'Vraag een offerte aan',
                'primary_label_en' => 'Request a quote',
                'primary_url' => 'contact.php',
                'secondary_label_nl' => 'Lees mijn verhaal',
                'secondary_label_en' => 'Read my story',
                'secondary_url' => 'over-mij.php',
            ],
            [
                'page_slug' => 'diensten',
                'eyebrow_nl' => 'Aan de slag',
                'eyebrow_en' => 'Get started',
                'title_nl' => 'Vertel me over jouw idee',
                'title_en' => 'Tell me about your idea',
                'lead_nl' => 'Welk materiaal je ook voor ogen hebt, ik denk graag met je mee over wat mogelijk is.',
                'lead_en' => "Whatever material you have in mind, I'm happy to think along about what's possible.",
                'primary_label_nl' => 'Vraag een offerte aan',
                'primary_label_en' => 'Request a quote',
                'primary_url' => 'contact.php',
                'secondary_label_nl' => 'Bekijk portfolio',
                'secondary_label_en' => 'View portfolio',
                'secondary_url' => 'portfolio.php',
            ],
            [
                'page_slug' => 'portfolio',
                'eyebrow_nl' => 'Aan de slag',
                'eyebrow_en' => 'Get started',
                'title_nl' => 'Klaar voor jouw eigen ontwerp?',
                'title_en' => 'Ready for your own design?',
                'lead_nl' => 'Stuur je idee, foto of ontwerp door — ik denk graag met je mee.',
                'lead_en' => "Send over your idea, photo or design — I'm happy to help think it through.",
                'primary_label_nl' => 'Vraag een offerte aan',
                'primary_label_en' => 'Request a quote',
                'primary_url' => 'contact.php',
                'secondary_label_nl' => null,
                'secondary_label_en' => null,
                'secondary_url' => null,
            ],
            [
                'page_slug' => 'shop',
                'eyebrow_nl' => 'Aan de slag',
                'eyebrow_en' => 'Get started',
                'title_nl' => 'Liever iets op maat?',
                'title_en' => 'Prefer something custom?',
                'lead_nl' => 'Stuur je idee, foto of ontwerp door — ik denk graag met je mee over materiaal, formaat en uitvoering.',
                'lead_en' => "Send over your idea, photo or design — I'm happy to help think through material, size and finish.",
                'primary_label_nl' => 'Vraag een offerte aan',
                'primary_label_en' => 'Request a quote',
                'primary_url' => 'contact.php',
                'secondary_label_nl' => null,
                'secondary_label_en' => null,
                'secondary_url' => null,
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
        $this->table('cta_bands')->drop()->save();
    }
}

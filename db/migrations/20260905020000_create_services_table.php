<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Ninth CMS-editable content type, and the first REUSABLE content entity:
 * "Service / material" — one row per fixed structural material/service key
 * (hout, metaal, acryl-glas, zakelijk). See the architecture review this
 * migration implements (STEP A of two): diensten.php's four
 * `.service-detail` sections (`#hout`, `#metaal`, `#acryl-glas`,
 * `#zakelijk`) currently hold this content hardcoded, duplicated a second
 * time (in condensed form) on index.php's Diensten carousel.
 *
 * Unlike every previous CMS type (keyed by page_slug + section_key, because
 * a page can gain more of that section type over time), this table is keyed
 * by a single `service_key` from a CLOSED, code-defined set — see
 * App\Service\ServiceContent::SERVICES. The four materials are fixed
 * structural identities (their anchors are hardcoded into diensten.php's
 * quicknav, the footer "Materialen" column, and — in STEP B — the homepage
 * carousel's links), so, unlike Portfolio gallery items, admins can edit
 * content but never add/remove/rename a service. This is deliberately NOT a
 * generic page builder.
 *
 * `index_label` ("01"-"04") and the alternating `bg-soft` background are
 * intentionally NOT columns here: both are 100% derivable from each
 * service's fixed position in App\Service\ServiceContent::SERVICES, exactly
 * like the checkmark icon on service_points stays presentation-owned. See
 * that class for how the position is turned into the label.
 *
 * closing_note_nl/en and cta_label_nl/en/cta_url are all nullable because
 * today only Metaal has a closing note and only Zakelijk has a CTA button —
 * the other three services genuinely have neither. The CTA fields follow
 * the same all-or-nothing convention as CtaBandContent's secondary button
 * and TextImageSplitContent's button: a half-filled CTA (label without URL,
 * or vice versa) is treated by App\Service\ServiceContent as "no CTA".
 *
 * Paragraphs, points ("kenmerken") and gallery images are normalized child
 * tables (next three migrations), not fixed paragraph_1/paragraph_2 or
 * point_1..4 columns, because the four services already have a different
 * paragraph count (1-2) and point count (3-4) today, and Hout/Metaal have a
 * 3-image gallery while Acryl & glas/Zakelijk have none.
 *
 * This table intentionally does NOT yet carry any homepage-carousel/teaser
 * fields (teaser body, teaser tags, teaser media) — that is STEP B's scope.
 * The `services` row is designed so STEP B can add those as nullable columns
 * (or a small child table, for the tags) without touching this schema.
 *
 * Seeded here with the four fixed keys and their general content
 * (title/lead/closing note/CTA) so the public site keeps rendering identical
 * output the moment this migration runs; paragraphs/points/images are
 * seeded by the next three migrations, once this table's ids exist.
 */
final class CreateServicesTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('services', ['id' => true]);
        $table
            ->addColumn('service_key', 'string', ['limit' => 50])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('lead_nl', 'string', ['limit' => 500])
            ->addColumn('lead_en', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('closing_note_nl', 'text', ['null' => true])
            ->addColumn('closing_note_en', 'text', ['null' => true])
            ->addColumn('cta_label_nl', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('cta_label_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('cta_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['service_key'], ['unique' => true])
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
        $table->insert([
            [
                'service_key' => 'hout',
                'title_nl' => 'Hout graveren',
                'title_en' => 'Wood engraving',
                'lead_nl' => 'Persoonlijke gravures op hout, van kleine cadeaus tot uniek maatwerk.',
                'lead_en' => 'Personal engravings on wood, from small gifts to unique custom pieces.',
                'closing_note_nl' => null,
                'closing_note_en' => null,
                'cta_label_nl' => null,
                'cta_label_en' => null,
                'cta_url' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'service_key' => 'metaal',
                'title_nl' => 'Metaal graveren',
                'title_en' => 'Metal engraving',
                'lead_nl' => 'Duurzame en nauwkeurige gravures op metaal, van persoonlijke cadeaus tot professioneel maatwerk.',
                'lead_en' => 'Durable, precise engravings on metal, from personal gifts to professional custom work.',
                'closing_note_nl' => 'Ook kleine, gevoelige stukken zoals een herinneringssieraad of urn graveer ik met evenveel zorg en aandacht als een groter product.',
                'closing_note_en' => 'Small, sensitive pieces such as a memorial pendant or urn are engraved with just as much care and attention as a larger product.',
                'cta_label_nl' => null,
                'cta_label_en' => null,
                'cta_url' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'service_key' => 'acryl-glas',
                'title_nl' => 'Acryl & glas',
                'title_en' => 'Acrylic & glass',
                'lead_nl' => 'Strak, doorschijnend en modern — een ander karakter dan hout of metaal.',
                'lead_en' => 'Sleek, translucent and modern — a different character from wood or metal.',
                'closing_note_nl' => null,
                'closing_note_en' => null,
                'cta_label_nl' => null,
                'cta_label_en' => null,
                'cta_url' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'service_key' => 'zakelijk',
                'title_nl' => 'Zakelijk & maatwerk in aantallen',
                'title_en' => 'Business & bulk custom work',
                'lead_nl' => 'Voor bedrijven die iets bijzonders willen achterlaten.',
                'lead_en' => 'For businesses that want to leave a lasting impression.',
                'closing_note_nl' => null,
                'closing_note_en' => null,
                'cta_label_nl' => 'Bespreek jouw zakelijke aanvraag',
                'cta_label_en' => 'Discuss your business request',
                'cta_url' => 'contact.php',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ])->saveData();
    }

    public function down(): void
    {
        $this->table('services')->drop()->save();
    }
}

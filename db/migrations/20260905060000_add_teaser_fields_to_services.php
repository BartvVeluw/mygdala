<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * STEP B of the Service/Material entity (see
 * 20260905020000_create_services_table.php's docblock, "STEP B" note):
 * extends `services` with the homepage-carousel projection so index.php's
 * Diensten carousel can read the SAME four rows diensten.php already reads,
 * instead of maintaining a second, independently authored content set.
 *
 * Purely additive, nullable columns — nothing about the existing
 * diensten.php schema/behaviour changes:
 *
 * - teaser_body_nl/en: the condensed paragraph the homepage card shows
 *   (shorter than diensten.php's lead + paragraphs).
 * - teaser_image_path: the homepage card's own media. Deliberately separate
 *   from service_images (that gallery is diensten.php-only, and Zakelijk's
 *   homepage card image was never part of its gallery — Zakelijk's
 *   `service_images` is empty). NULL means "use the fixed icon-fallback
 *   presentation" (today only Acryl & glas) rather than a separate
 *   presentation-mode column, per the architecture review.
 * - teaser_image_alt_nl/en: the homepage card image needs its own alt text
 *   (the same photo's diensten.php gallery alt text describes it as a
 *   gallery photo, not as this card's illustrative image — e.g. Hout's
 *   gallery alt is "Gegraveerde snijplank..." while the homepage card alt is
 *   "Gegraveerde houten snijplank..."). Nullable because a NULL
 *   teaser_image_path has no image to describe.
 *
 * Backfilled here with the exact content that was hardcoded on index.php's
 * orbit-carousel cards before this migration, so the public homepage keeps
 * rendering identical output the moment this migration runs. Teaser tags are
 * a separate child table (next migration).
 */
final class AddTeaserFieldsToServices extends AbstractMigration
{
    public function up(): void
    {
        $this->table('services')
            ->addColumn('teaser_body_nl', 'text', ['null' => true])
            ->addColumn('teaser_body_en', 'text', ['null' => true])
            ->addColumn('teaser_image_path', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('teaser_image_alt_nl', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('teaser_image_alt_en', 'string', ['limit' => 255, 'null' => true])
            ->update();

        if (InstallState::isFreshInstall($this)) {
            // Everything below is Van Veluw Laserdesign's own page
            // content, lifted out of the templates it used to be
            // hardcoded in. An installation with no history to preserve
            // gets the empty table and builds its own pages.
            // See src/Install/InstallState.php.
            return;
        }

        $teasers = [
            'hout' => [
                'teaser_body_nl' => 'Snijplanken, onderzetters, kruidenkistjes, naambordjes en wanddecoratie: hout krijgt door een gravure direct een warme, persoonlijke uitstraling. Elke plank heeft zijn eigen nerf, dus geen twee stukken zijn precies gelijk.',
                'teaser_body_en' => 'Cutting boards, coasters, spice boxes, name signs and wall decor: engraving gives wood an instantly warm, personal character. Every plank has its own grain, so no two pieces are ever quite the same.',
                'teaser_image_path' => 'assets/images/snijplank-just-married.webp',
                'teaser_image_alt_nl' => 'Gegraveerde houten snijplank met tekst Just Married',
                'teaser_image_alt_en' => 'Engraved wooden cutting board reading Just Married',
            ],
            'metaal' => [
                'teaser_body_nl' => 'Gereedschap, aluminium visitekaartjes, naamplaatjes en sieraden: een metalen gravure slijt niet weg en blijft jarenlang scherp zichtbaar. Ideaal voor een cadeau met betekenis of een representatief bedrijfsproduct.',
                'teaser_body_en' => "Tools, aluminium business cards, nameplates and jewellery: a metal engraving doesn't wear away and stays crisp for years. Perfect for a meaningful gift or a business item that makes an impression.",
                'teaser_image_path' => 'assets/images/visitekaartje-metaal-barbershop.webp',
                'teaser_image_alt_nl' => 'Metalen visitekaartje met lasergravure voor een barbershop',
                'teaser_image_alt_en' => 'Laser-engraved metal business card for a barbershop',
            ],
            'acryl-glas' => [
                'teaser_body_nl' => 'Naast hout en metaal werk ik ook met acrylaat: van doorschijnende naambordjes tot displays en awards. Glaswerk is op aanvraag mogelijk — vraag gerust naar de opties voor jouw ontwerp.',
                'teaser_body_en' => 'Alongside wood and metal, I also work with acrylic: from translucent name signs to displays and awards. Glasswork is available on request — just ask about the options for your design.',
                'teaser_image_path' => null,
                'teaser_image_alt_nl' => null,
                'teaser_image_alt_en' => null,
            ],
            'zakelijk' => [
                'teaser_body_nl' => 'Relatiegeschenken met logo, gereedschap met bedrijfsnaam, seriematige naamplaatjes of aluminium visitekaartjes: voor grotere aantallen denk ik graag mee over ontwerp, materiaal, planning en afwerking.',
                'teaser_body_en' => "Branded gifts, tools marked with your company name, batches of nameplates or aluminium business cards: for larger quantities I'm happy to help plan the design, material, timeline and finish.",
                'teaser_image_path' => 'assets/images/hero-visitekaartje-aluminium.webp',
                'teaser_image_alt_nl' => 'Luxe aluminium visitekaartje met lasergravure',
                'teaser_image_alt_en' => 'Premium aluminium business card with laser engraving',
            ],
        ];

        foreach ($teasers as $serviceKey => $fields) {
            $this->query(
                'UPDATE services SET teaser_body_nl = ?, teaser_body_en = ?, teaser_image_path = ?, teaser_image_alt_nl = ?, teaser_image_alt_en = ? WHERE service_key = ?',
                [
                    $fields['teaser_body_nl'],
                    $fields['teaser_body_en'],
                    $fields['teaser_image_path'],
                    $fields['teaser_image_alt_nl'],
                    $fields['teaser_image_alt_en'],
                    $serviceKey,
                ]
            );
        }
    }

    public function down(): void
    {
        $this->table('services')
            ->removeColumn('teaser_body_nl')
            ->removeColumn('teaser_body_en')
            ->removeColumn('teaser_image_path')
            ->removeColumn('teaser_image_alt_nl')
            ->removeColumn('teaser_image_alt_en')
            ->update();
    }
}

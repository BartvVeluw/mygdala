<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * "Kenmerken" (points) for the services repeater (see
 * 20260905020000_create_services_table.php's docblock) — the
 * `.service-detail__point` cards (icon + title + body) under each material's
 * intro text, 3-4 per service on diensten.php today.
 *
 * No icon column: the checkmark SVG is identical and fixed for every point
 * on every service (`.service-detail__point svg`) — presentation-owned by
 * the theme, same as how FeatureGridContent's icon_key is the ONLY
 * CMS-configurable icon field in this codebase, precisely because that type
 * genuinely offers a per-card icon choice in the current markup and this one
 * does not.
 *
 * is_active lets a single point be hidden without deleting it — same
 * convention as feature_grid_items/faq_items (cards with individually
 * meaningful hidden state), unlike service_paragraphs/service_images (see
 * their own migrations) where a "hidden but kept" state has no real use and
 * deleting is enough. sort_order uses the same "swap with neighbour"
 * convention as every other repeater here.
 *
 * Seeded with the exact points currently hardcoded per material on
 * diensten.php, so the public site keeps rendering identical output the
 * moment this migration runs.
 */
final class CreateServicePointsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('service_points', ['id' => true]);
        $table
            ->addColumn('service_id', 'integer', ['signed' => false])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body_nl', 'text')
            ->addColumn('body_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('service_id', 'services', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['service_id'])
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

        $houtId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'hout'")['id'];
        $metaalId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'metaal'")['id'];
        $acrylId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'acryl-glas'")['id'];
        $zakelijkId = (int) $this->fetchRow("SELECT id FROM services WHERE service_key = 'zakelijk'")['id'];

        $items = [
            // Hout
            ['service_id' => $houtId, 'title_nl' => "Namen & teksten", 'title_en' => 'Names & text', 'body_nl' => 'Een naam, datum, quote of familienaam maakt een houten product direct persoonlijk.', 'body_en' => 'A name, date, quote or family name instantly personalises a wooden product.', 'sort_order' => 0],
            ['service_id' => $houtId, 'title_nl' => "Logo's & bedrijfsnamen", 'title_en' => 'Logos & company names', 'body_nl' => 'Interessant voor relatiegeschenken, displays, verpakkingen of bordjes met logo.', 'body_en' => 'Useful for corporate gifts, displays, packaging or signage carrying your logo.', 'sort_order' => 1],
            ['service_id' => $houtId, 'title_nl' => 'Tekeningen & illustraties', 'title_en' => 'Drawings & illustrations', 'body_nl' => 'Een lijntekening, symbool, kaart, dier of ander ontwerp kan mooi worden omgezet naar een gravure.', 'body_en' => 'A line drawing, symbol, map, animal or other design can be beautifully translated into an engraving.', 'sort_order' => 2],
            ['service_id' => $houtId, 'title_nl' => 'Eigen idee of maatwerk', 'title_en' => 'Your own idea or custom work', 'body_nl' => 'Heb je een idee dat nog niet hierboven staat? Stuur het gerust, dan kijk ik wat mogelijk is.', 'body_en' => "Have an idea that isn't listed here? Feel free to send it — I'll look at what's possible.", 'sort_order' => 3],
            // Metaal
            ['service_id' => $metaalId, 'title_nl' => 'Gereedschap', 'title_en' => 'Tools', 'body_nl' => 'Naam, bedrijfsnaam, logo of nummer — handig om terug te vinden, mooi als cadeau.', 'body_en' => 'Name, company name, logo or number — handy for keeping track, nice as a gift.', 'sort_order' => 0],
            ['service_id' => $metaalId, 'title_nl' => 'Aluminium visitekaartjes', 'title_en' => 'Aluminium business cards', 'body_nl' => 'Valt direct op en gaat veel langer mee dan een papieren kaartje. Ook met QR-code mogelijk.', 'body_en' => 'Stands out immediately and lasts far longer than a paper card. A QR code can be included.', 'sort_order' => 1],
            ['service_id' => $metaalId, 'title_nl' => 'Sieraden & herinneringen', 'title_en' => 'Jewellery & keepsakes', 'body_nl' => 'Initialen, een datum of korte boodschap op een hanger, ring of ander persoonlijk voorwerp.', 'body_en' => 'Initials, a date or short message on a pendant, ring or other personal item.', 'sort_order' => 2],
            ['service_id' => $metaalId, 'title_nl' => 'Naamplaatjes', 'title_en' => 'Nameplates', 'body_nl' => 'Voor deuren, kantoren of producten — eventueel in serie voor meerdere exemplaren.', 'body_en' => 'For doors, offices or products — available in batches for multiple pieces.', 'sort_order' => 3],
            // Acryl & glas
            ['service_id' => $acrylId, 'title_nl' => 'Naambordjes & displays', 'title_en' => 'Name signs & displays', 'body_nl' => 'Strak en modern, mooi voor woning of bedrijfspand.', 'body_en' => 'Sleek and modern, a great fit for a home or business premises.', 'sort_order' => 0],
            ['service_id' => $acrylId, 'title_nl' => 'Awards & erkenning', 'title_en' => 'Awards & recognition', 'body_nl' => 'Een gegraveerde acrylaat award voelt bijzonder en representatief.', 'body_en' => 'An engraved acrylic award feels special and professional.', 'sort_order' => 1],
            ['service_id' => $acrylId, 'title_nl' => 'Glas op aanvraag', 'title_en' => 'Glass on request', 'body_nl' => 'Glaswerk vraagt maatwerk per project — neem contact op om de opties te bespreken.', 'body_en' => 'Glasswork is bespoke per project — get in touch to discuss the options.', 'sort_order' => 2],
            // Zakelijk
            ['service_id' => $zakelijkId, 'title_nl' => 'Relatiegeschenken', 'title_en' => 'Corporate gifts', 'body_nl' => 'Met logo of bedrijfsnaam, in hout of metaal, als attentie voor klanten of medewerkers.', 'body_en' => 'With logo or company name, in wood or metal, as a gift for clients or staff.', 'sort_order' => 0],
            ['service_id' => $zakelijkId, 'title_nl' => 'Bulkbestellingen', 'title_en' => 'Bulk orders', 'body_nl' => 'Seriematige naamplaatjes, kaartjes of onderdelen met consistente kwaliteit.', 'body_en' => 'Batches of nameplates, cards or parts with consistent quality.', 'sort_order' => 1],
            ['service_id' => $zakelijkId, 'title_nl' => 'Serienummers & markering', 'title_en' => 'Serial numbers & marking', 'body_nl' => 'Traceerbare markering op onderdelen, gereedschap of producten.', 'body_en' => 'Traceable marking on parts, tools or products.', 'sort_order' => 2],
            ['service_id' => $zakelijkId, 'title_nl' => 'Meedenken & plannen', 'title_en' => 'Planning together', 'body_nl' => 'Overleg over ontwerp, materiaal en levertijd, afgestemd op jouw aantallen.', 'body_en' => 'Discussion on design, material and delivery time, matched to your quantities.', 'sort_order' => 3],
        ];

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $item + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('service_points')->drop()->save();
    }
}

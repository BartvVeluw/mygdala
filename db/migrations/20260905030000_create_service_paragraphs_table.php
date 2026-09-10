<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Body paragraphs for the services repeater (see previous migration's
 * docblock). Named content_nl/content_en, same convention as
 * text_image_split_paragraphs — one row per plain-text paragraph, no fixed
 * count (Hout/Metaal/Zakelijk currently have 2, Acryl & glas has 1).
 *
 * No is_active column, same reasoning as text_image_split_paragraphs: a
 * paragraph has no meaningful "hidden but kept" state the admin UI needs —
 * deleting it is enough. sort_order controls display order using the same
 * "swap with neighbour" convention as every other repeater in this codebase.
 *
 * Seeded with the exact paragraphs currently hardcoded in diensten.php's
 * four `.service-detail__head` sections, so the public site keeps rendering
 * identical output the moment this migration runs.
 */
final class CreateServiceParagraphsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('service_paragraphs', ['id' => true]);
        $table
            ->addColumn('service_id', 'integer', ['signed' => false])
            ->addColumn('content_nl', 'text')
            ->addColumn('content_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
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

        $rows = [
            [
                'service_id' => $houtId,
                'content_nl' => 'Een naam, tekst, tekening, logo of foto wordt met de laser nauwkeurig in het hout aangebracht. Denk aan snijplanken, onderzetters, kruidenkistjes, naambordjes, wanddecoratie en persoonlijke cadeaus. Heb je nog geen kant-en-klaar ontwerp? Dan denk ik graag mee over een ontwerp dat bij het product past.',
                'content_en' => "A name, text, drawing, logo or photo is precisely engraved into the wood with the laser. Think cutting boards, coasters, spice boxes, name signs, wall decor and personal gifts. No ready-made design yet? I'm happy to help create one that suits the product.",
                'sort_order' => 0,
            ],
            [
                'service_id' => $houtId,
                'content_nl' => 'Hout heeft een warme, natuurlijke uitstraling en elke plank heeft zijn eigen nerf en kleurverschillen — geen twee gegraveerde stukken zijn precies gelijk. Anders dan een sticker of opdruk slijt een lasergravure niet weg.',
                'content_en' => "Wood has a warm, natural character, and every plank has its own grain and colour variation — no two engraved pieces are ever quite the same. Unlike a sticker or print, a laser engraving doesn't wear off.",
                'sort_order' => 1,
            ],
            [
                'service_id' => $metaalId,
                'content_nl' => 'Een naam, tekst, logo of afbeelding wordt met de laser nauwkeurig op het metaal aangebracht. Ik graveer zowel voor particulieren als bedrijven: gereedschap, aluminium visitekaartjes, naamplaatjes, sleutelhangers, sieraden en meer.',
                'content_en' => 'A name, text, logo or image is precisely engraved onto the metal with the laser. I engrave for both individuals and businesses: tools, aluminium business cards, nameplates, keyrings, jewellery and more.',
                'sort_order' => 0,
            ],
            [
                'service_id' => $metaalId,
                'content_nl' => 'Heb je zelf een metalen product dat je wilt laten graveren, zoals een herinneringsstuk of een cadeau met betekenis? Stuur een foto en het materiaal mee, dan bekijk ik wat technisch mogelijk is.',
                'content_en' => "Have your own metal item you'd like engraved, such as a keepsake or a meaningful gift? Send a photo and the material, and I'll look at what's technically possible.",
                'sort_order' => 1,
            ],
            [
                'service_id' => $acrylId,
                'content_nl' => 'Naast hout en metaal werk ik ook met acrylaat: denk aan doorschijnende naambordjes, displays en awards met een strakke, moderne uitstraling. Glaswerk is op aanvraag mogelijk — vraag gerust naar de opties voor jouw ontwerp en materiaal.',
                'content_en' => 'Alongside wood and metal, I also work with acrylic: think translucent name signs, displays and awards with a clean, modern look. Glasswork is available on request — just ask about the options for your design and material.',
                'sort_order' => 0,
            ],
            [
                'service_id' => $zakelijkId,
                'content_nl' => "Van een enkel gegraveerd relatiegeschenk tot een seriematige bestelling: ik werk voor zzp'ers, ondernemers en bedrijven aan onder andere relatiegeschenken met logo, gereedschap met bedrijfsnaam, aluminium visitekaartjes en naamplaatjes in aantallen.",
                'content_en' => 'From a single engraved corporate gift to a batch order: I work with freelancers, entrepreneurs and companies on branded corporate gifts, tools marked with a company name, aluminium business cards and nameplates in quantity.',
                'sort_order' => 0,
            ],
            [
                'service_id' => $zakelijkId,
                'content_nl' => 'Voor grotere aantallen is vooroverleg extra fijn, zodat we samen kunnen kijken naar ontwerp, materiaal, afwerking en levertijd. Neem gerust contact op om de mogelijkheden te bespreken.',
                'content_en' => 'For larger quantities, an initial conversation really helps — together we can plan the design, material, finish and delivery time. Feel free to get in touch to discuss the options.',
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
        $this->table('service_paragraphs')->drop()->save();
    }
}

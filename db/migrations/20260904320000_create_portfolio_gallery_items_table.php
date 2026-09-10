<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Items for the portfolio_galleries repeater (see previous migration's
 * docblock). Each item is one `.gallery-item` card: image + alt, overlay
 * title + subtitle, and one or more categories.
 *
 * `categories` stores the exact same space-separated token string the
 * frontend already puts in `data-category` (e.g. "metaal zakelijk") — see
 * portfolio.php's filter bar / assets/js/main.js `initFilters()`, which
 * splits on whitespace. Deliberately not a separate join table: the
 * taxonomy itself (hout/metaal/zakelijk) is small, fixed, and owned by
 * App\Service\PortfolioGalleryContent::CATEGORY_KEYS (same "controlled key
 * set" convention as FeatureGridContent::ICON_KEYS), so a plain validated
 * string column is enough and keeps the admin form a simple checkbox group.
 *
 * image_path/alt_nl/alt_en follow the same convention as
 * text_image_split_images (image_path is either an admin upload under
 * assets/images/sections/, or — for this seed — one of the site's existing
 * shared photos; App\Service\SectionImageUploader::delete() only ever
 * touches the former).
 *
 * Seeded with the exact 14 items currently hardcoded on portfolio.php (same
 * order) so the public site keeps rendering identical output the moment
 * this migration runs. Every seeded item already has real, distinct NL/EN
 * text in the current markup, so no *_en column is seeded null here (unlike
 * some earlier section types).
 */
final class CreatePortfolioGalleryItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('portfolio_gallery_items', ['id' => true]);
        $table
            ->addColumn('portfolio_gallery_id', 'integer', ['signed' => false])
            ->addColumn('image_path', 'string', ['limit' => 255])
            ->addColumn('alt_nl', 'string', ['limit' => 255])
            ->addColumn('alt_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_nl', 'string', ['limit' => 150])
            ->addColumn('title_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('subtitle_nl', 'string', ['limit' => 150])
            ->addColumn('subtitle_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('categories', 'string', ['limit' => 100])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('portfolio_gallery_id', 'portfolio_galleries', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['portfolio_gallery_id'])
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

        $galleryId = (int) $this->fetchRow(
            "SELECT id FROM portfolio_galleries WHERE page_slug = 'portfolio' AND section_key = 'gallery'"
        )['id'];

        $items = [
            [
                'image_path' => 'assets/images/snijplank-just-married.webp',
                'alt_nl' => 'Gegraveerde snijplank met tekst Just Married',
                'alt_en' => 'Engraved cutting board reading Just Married',
                'title_nl' => 'Snijplank — Just Married',
                'title_en' => 'Cutting board — Just Married',
                'subtitle_nl' => 'Bruiloftscadeau, hout',
                'subtitle_en' => 'Wedding gift, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/visitekaartje-metaal-barbershop.webp',
                'alt_nl' => 'Metalen visitekaartje met lasergravure voor een barbershop',
                'alt_en' => 'Laser-engraved metal business card for a barbershop',
                'title_nl' => 'Black Razor Barbershop',
                'title_en' => 'Black Razor Barbershop',
                'subtitle_nl' => 'Visitekaartje, metaal',
                'subtitle_en' => 'Business card, metal',
                'categories' => 'metaal',
            ],
            [
                'image_path' => 'assets/images/skyline-nijmegen-hout.webp',
                'alt_nl' => 'Houten wanddecoratie met skyline van Nijmegen',
                'alt_en' => 'Wooden wall decor featuring the Nijmegen skyline',
                'title_nl' => 'Skyline Nijmegen',
                'title_en' => 'Nijmegen skyline',
                'subtitle_nl' => 'Wanddecoratie, hout',
                'subtitle_en' => 'Wall decor, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/nec-stadion-wanddecoratie.webp',
                'alt_nl' => 'Houten wanddecoratie van het N.E.C. stadion',
                'alt_en' => 'Wooden wall decor of the N.E.C. stadium',
                'title_nl' => 'N.E.C. stadion',
                'title_en' => 'N.E.C. stadium',
                'subtitle_nl' => 'Wanddecoratie, hout',
                'subtitle_en' => 'Wall decor, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/medaille-heuvelenloop.webp',
                'alt_nl' => 'Gepersonaliseerde medaille van de Zevenheuvelenloop',
                'alt_en' => 'Personalised Zevenheuvelenloop medal',
                'title_nl' => 'Zevenheuvelenloop medaille',
                'title_en' => 'Zevenheuvelenloop medal',
                'subtitle_nl' => 'Gepersonaliseerd, metaal',
                'subtitle_en' => 'Personalised, metal',
                'categories' => 'metaal',
            ],
            [
                'image_path' => 'assets/images/mama-puzzelbord.webp',
                'alt_nl' => 'Houten puzzelbord met persoonlijke tekst',
                'alt_en' => 'Wooden puzzle board with personal text',
                'title_nl' => 'Puzzelbord voor Moederdag',
                'title_en' => "Mother's Day puzzle board",
                'subtitle_nl' => 'Cadeau, hout',
                'subtitle_en' => 'Gift, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/hero-visitekaartje-aluminium.webp',
                'alt_nl' => 'Luxe aluminium visitekaartje met lasergravure',
                'alt_en' => 'Premium aluminium business card with laser engraving',
                'title_nl' => 'Aluminium visitekaartje',
                'title_en' => 'Aluminium business card',
                'subtitle_nl' => 'Zakelijk, metaal',
                'subtitle_en' => 'Business, metal',
                'categories' => 'metaal zakelijk',
            ],
            [
                'image_path' => 'assets/images/naambordje-olifant.webp',
                'alt_nl' => 'Houten naambordje met olifant voor kinderkamer',
                'alt_en' => 'Wooden nursery name sign with elephant design',
                'title_nl' => 'Naambordje — olifant',
                'title_en' => 'Name sign — elephant',
                'subtitle_nl' => 'Kinderkamer, hout',
                'subtitle_en' => 'Nursery, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/hero-collage-c.webp',
                'alt_nl' => 'Set gereedschap gegraveerd met naam en logo',
                'alt_en' => 'Set of tools engraved with a name and logo',
                'title_nl' => 'Gereedschapsset met logo',
                'title_en' => 'Tool set with logo',
                'subtitle_nl' => 'Zakelijk, metaal',
                'subtitle_en' => 'Business, metal',
                'categories' => 'metaal zakelijk',
            ],
            [
                'image_path' => 'assets/images/naambordje-dinosaurus.webp',
                'alt_nl' => 'Houten naambordje met dinosaurus voor kinderkamer',
                'alt_en' => 'Wooden nursery name sign with dinosaur design',
                'title_nl' => 'Naambordje — dinosaurus',
                'title_en' => 'Name sign — dinosaur',
                'subtitle_nl' => 'Kinderkamer, hout',
                'subtitle_en' => 'Nursery, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/hero-collage-b.webp',
                'alt_nl' => 'Gegraveerde hamer met tekst Van Leo Afblijven',
                'alt_en' => 'Engraved hammer reading Van Leo Hands Off',
                'title_nl' => 'Gepersonaliseerde hamer',
                'title_en' => 'Personalised hammer',
                'subtitle_nl' => 'Cadeau, metaal',
                'subtitle_en' => 'Gift, metal',
                'categories' => 'metaal',
            ],
            [
                'image_path' => 'assets/images/naambordje-dolfijn.webp',
                'alt_nl' => 'Houten kinderkamerdecoratie met dolfijn en naam',
                'alt_en' => 'Wooden nursery decor with dolphin and name',
                'title_nl' => 'Naambordje — dolfijn',
                'title_en' => 'Name sign — dolphin',
                'subtitle_nl' => 'Kinderkamer, hout',
                'subtitle_en' => 'Nursery, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/naambordje-beer.webp',
                'alt_nl' => 'Houten naambordje met beer voor kinderkamer',
                'alt_en' => 'Wooden nursery name sign with bear design',
                'title_nl' => 'Naambordje — beer',
                'title_en' => 'Name sign — bear',
                'subtitle_nl' => 'Kinderkamer, hout',
                'subtitle_en' => 'Nursery, wood',
                'categories' => 'hout',
            ],
            [
                'image_path' => 'assets/images/hero-collage-a.webp',
                'alt_nl' => 'MOPA-laser graveert een naam in een stalen hamer',
                'alt_en' => 'MOPA laser engraving a name into a steel hammer',
                'title_nl' => 'Live graveren met de MOPA-laser',
                'title_en' => 'Live engraving with the MOPA laser',
                'subtitle_nl' => 'Proces, metaal',
                'subtitle_en' => 'Process, metal',
                'categories' => 'metaal',
            ],
        ];

        $rows = [];
        foreach ($items as $sortOrder => $item) {
            $rows[] = $item + [
                'portfolio_gallery_id' => $galleryId,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('portfolio_gallery_items')->drop()->save();
    }
}

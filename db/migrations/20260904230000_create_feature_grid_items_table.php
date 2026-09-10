<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Cards for the feature_grids repeater (see previous migration's docblock).
 * icon_key is a controlled identifier (App\Service\FeatureGridContent::ICON_KEYS),
 * never raw SVG/HTML — the frontend/theme maps each key to its existing,
 * hardcoded SVG markup (partials/feature-icons.php), so the CMS can never
 * inject arbitrary markup here.
 *
 * sort_order controls display order using the same "swap with neighbour"
 * convention as product_images.sort_order (see
 * ProductImageRepository::moveImage / FeatureGridRepository::moveItem).
 * is_active lets a single card be hidden without deleting it — a card that
 * is deleted (row gone) and a card that is merely hidden (is_active = 0)
 * are deliberately different: see App\Service\FeatureGridContent for how
 * that distinction is preserved when the database is unreachable.
 *
 * Seeded with the exact 3 + 4 cards currently hardcoded on index.php and
 * over-mij.php respectively, so the public site keeps rendering identical
 * output the moment this migration runs.
 */
final class CreateFeatureGridItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('feature_grid_items', ['id' => true]);
        $table
            ->addColumn('feature_grid_id', 'integer', ['signed' => false])
            ->addColumn('icon_key', 'string', ['limit' => 50])
            ->addColumn('title_nl', 'string', ['limit' => 255])
            ->addColumn('title_en', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('body_nl', 'text')
            ->addColumn('body_en', 'text', ['null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('feature_grid_id', 'feature_grids', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['feature_grid_id'])
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

        $valuePropsId = (int) $this->fetchRow(
            "SELECT id FROM feature_grids WHERE page_slug = 'index' AND section_key = 'value-props'"
        )['id'];
        $mijnStijlId = (int) $this->fetchRow(
            "SELECT id FROM feature_grids WHERE page_slug = 'over-mij' AND section_key = 'mijn-stijl'"
        )['id'];

        $items = [
            // index.php — Value props (no section heading; see previous migration)
            [
                'feature_grid_id' => $valuePropsId,
                'icon_key' => 'precision',
                'title_nl' => 'Ontwerp op maat',
                'title_en' => 'Custom design',
                'body_nl' => 'Heb je nog geen kant-en-klaar bestand? Ik denk mee over vorm, materiaal en plaatsing tot het ontwerp klopt.',
                'body_en' => "No ready-made file yet? I'll help shape the design, material and placement until it feels right.",
                'sort_order' => 0,
            ],
            [
                'feature_grid_id' => $valuePropsId,
                'icon_key' => 'heart',
                'title_nl' => 'Persoonlijk contact',
                'title_en' => 'Personal contact',
                'body_nl' => 'Direct contact met de maker, geen tussenpersoon. Je weet altijd bij wie je aanvraag terechtkomt.',
                'body_en' => "Direct contact with the maker, no middleman. You always know who's handling your request.",
                'sort_order' => 1,
            ],
            [
                'feature_grid_id' => $valuePropsId,
                'icon_key' => 'diamond',
                'title_nl' => 'Blijvende gravure',
                'title_en' => 'A lasting engraving',
                'body_nl' => 'Een lasergravure slijt niet zoals een sticker of opdruk — hij wordt echt in het materiaal aangebracht.',
                'body_en' => "A laser engraving doesn't wear off like a sticker or print — it's etched right into the material.",
                'sort_order' => 2,
            ],
            // over-mij.php — Mijn stijl
            [
                'feature_grid_id' => $mijnStijlId,
                'icon_key' => 'diamond',
                'title_nl' => 'Ontwerp én ambacht',
                'title_en' => 'Design and craft',
                'body_nl' => 'Ik denk niet alleen mee over de gravure, maar ook over de vorm en het materiaal van het product zelf.',
                'body_en' => "I don't just think about the engraving, but also the shape and material of the product itself.",
                'sort_order' => 0,
            ],
            [
                'feature_grid_id' => $mijnStijlId,
                'icon_key' => 'heart',
                'title_nl' => 'Warme, persoonlijke stijl',
                'title_en' => 'A warm, personal style',
                'body_nl' => 'Eenvoud met karakter — geen massaproductie, maar werk dat aandacht heeft gekregen.',
                'body_en' => "Simplicity with character — not mass production, but work that's been given real attention.",
                'sort_order' => 1,
            ],
            [
                // No data-nl/data-en attributes in the original markup for
                // this card's title (a technical term, same in both
                // languages) — title_en stays null so it falls back to
                // title_nl, matching the site's existing NL-fallback
                // convention and producing identical rendered output.
                'feature_grid_id' => $mijnStijlId,
                'icon_key' => 'precision',
                'title_nl' => 'CO₂ & MOPA laser',
                'title_en' => null,
                'body_nl' => 'Met twee lasertechnieken kan ik uiteenlopende materialen aan, van hout tot staal.',
                'body_en' => 'With two laser technologies, I can work with a wide range of materials, from wood to steel.',
                'sort_order' => 2,
            ],
            [
                'feature_grid_id' => $mijnStijlId,
                'icon_key' => 'location',
                'title_nl' => 'Gevestigd in Nijmegen',
                'title_en' => 'Based in Nijmegen',
                'body_nl' => 'Mijn werkplaats staat in Nijmegen; ophalen is altijd mogelijk, verzenden ook.',
                'body_en' => 'My workshop is in Nijmegen; pickup is always possible, and so is shipping.',
                'sort_order' => 3,
            ],
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
        $this->table('feature_grid_items')->drop()->save();
    }
}

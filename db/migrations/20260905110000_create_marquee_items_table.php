<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Items for the marquee_sections repeater (see previous migration's
 * docblock). Each row is one scrolling label (e.g. "Hout" / "Wood") — no
 * icon, no url, no separator: the CSS `::after` glyph between items and the
 * scroll animation stay entirely theme-owned (see assets/css/style.css
 * `.marquee__track span::after` and the `marquee` keyframes), only the text
 * itself moves into the database.
 *
 * Same sort_order "swap with neighbour" and is_active hide-without-delete
 * conventions as stat_strip_items/step_list_items.
 *
 * Seeded with the exact 6 labels currently hardcoded in assets/js/main.js's
 * MARQUEE_ITEMS array, so the public site keeps rendering identical output
 * the moment this migration runs.
 */
final class CreateMarqueeItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('marquee_items', ['id' => true]);
        $table
            ->addColumn('marquee_section_id', 'integer', ['signed' => false])
            ->addColumn('label_nl', 'string', ['limit' => 100])
            ->addColumn('label_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('marquee_section_id', 'marquee_sections', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['marquee_section_id'])
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

        $sectionId = (int) $this->fetchRow(
            "SELECT id FROM marquee_sections WHERE page_slug = 'index' AND section_key = 'materialenband'"
        )['id'];

        $items = [
            ['label_nl' => 'Hout', 'label_en' => 'Wood'],
            ['label_nl' => 'Metaal', 'label_en' => 'Metal'],
            ['label_nl' => 'Acryl & Glas', 'label_en' => 'Acrylic & Glass'],
            ['label_nl' => 'Zakelijk', 'label_en' => 'Business'],
            ['label_nl' => 'Maatwerk', 'label_en' => 'Custom work'],
            ['label_nl' => 'Cadeaus', 'label_en' => 'Gifts'],
        ];

        $rows = [];
        foreach ($items as $index => $item) {
            $rows[] = $item + [
                'marquee_section_id' => $sectionId,
                'sort_order' => $index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $table->insert($rows)->saveData();
    }

    public function down(): void
    {
        $this->table('marquee_items')->drop()->save();
    }
}

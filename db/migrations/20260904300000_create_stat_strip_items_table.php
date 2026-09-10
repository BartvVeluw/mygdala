<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Stats for the stat_strips repeater (see previous migration's docblock).
 * primary_text is the `<strong>` value, secondary_text is the `<span>`
 * value — e.g. primary "CO₂ & MOPA", secondary "Lasertechnologie". Same
 * sort_order "swap with neighbour" and is_active hide-without-delete
 * conventions as feature_grid_items.
 *
 * Seeded with the exact 4 stats currently hardcoded on index.php so the
 * public site keeps rendering identical output the moment this migration
 * runs. Two of the four have no data-en attribute in the current markup
 * (the NL and EN text are identical), so their *_en columns are seeded
 * null on purpose, matching the site's existing NL-fallback convention.
 */
final class CreateStatStripItemsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('stat_strip_items', ['id' => true]);
        $table
            ->addColumn('stat_strip_id', 'integer', ['signed' => false])
            ->addColumn('primary_text_nl', 'string', ['limit' => 100])
            ->addColumn('primary_text_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('secondary_text_nl', 'string', ['limit' => 150])
            ->addColumn('secondary_text_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('stat_strip_id', 'stat_strips', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['stat_strip_id'])
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

        $capabilityBandId = (int) $this->fetchRow(
            "SELECT id FROM stat_strips WHERE page_slug = 'index' AND section_key = 'capability-band'"
        )['id'];

        $items = [
            [
                'stat_strip_id' => $capabilityBandId,
                'primary_text_nl' => 'CO₂ & MOPA',
                'primary_text_en' => null,
                'secondary_text_nl' => 'Lasertechnologie',
                'secondary_text_en' => 'Laser technology',
                'sort_order' => 0,
            ],
            [
                'stat_strip_id' => $capabilityBandId,
                'primary_text_nl' => 'Hout · Metaal',
                'primary_text_en' => 'Wood · Metal',
                'secondary_text_nl' => 'Acryl · glas op aanvraag',
                'secondary_text_en' => 'Acrylic · glass on request',
                'sort_order' => 1,
            ],
            [
                'stat_strip_id' => $capabilityBandId,
                'primary_text_nl' => 'Particulier',
                'primary_text_en' => 'Personal',
                'secondary_text_nl' => '& zakelijk',
                'secondary_text_en' => '& business',
                'sort_order' => 2,
            ],
            [
                'stat_strip_id' => $capabilityBandId,
                'primary_text_nl' => 'Nijmegen',
                'primary_text_en' => null,
                'secondary_text_nl' => 'Werkplaats & ophalen',
                'secondary_text_en' => 'Workshop & pickup',
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
        $this->table('stat_strip_items')->drop()->save();
    }
}

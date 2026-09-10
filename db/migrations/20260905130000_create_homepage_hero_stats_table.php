<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Stats repeater for the Homepage Hero (`.hero__meta`, 3 "strong + span"
 * items to the right of the CTA buttons) — see the previous migration's
 * docblock and App\Service\HomepageHeroContent. Same parent/child +
 * sort_order-swap + is_active hide-without-delete conventions as
 * stat_strip_items, but deliberately its OWN table: this is Homepage-Hero
 * content, not a reuse of stat_strips, and the two must be able to evolve
 * independently (stat_strips has no image/badge/CTA context, this one does).
 *
 * The current Hero layout is visually tuned for a maximum of 3 stats — the
 * admin UI and the create-stat API both enforce that cap (never here as a DB
 * constraint, since that would make a future deliberate design change a
 * migration instead of a code change).
 *
 * Seeded with the exact 3 stats currently hardcoded in index.php's
 * `.hero__meta` so the public site keeps rendering identical output the
 * moment this migration runs. The first stat has no data-en counterpart in
 * the current markup (identical text in both languages), so its *_en column
 * is seeded null on purpose, matching stat_strip_items' equivalent case.
 */
final class CreateHomepageHeroStatsTable extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('homepage_hero_stats', ['id' => true]);
        $table
            ->addColumn('homepage_hero_id', 'integer', ['signed' => false])
            ->addColumn('primary_text_nl', 'string', ['limit' => 100])
            ->addColumn('primary_text_en', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('secondary_text_nl', 'string', ['limit' => 150])
            ->addColumn('secondary_text_en', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('sort_order', 'integer', ['default' => 0])
            ->addColumn('is_active', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('homepage_hero_id', 'homepage_hero', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
            ])
            ->addIndex(['homepage_hero_id'])
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

        $heroId = (int) $this->fetchRow(
            "SELECT id FROM homepage_hero WHERE page_slug = 'index'"
        )['id'];

        $items = [
            [
                'homepage_hero_id' => $heroId,
                'primary_text_nl' => 'CO₂ & MOPA',
                'primary_text_en' => null,
                'secondary_text_nl' => 'lasertechnologie',
                'secondary_text_en' => 'laser technology',
                'sort_order' => 0,
            ],
            [
                'homepage_hero_id' => $heroId,
                'primary_text_nl' => 'Hout · Metaal · Acryl',
                'primary_text_en' => 'Wood · Metal · Acrylic',
                'secondary_text_nl' => 'kernmaterialen',
                'secondary_text_en' => 'core materials',
                'sort_order' => 1,
            ],
            [
                'homepage_hero_id' => $heroId,
                'primary_text_nl' => 'Particulier & Zakelijk',
                'primary_text_en' => 'Personal & Business',
                'secondary_text_nl' => 'voor wie ik werk',
                'secondary_text_en' => 'who I work with',
                'sort_order' => 2,
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
        $this->table('homepage_hero_stats')->drop()->save();
    }
}

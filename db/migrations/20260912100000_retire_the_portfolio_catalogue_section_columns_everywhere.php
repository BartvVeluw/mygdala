<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Makes `portfolio_galleries` the same table on every installation.
 *
 * 20260908290000_turn_the_portfolio_blocks_into_one_reusable_block.php moved
 * the catalogue's section identity (`page_slug`, `section_key`) and its
 * section-level `is_active` onto the item_gallery block, and then dropped the
 * three columns — but only after its fresh-install return, and only once both
 * of that site's galleries existed. So an upgraded installation lost them,
 * and every installation built from zero kept them, unique index and all.
 * Nothing reads them any more: App\Repository\PortfolioGalleryRepository
 * creates and finds the catalogue by id alone.
 *
 * A fresh-install guard may skip one site's content; it may never decide the
 * schema (INSTALL-BOOTSTRAP.md). That migration is left as it is: it has
 * already run on every existing database, fresh ones included, and would
 * never run there again. This one does the schema half for everybody, and
 * nothing where it is already done.
 *
 * No data moves. Wherever there was a block to hold the old visibility flag,
 * the earlier migration already copied it there; a catalogue that never was
 * a page section has nothing in these columns worth keeping.
 *
 * Forward-only and idempotent: each piece is dropped only while it still
 * exists, so a database missing one of them is finished rather than failed.
 * MySQL-compatible: plain ALTER TABLE.
 *
 * Tests\Install\PortfolioCatalogueSchemaTest proves the result.
 */
final class RetireThePortfolioCatalogueSectionColumnsEverywhere extends AbstractMigration
{
    /** What the catalogue carried while it was still a page section. */
    private const RETIRED_COLUMNS = ['page_slug', 'section_key', 'is_active'];

    /** The (page_slug, section_key) unique index; MySQL names it after its first column. */
    private const RETIRED_INDEX = 'page_slug';

    public function up(): void
    {
        if (!$this->hasTable('portfolio_galleries')) {
            return;
        }

        $table = $this->table('portfolio_galleries');

        if ($table->hasIndexByName(self::RETIRED_INDEX)) {
            $table->removeIndexByName(self::RETIRED_INDEX)->save();
        }

        foreach (self::RETIRED_COLUMNS as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column)->save();
            }
        }
    }
}

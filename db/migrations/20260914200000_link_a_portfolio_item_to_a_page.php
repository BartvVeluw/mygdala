<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * An optional link from a portfolio item to the ordinary CMS page that is its
 * project page. MODULES.md, "Portfolio".
 *
 * WHY A LINK AND NOT A URL. A project page used to be the Portfolio's own page:
 * its slug, texts and extra photos lived on this table and on
 * portfolio_item_images, rendered by portfolio-detail.php. A project page is an
 * ordinary `pages` row now, built in the page builder like any other page, and
 * the item only says WHICH page. It says so by id, never by address: renaming
 * the page moves the card along with it, exactly as a menu item that points at
 * a page follows a rename (App\Service\LinkResolver).
 *
 * NULLABLE, and NULL for every existing row. An item without a page is a
 * complete item whose card is simply not a link.
 *
 * ON DELETE SET NULL. Deleting a page belongs to Pages, and Pages does not know
 * the Portfolio exists (App\Service\PageService counts menu and footer links,
 * nothing else). So the database must neither refuse that delete (RESTRICT)
 * nor take the portfolio item down with the page (CASCADE): the item stays,
 * without a link. ON UPDATE CASCADE, like every other key that points at
 * pages.id.
 *
 * INT UNSIGNED, because pages.id is: MySQL refuses a signed column that
 * references an unsigned one (db/migrations/CLAUDE.md).
 *
 * NOTHING IS REMOVED OR MOVED. has_detail_page, slug, intro_*, description_*
 * and portfolio_item_images keep every value; portfolio-detail.php still reads
 * them for an old project address that has no published page linked yet.
 *
 * Schema only, so no fresh-install guard: a new installation and an upgraded
 * one end with the same column (INSTALL-BOOTSTRAP.md). Every step checks
 * before it acts, so a run that stopped halfway, or a second run, still ends
 * with exactly one column, one index and one key.
 */
final class LinkAPortfolioItemToAPage extends AbstractMigration
{
    private const TABLE = 'portfolio_gallery_items';

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE) || !$this->hasTable('pages')) {
            return;
        }

        if (!$this->table(self::TABLE)->hasColumn('page_id')) {
            $this->table(self::TABLE)
                ->addColumn('page_id', 'integer', [
                    'signed' => false,
                    'null' => true,
                    'default' => null,
                    'after' => 'portfolio_gallery_id',
                    'comment' => 'The ordinary CMS page that is this item\'s project page; NULL = no link',
                ])
                ->update();
        }

        if (!$this->table(self::TABLE)->hasIndex(['page_id'])) {
            $this->table(self::TABLE)->addIndex(['page_id'])->update();
        }

        if (!$this->table(self::TABLE)->hasForeignKey('page_id')) {
            $this->table(self::TABLE)
                ->addForeignKey('page_id', 'pages', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                ])
                ->update();
        }
    }

    /**
     * Takes the link off again. Going up touched no other column, so there is
     * nothing else to put back.
     */
    public function down(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            return;
        }

        if ($this->table(self::TABLE)->hasForeignKey('page_id')) {
            $this->table(self::TABLE)->dropForeignKey('page_id')->update();
        }

        if ($this->table(self::TABLE)->hasIndex(['page_id'])) {
            $this->table(self::TABLE)->removeIndex(['page_id'])->update();
        }

        if ($this->table(self::TABLE)->hasColumn('page_id')) {
            $this->table(self::TABLE)->removeColumn('page_id')->update();
        }
    }
}

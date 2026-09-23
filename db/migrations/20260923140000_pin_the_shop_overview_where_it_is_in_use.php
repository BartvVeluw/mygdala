<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Stores which product overview an EXISTING installation already has, so
 * App\Service\ShopOverview can start with "no overview" on a new one without
 * taking a live storefront off the air (MODULES.md, "Shop").
 *
 * Until now /shop.php always answered: with the CMS page whose content_key is
 * `shop` when the installation had one, and otherwise with the module's own
 * automatic listing of every product. The Shop no longer implies a public
 * listing — an owner chooses a page, or none — and a new installation starts
 * with none. An existing site never chose, because it never could, so without
 * this row its storefront would disappear the moment the code is deployed.
 *
 * WHAT IS STORED (site_settings.shop_overview):
 *
 *   - the id of the `shop` page, when there is one: that page stays the
 *     overview and keeps its address /shop.php;
 *   - otherwise `builtin`: the automatic listing /shop.php showed, kept as it
 *     was until the owner picks a page or none under Shop-instellingen.
 *
 * WHO GETS THE PIN: the same rule as
 * 20260921100000_pin_the_multilingual_module_where_it_is_in_use.php — every
 * existing installation, and a fresh one whose Setup Wizard has already
 * finished. A database being built from zero right now stores nothing.
 *
 * Data only, no schema. INSERT IGNORE against the unique setting_key, so a
 * choice that already exists is kept. Idempotent.
 */
final class PinTheShopOverviewWhereItIsInUse extends AbstractMigration
{
    private const SETTING_KEY = 'shop_overview';

    public function up(): void
    {
        if (!$this->hasTable('site_settings') || !$this->hasTable('pages')) {
            return;
        }

        if (InstallState::isFreshInstall($this) && !$this->setupIsComplete()) {
            return;
        }

        $page = $this->fetchRow("SELECT id FROM pages WHERE content_key = 'shop' LIMIT 1");
        $value = $page !== false ? (string) (int) $page['id'] : 'builtin';
        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [self::SETTING_KEY, $value, $now, $now]
        );
    }

    /** Nothing to undo: the row is an ordinary setting the owner can change. */
    public function down(): void
    {
    }

    /** Whether the Setup Wizard has finished, read the way 20260921100000 reads it. */
    private function setupIsComplete(): bool
    {
        if (!$this->hasTable(InstallState::TABLE)) {
            return false;
        }

        return $this->fetchRow(
            'SELECT 1 FROM ' . InstallState::TABLE . " WHERE state_key = 'setup_completed_at' AND state_value <> ''"
        ) !== false;
    }
}

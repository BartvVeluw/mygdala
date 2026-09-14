<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Stores "Portfolio on" for every installation that has been running the
 * Portfolio, so App\Module\PortfolioModule can start OFF on a new installation
 * without taking a single existing portfolio off the air.
 *
 * The Portfolio used to be Core: always present, never switchable. It is an
 * optional module now, and a new site only gets it when somebody asks for it
 * (PortfolioModule::enabledByDefault()). An existing site never asked, because
 * it never could, so it has no MODULE_PORTFOLIO_ENABLED and no stored
 * preference either — without this row it would land on the new default the
 * moment the code is deployed. Same shape and reasoning as
 * 20260913100000_pin_the_order_number_prefix_before_the_generic_default.php.
 *
 * WHO GETS THE PIN. Two kinds of installation have been running it:
 *
 *   - every existing installation, which InstallState reports as legacy: its
 *     Portfolio page, menu link and project pages predate the module;
 *   - a FRESH installation that already holds portfolio content (an item or a
 *     category): built from zero before this migration, it has been using the
 *     Portfolio just the same.
 *
 * A database being built from zero right now holds neither, so it stores
 * nothing and the module's own default applies. The Setup Wizard then offers
 * the Portfolio like every other module.
 *
 * The row is an ordinary stored preference (App\Module\ModuleSettings): the
 * environment still overrules it, and an owner can still change it. The key is
 * written out here rather than taken from ModuleSettings::settingKey(), because
 * a migration must keep running unchanged whatever that class becomes.
 *
 * Data only, no schema. Forward-only and non-destructive: INSERT IGNORE
 * against the unique index on setting_key, so a preference that already exists
 * is kept. Idempotent.
 */
final class PinThePortfolioModuleWhereItIsInUse extends AbstractMigration
{
    private const SETTING_KEY = 'module_portfolio_enabled';

    public function up(): void
    {
        if (!$this->hasTable('module_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this) && !$this->holdsPortfolioContent()) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        $this->execute(
            'INSERT IGNORE INTO module_settings (setting_key, setting_value, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [self::SETTING_KEY, '1', $now, $now]
        );
    }

    /**
     * Nothing to undo: the row this wrote is an ordinary stored preference,
     * and deleting it would take the portfolio off the air again.
     */
    public function down(): void
    {
    }

    /** Whether anybody has already added a portfolio item or category here. */
    private function holdsPortfolioContent(): bool
    {
        foreach (['portfolio_gallery_items', 'portfolio_categories'] as $table) {
            if ($this->hasTable($table) && $this->fetchRow('SELECT 1 FROM ' . $table . ' LIMIT 1') !== false) {
                return true;
            }
        }

        return false;
    }
}

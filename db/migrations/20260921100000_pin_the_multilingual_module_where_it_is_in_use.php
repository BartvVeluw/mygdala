<?php

declare(strict_types=1);

use App\Install\InstallState;
use Phinx\Migration\AbstractMigration;

/**
 * Stores "Meertaligheid on" for every installation that has been publishing
 * more than one language, so App\Module\MultilingualModule can start OFF on a
 * new installation without taking a single existing translation off the air.
 *
 * Every installation before Multilingual 2.0 phase 7 published Dutch and
 * English — the V1 language switch, and since phase 6 an address per
 * language. The module that now decides that is off by default
 * (MultilingualModule::enabledByDefault(), docs/multilingual/ARCHITECTURE.md:
 * a new site publishes its default language until somebody asks for more). An
 * existing site never asked, because it never could, so it has no
 * MODULE_MULTILINGUAL_ENABLED and no stored preference either — without this
 * row its English pages would disappear the moment the code is deployed. The
 * same shape and reasoning as
 * 20260914170000_pin_the_portfolio_module_where_it_is_in_use.php.
 *
 * WHO GETS THE PIN:
 *
 *   - every existing installation, which InstallState reports as legacy;
 *   - a FRESH installation whose Setup Wizard has already finished: built
 *     from zero before this migration, it has been a running site publishing
 *     Dutch and English just the same.
 *
 * A database being built from zero right now has not finished its wizard, so
 * it stores nothing and the module's own default applies; the wizard then
 * offers Meertaligheid like every other module. Whether it holds words in
 * another language says nothing here: the seed migrations of every fresh
 * installation write English words of their own.
 *
 * Nothing about the languages themselves changes: their rows, their own
 * active flags and every translation stay exactly as they are. The row is an
 * ordinary stored preference (App\Module\ModuleSettings): the environment
 * still overrules it, and an owner can change it under Settings > Talen. The
 * key is written out here rather than taken from ModuleSettings::settingKey(),
 * because a migration must keep running unchanged whatever that class becomes.
 *
 * Data only, no schema. Forward-only and non-destructive: INSERT IGNORE
 * against the unique index on setting_key, so a preference that already exists
 * is kept. Idempotent.
 */
final class PinTheMultilingualModuleWhereItIsInUse extends AbstractMigration
{
    private const SETTING_KEY = 'module_multilingual_enabled';

    public function up(): void
    {
        if (!$this->hasTable('module_settings')) {
            return;
        }

        if (InstallState::isFreshInstall($this) && !$this->setupIsComplete()) {
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
     * and deleting it would take every other language off the air again.
     */
    public function down(): void
    {
    }

    /**
     * Whether the Setup Wizard has finished on this installation: the row
     * App\Install\SetupState writes into InstallState's own table (the one
     * table a from-zero installation creates outside a migration's schema
     * calls), read here directly.
     */
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

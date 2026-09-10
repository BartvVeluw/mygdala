<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Key/value store for which first-party modules this installation wants
 * enabled, when the environment does not say.
 *
 * Why it exists. Until the Setup Wizard, a module was switched on or off
 * with MODULE_<KEY>_ENABLED in .env and nowhere else (MODULES.md), which is
 * still the right answer for a deployment that HAS an .env it can edit. It
 * is not an answer the wizard can offer: someone setting up a brand-new site
 * through the CMS cannot reach the server's environment file, and asking
 * them to is exactly the manual database/.env editing this step removes.
 *
 * SO THE ENVIRONMENT STILL WINS. App\Module\ModuleConfig reads the variable
 * first and only falls back to a row here when the variable is absent or
 * empty. That keeps every existing deployment behaving identically — the
 * php_cms test container's MODULE_SHOP_ENABLED=false still decides, and a
 * hosting account that pins its modules in .env still cannot have them
 * changed by anyone who is merely signed into the CMS. There is one
 * precedence chain, written down in one place, and this table is its last
 * step before the "enabled" default.
 *
 * DELIBERATELY SEEDED EMPTY, exactly like `theme_settings`
 * (20260909200000): a missing row means "the default", so this migration
 * cannot change what any existing installation runs, and a fresh install
 * behaves the same before anybody has opened the wizard.
 *
 * Same shape as `site_settings` and `theme_settings` and a SEPARATE table
 * for the same reason: this is deployment configuration, not site identity
 * and not appearance. Nothing that resets one may reach the others.
 */
final class CreateModuleSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('module_settings')) {
            return;
        }

        $this->table('module_settings', ['id' => true])
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('module_settings')) {
            $this->table('module_settings')->drop()->save();
        }
    }
}

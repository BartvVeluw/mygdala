<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Key/value store for how the CMS ITSELF looks and behaves — the admin panel,
 * not the website it manages.
 *
 * Why a fourth settings table. This project already keeps three apart on
 * purpose, and the reason is always the same: nothing that resets one may
 * reach the others.
 *
 *   site_settings    who the site IS (name, logo, address, invoice texts)
 *   theme_settings   what the PUBLIC site looks like (colours, fonts, shape)
 *   module_settings  which modules this deployment runs
 *   admin_settings   what the CMS looks like to the people who work in it
 *
 * The dashboard theme is emphatically NOT public appearance: an owner who
 * picks a dark CMS has said nothing about their website, and "standaard-
 * vormgeving herstellen" on the theme screen must not be able to change the
 * panel they are standing in. A shared table would make that a naming
 * convention; a separate one makes it a fact.
 *
 * DELIBERATELY SEEDED EMPTY, exactly like `theme_settings` (20260909200000)
 * and `module_settings` (20260910100000): a missing row means "the code
 * default", so this migration cannot change what any existing installation
 * looks like, and a fresh install is already coherent before anybody has
 * opened the settings screen. Both therefore start on the Default theme,
 * which is the current admin appearance unchanged.
 */
final class CreateAdminSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('admin_settings')) {
            return;
        }

        $this->table('admin_settings', ['id' => true])
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('admin_settings')) {
            $this->table('admin_settings')->drop()->save();
        }
    }
}

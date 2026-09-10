<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Key/value store for the VISUAL theme: the five colours, the font pairing
 * and the button shape an administrator picks under Vormgeving & Branding.
 *
 * Same shape as `site_settings` and for the same reason — a new theme
 * setting must never need a schema migration — but deliberately a SEPARATE
 * table, because the two answer different questions. `site_settings` is who
 * the site is (name, logo, address, KVK, invoice and e-mail copy);
 * `theme_settings` is what it looks like. Resetting the theme to its
 * defaults must never be able to touch a company address, and a table
 * boundary is the cheapest way to guarantee that.
 *
 * DELIBERATELY SEEDED EMPTY. App\Service\Theme\ThemeSettings holds the
 * defaults in code, and "no row" means "the default" — so this migration
 * cannot change how any existing site looks, a fresh install renders
 * correctly before anyone has saved anything, and the public site survives
 * the table being unreachable. The admin screen only ever writes rows for
 * what somebody actually chose.
 */
final class CreateThemeSettingsTable extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('theme_settings')) {
            return;
        }

        $this->table('theme_settings', ['id' => true])
            ->addColumn('setting_key', 'string', ['limit' => 100])
            ->addColumn('setting_value', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['setting_key'], ['unique' => true])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('theme_settings')) {
            $this->table('theme_settings')->drop()->save();
        }
    }
}

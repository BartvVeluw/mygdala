<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Branding & Design 2.0: the website's colours become NAMED PALETTES, of
 * which exactly one is the active one (THEMING.md, "Kleurenpaletten").
 *
 *   color_palettes   one row per palette: a unique name and the five colour
 *                    roles of the site theme, in the one canonical form
 *                    #RRGGBB. `is_active` is 1 for the active palette and
 *                    NULL for every other row, never 0, under a UNIQUE index:
 *                    MySQL allows any number of NULLs in a unique index but
 *                    only one 1, so "at most one active palette" holds in the
 *                    database itself (the pattern of site_languages.is_default,
 *                    20260917120000). "At least one" is the CMS's rule: the
 *                    active palette and the last palette cannot be deleted.
 *
 * THE CURRENT LOOK IS KEPT. The first palette, "Standaard", gets the colours
 * the website shows today: each stored theme_settings colour row, normalised
 * exactly like App\Service\Theme\ThemeSettings does (#RGB or #RRGGBB, with
 * or without the hash, any case), and the shipped default (the value core.css
 * declares) for a colour that was never chosen or no longer validates. It is
 * the active palette, so every page renders as before — a palette equal to
 * the shipped default still emits no <style id="site-theme"> at all.
 *
 * ONE SOURCE OF TRUTH. Once "Standaard" holds them, the five colour rows are
 * removed from theme_settings; the font pairing and the button shape stay
 * there, because they are not part of a palette. Only after the palette row
 * exists, so a run that stops half-way still has the colours somewhere, and a
 * second run picks up where it stopped.
 *
 * Page themes (page_themes, module page_themes) are not touched: a page theme
 * keeps its own colours whichever palette is active. Idempotent: every step
 * checks first; the seed runs only on an empty table. Fresh install and
 * upgrade end on the same schema; a fresh install seeds the shipped default.
 */
final class CreateColorPalettes extends AbstractMigration
{
    private const TABLE = 'color_palettes';

    /** The shipped default, exactly as core.css and ThemeSettings::DEFAULTS. */
    private const DEFAULT_COLORS = [
        'primary_color' => '#C9A063',
        'on_primary_color' => '#1B140D',
        'background_color' => '#120D09',
        'surface_color' => '#1C150E',
        'text_color' => '#F5EFE4',
    ];

    public function up(): void
    {
        if (!$this->hasTable(self::TABLE)) {
            $this->table(self::TABLE, ['id' => true])
                ->addColumn('name', 'string', ['limit' => 80, 'null' => false])
                ->addColumn('primary_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('on_primary_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('background_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('surface_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('text_color', 'char', ['limit' => 7, 'null' => false])
                ->addColumn('is_active', 'boolean', [
                    'null' => true,
                    'default' => null,
                    'comment' => '1 = the active website palette, NULL = not; never 0 (unique index)',
                ])
                ->addColumn('created_at', 'datetime', ['null' => true, 'default' => null])
                ->addColumn('updated_at', 'datetime', ['null' => true, 'default' => null])
                ->addIndex(['name'], ['unique' => true, 'name' => 'uq_color_palettes_name'])
                ->addIndex(['is_active'], ['unique' => true, 'name' => 'uq_color_palettes_active'])
                ->create();
        }

        $count = $this->fetchRow('SELECT COUNT(*) AS c FROM ' . self::TABLE);
        if ((int) ($count['c'] ?? 0) === 0) {
            $colors = $this->currentColors();
            $this->execute(
                'INSERT INTO ' . self::TABLE
                . ' (name, primary_color, on_primary_color, background_color, surface_color, text_color, is_active, created_at, updated_at)'
                . ' VALUES (' . implode(', ', array_map(
                    fn (string $value): string => $this->getAdapter()->getConnection()->quote($value),
                    ['Standaard', ...array_values($colors)]
                )) . ', 1, NOW(), NOW())'
            );
        }

        // Only now that a palette holds the colours: remove the second copy.
        if ($this->hasTable('theme_settings')) {
            $this->execute(
                "DELETE FROM theme_settings WHERE setting_key IN ('"
                . implode("', '", array_keys(self::DEFAULT_COLORS)) . "')"
            );
        }
    }

    /** Forward-only (db/migrations/CLAUDE.md). */
    public function down(): void
    {
    }

    /**
     * The five colours the website shows right now, in DEFAULT_COLORS order.
     *
     * @return array<string, string>
     */
    private function currentColors(): array
    {
        $colors = self::DEFAULT_COLORS;

        if (!$this->hasTable('theme_settings')) {
            return $colors;
        }

        $rows = $this->fetchAll(
            "SELECT setting_key, setting_value FROM theme_settings WHERE setting_key IN ('"
            . implode("', '", array_keys(self::DEFAULT_COLORS)) . "')"
        );

        foreach ($rows as $row) {
            $normalised = self::normalise((string) ($row['setting_value'] ?? ''));
            if ($normalised !== null) {
                $colors[(string) $row['setting_key']] = $normalised;
            }
        }

        return $colors;
    }

    /** App\Service\Theme\ThemeColor::normalise(), frozen in this migration. */
    private static function normalise(string $value): ?string
    {
        $value = ltrim(trim($value), '#');

        if (preg_match('/^[0-9A-Fa-f]{3}$/', $value) === 1) {
            $value = $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
        }

        if (preg_match('/^[0-9A-Fa-f]{6}$/', $value) !== 1) {
            return null;
        }

        return '#' . strtoupper($value);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\ButtonStyleRepository;
use App\Service\Theme\ButtonStyles;
use App\Service\Theme\ThemeSettings;

/**
 * Test support, never part of the application: the button style library
 * (button_styles, button_style_defaults) as a test found it, and put back
 * exactly so afterwards.
 *
 * The two defaults draw every .btn of the website, so a test that changed
 * one and forgot would change how every later test's pages render.
 * snapshot() in setUp(), restore() in tearDown(): every choice of a style
 * the snapshot did not have is cleared (the foreign keys would refuse the
 * delete otherwise), the defaults are put back, every style the snapshot had
 * gets its own row back and every other style is deleted.
 */
final class ButtonStyleFixture
{
    /** @return array{styles: list<array<string, mixed>>, defaults: list<array<string, mixed>>} */
    public static function snapshot(): array
    {
        $db = Database::connection();

        return [
            'styles' => $db->query('SELECT * FROM button_styles ORDER BY id')->fetchAll(),
            'defaults' => $db->query('SELECT * FROM button_style_defaults ORDER BY role')->fetchAll(),
        ];
    }

    /** @param array{styles: list<array<string, mixed>>, defaults: list<array<string, mixed>>} $snapshot */
    public static function restore(array $snapshot): void
    {
        $db = Database::connection();
        $keep = array_map(static fn (array $row): int => (int) $row['id'], $snapshot['styles']);
        $keepList = $keep === [] ? '0' : implode(', ', $keep);

        foreach (ButtonStyleRepository::slots() as $table => $columns) {
            foreach ($columns as $column) {
                $db->exec('UPDATE `' . $table . '` SET `' . $column . '` = NULL WHERE `' . $column . '` NOT IN (' . $keepList . ')');
            }
        }

        $db->exec('DELETE FROM button_style_defaults');
        foreach ($snapshot['styles'] as $row) {
            $sets = [];
            foreach (array_keys($row) as $column) {
                if ($column !== 'id') {
                    $sets[] = '`' . $column . '` = ?';
                }
            }
            $values = array_values(array_diff_key($row, ['id' => true]));
            $values[] = $row['id'];
            $db->prepare('UPDATE button_styles SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        }
        $db->exec('DELETE FROM button_styles WHERE id NOT IN (' . $keepList . ')');

        foreach ($snapshot['defaults'] as $row) {
            $db->prepare('INSERT INTO button_style_defaults (role, button_style_id, updated_at) VALUES (?, ?, ?)')
                ->execute([$row['role'], $row['button_style_id'], $row['updated_at']]);
        }

        ThemeSettings::clearCache();
        ButtonStyles::clearCache();
    }

    /** The id of the style with this name. */
    public static function idNamed(string $name): int
    {
        $stmt = Database::connection()->prepare('SELECT id FROM button_styles WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * A style's complete set of values for a form or ButtonStyles::create():
     * the seeded "Primair" with these changes.
     *
     * @param array<string, string|int|null> $changes
     * @return array<string, string|int|null>
     */
    public static function values(string $name, array $changes = []): array
    {
        return $changes + [
            'name' => $name, 'appearance' => 'filled', 'shape' => 'pill', 'size' => 'normal',
            'fill_color' => 'primary', 'fill_gradient' => 1, 'text_color' => 'on_primary',
            'border_width' => 'none', 'border_color' => 'primary', 'shadow' => 'none',
            'font_weight' => 'bold', 'font_role' => 'body', 'uppercase' => 0, 'underline' => 0,
            'icon' => 'none', 'icon_position' => 'after', 'icon_gap' => 'normal', 'icon_motion' => 1,
            'hover_effect' => 'glow', 'hover_fill_color' => null, 'hover_text_color' => null, 'hover_border_color' => null,
        ];
    }

    /**
     * The same as a posted form: checkboxes are present or absent, a hover
     * colour left unchanged is ''.
     *
     * @param array<string, string|int|null> $changes
     * @return array<string, string>
     */
    public static function form(string $name, array $changes = []): array
    {
        $form = [];
        foreach (self::values($name, $changes) as $field => $value) {
            if (in_array($field, ['fill_gradient', 'uppercase', 'underline', 'icon_motion'], true)) {
                if ((int) $value === 1) {
                    $form[$field] = '1';
                }
                continue;
            }
            $form[$field] = (string) ($value ?? '');
        }

        return $form;
    }
}

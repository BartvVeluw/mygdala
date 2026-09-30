<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Service\Theme\ColorPaletteService;

/**
 * Test support, never part of the application: the website's colour palettes
 * (color_palettes) as a test found them, and put back exactly so afterwards.
 *
 * The active palette is the website's colours, so a test that changed it and
 * forgot would change how every later test's pages render. snapshot() in
 * setUp(), restore() in tearDown(): the table is emptied and its rows put
 * back with their own ids, active flag and timestamps.
 */
final class ColorPaletteFixture
{
    /** A readable dark palette that differs from the shipped default in every colour. */
    public const COLORS = [
        'primary_color' => '#FF7518',
        'on_primary_color' => '#111111',
        'background_color' => '#1A0F1F',
        'surface_color' => '#2A1A30',
        'text_color' => '#F7F1E8',
    ];

    /** @return list<array<string, mixed>> */
    public static function snapshot(): array
    {
        return Database::connection()->query('SELECT * FROM color_palettes ORDER BY id')->fetchAll();
    }

    /** @param list<array<string, mixed>> $rows */
    public static function restore(array $rows): void
    {
        $db = Database::connection();
        $db->exec('DELETE FROM color_palettes');

        foreach ($rows as $row) {
            $columns = array_keys($row);
            $db->prepare(
                'INSERT INTO color_palettes (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')'
            )->execute(array_values($row));
        }

        ColorPaletteService::clearCache();
    }

    /**
     * Leaves exactly one palette, active, with these colours (the shipped
     * default when none are given): a known starting point.
     *
     * @param array<string, string> $colors
     */
    public static function only(array $colors = []): int
    {
        $db = Database::connection();
        $db->exec('DELETE FROM color_palettes');

        $values = $colors + [
            'primary_color' => '#C9A063',
            'on_primary_color' => '#1B140D',
            'background_color' => '#120D09',
            'surface_color' => '#1C150E',
            'text_color' => '#F5EFE4',
        ];

        $db->prepare(
            'INSERT INTO color_palettes (name, primary_color, on_primary_color, background_color, surface_color, text_color, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())'
        )->execute([
            'Standaard',
            $values['primary_color'],
            $values['on_primary_color'],
            $values['background_color'],
            $values['surface_color'],
            $values['text_color'],
        ]);
        $id = (int) $db->lastInsertId();

        ColorPaletteService::clearCache();

        return $id;
    }
}

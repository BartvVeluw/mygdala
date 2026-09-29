<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\PageThemeRepository;
use App\Service\PageThemes\PageThemeService;

/**
 * Test support, never part of the application: page themes for a test, and
 * their removal. Every theme a test makes has a name starting with PREFIX, so
 * removeAll() takes exactly those — first off every page that uses one (the
 * foreign key is RESTRICT), then the rows.
 */
final class PageThemeFixture
{
    public const PREFIX = 'ZZ Test ';

    /** A readable dark palette that differs from the site theme in every colour. */
    public const COLORS = [
        'primary_color' => '#FF7518',
        'on_primary_color' => '#111111',
        'background_color' => '#1A0F1F',
        'surface_color' => '#2A1A30',
        'text_color' => '#F7F1E8',
    ];

    /**
     * @param array<string, string> $overrides columns to store instead of the defaults
     * @return int the new theme's id
     */
    public static function create(string $name, array $overrides = []): int
    {
        $repository = new PageThemeRepository();
        $name = self::PREFIX . $name;

        $id = $repository->create($overrides + [
            'name' => $name,
            'slug' => PageThemeService::uniqueSlug($name, null, $repository),
            'font_pairing' => 'playfair-source-sans',
        ] + self::COLORS);

        PageThemeService::clearCache();

        return $id;
    }

    /** Gives a page a theme, or the site theme again, straight in the database. */
    public static function assign(int $pageId, ?int $themeId): void
    {
        $stmt = Database::connection()->prepare('UPDATE pages SET page_theme_id = ? WHERE id = ?');
        $stmt->execute([$themeId, $pageId]);
    }

    public static function removeAll(): void
    {
        $db = Database::connection();
        $like = self::PREFIX . '%';

        $db->prepare('UPDATE pages SET page_theme_id = NULL WHERE page_theme_id IN (SELECT id FROM (SELECT id FROM page_themes WHERE name LIKE ?) AS t)')
            ->execute([$like]);
        $db->prepare('DELETE FROM page_themes WHERE name LIKE ?')->execute([$like]);

        PageThemeService::clearCache();
    }
}

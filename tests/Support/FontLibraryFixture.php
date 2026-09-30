<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontStorage;

/**
 * Font Library rows for the tests, and their removal. Every family a test
 * makes has a name starting with PREFIX, so removeAll() can never touch a
 * family somebody made in the CMS of the same database. The website's font
 * roles are snapshotted and put back as they were.
 */
final class FontLibraryFixture
{
    public const PREFIX = 'ZZ Font ';

    /**
     * A family straight in the database, with one row per variant key
     * ("400", "700-italic") and, when a storage is given, a real file for
     * each. Returns the family id.
     *
     * @param list<string> $variants
     */
    public static function create(string $name, array $variants = ['400'], string $category = 'sans', ?FontStorage $storage = null): int
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO font_families (name, category, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')
            ->execute([self::PREFIX . $name, $category]);
        $id = (int) $db->lastInsertId();

        foreach ($variants as $key) {
            [$weight, $italic] = array_pad(explode('-', $key), 2, '');
            $fileName = bin2hex(random_bytes(16)) . '.woff2';
            if ($storage !== null) {
                $path = FontFileFixture::file(FontFileFixture::woff2());
                $fileName = $storage->store($path, 'woff2', false);
                @unlink($path);
            }

            $db->prepare(
                'INSERT INTO font_files (font_family_id, weight, style, format, file_name, original_filename, byte_size, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$id, (int) $weight, $italic === 'italic' ? 'italic' : 'normal', 'woff2', $fileName, $name . '-' . $key . '.woff2', 1234]);
        }

        FontLibrary::clearCache();

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public static function rolesSnapshot(): array
    {
        return Database::connection()->query('SELECT * FROM theme_font_roles ORDER BY role')->fetchAll();
    }

    /**
     * Removes every test family (and the roles and page-theme choices that
     * point at one), then puts the website's roles back.
     *
     * @param list<array<string, mixed>> $roles from rolesSnapshot()
     */
    public static function removeAll(array $roles = [], ?FontStorage $storage = null): void
    {
        $db = Database::connection();
        $like = self::PREFIX . '%';
        $ids = 'SELECT id FROM (SELECT id FROM font_families WHERE name LIKE ?) AS f';

        $db->exec('DELETE FROM theme_font_roles');
        $db->prepare('UPDATE page_themes SET heading_font_family_id = NULL WHERE heading_font_family_id IN (' . $ids . ')')->execute([$like]);
        $db->prepare('UPDATE page_themes SET body_font_family_id = NULL WHERE body_font_family_id IN (' . $ids . ')')->execute([$like]);

        $files = $db->prepare('SELECT v.file_name FROM font_files v JOIN font_families f ON f.id = v.font_family_id WHERE f.name LIKE ?');
        $files->execute([$like]);
        foreach ($files->fetchAll(\PDO::FETCH_COLUMN) as $fileName) {
            ($storage ?? new FontStorage())->delete((string) $fileName);
        }

        $db->prepare('DELETE FROM font_families WHERE name LIKE ?')->execute([$like]);

        foreach ($roles as $row) {
            $db->prepare('INSERT INTO theme_font_roles (role, font_family_id, updated_at) VALUES (?, ?, ?)')
                ->execute([$row['role'], $row['font_family_id'], $row['updated_at']]);
        }

        FontLibrary::clearCache();
    }
}

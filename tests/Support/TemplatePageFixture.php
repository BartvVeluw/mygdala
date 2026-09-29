<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database;
use App\Repository\PageRepository;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;

/**
 * A page served by its own PHP template at the project root, for a test that
 * requests that template over HTTP.
 *
 * contact.php, diensten.php and over-mij.php are code every installation
 * ships, but each renders only when the `pages` table holds a published row
 * with its content key. The installation this code grew out of had those
 * rows; a fresh one, and a test database copied from one, does not, and the
 * file then answers 404 exactly as it should. A test that is about routing,
 * a head or a module boundary rather than about that page therefore brings
 * the row itself, instead of relying on what the test database happens to
 * contain.
 *
 * The row is shaped like the ones the pages migration made for such a
 * template (is_system, route_path, slug = content key). A row that already
 * exists is left alone and never removed: only what ensure() created goes
 * again in remove().
 */
final class TemplatePageFixture
{
    /** content key => [route path, title] of every template this fixture knows */
    private const TEMPLATES = [
        'contact' => ['/contact.php', 'Contact'],
        'diensten' => ['/diensten.php', 'Diensten'],
        'over-mij' => ['/over-mij.php', 'Over mij'],
    ];

    /**
     * Makes sure the template's page exists and is published.
     *
     * @return int|null the id of the row this call created, or null when the
     *                  database already had one
     */
    public static function ensure(string $contentKey): ?int
    {
        if (!array_key_exists($contentKey, self::TEMPLATES)) {
            throw new \InvalidArgumentException('No template page "' . $contentKey . '" in TemplatePageFixture.');
        }

        $existing = (new PageRepository())->findByContentKey($contentKey);
        if ($existing !== null) {
            if (($existing['status'] ?? '') !== 'published') {
                throw new \RuntimeException(
                    'The test database has an unpublished "' . $contentKey . '" page; this fixture does not change it.'
                );
            }

            return null;
        }

        [$routePath, $title] = self::TEMPLATES[$contentKey];

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO pages
                (content_key, slug, status, is_system, route_path, sort_order, created_at, updated_at)
             VALUES (:content_key, :slug, \'published\', 1, :route_path,
                     (SELECT next_order FROM (SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_order FROM pages) AS s),
                     NOW(), NOW())'
        )->execute([
            'content_key' => $contentKey,
            'slug' => $contentKey,
            'route_path' => $routePath,
        ]);
        $id = (int) $db->lastInsertId();

        PageLocalization::save($id, PageLocalization::defaultLanguage(), [PageTranslation::TITLE => $title]);
        PageContent::clearCache();

        return $id;
    }

    /**
     * Ensures several templates at once.
     *
     * @param list<string> $contentKeys
     *
     * @return list<int> the ids this call created
     */
    public static function ensureAll(array $contentKeys): array
    {
        $created = [];
        foreach ($contentKeys as $contentKey) {
            $id = self::ensure($contentKey);
            if ($id !== null) {
                $created[] = $id;
            }
        }

        return $created;
    }

    /**
     * Removes the rows ensure() created; its text goes with it
     * (page_translations cascades).
     *
     * @param list<int|null> $ids
     */
    public static function remove(array $ids): void
    {
        $pages = new PageRepository();
        foreach ($ids as $id) {
            if ($id !== null) {
                $pages->delete($id);
            }
        }
        PageContent::clearCache();
    }
}

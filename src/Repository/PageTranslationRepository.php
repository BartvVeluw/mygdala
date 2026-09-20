<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All `page_translations` SQL (db/migrations/20260917140000).
 *
 * Deliberately dumb, like App\Repository\PageRepository: which language a
 * reader gets, what an empty field falls back to and whether a language may
 * be written at all is App\Service\PageLocalization's business, and nothing
 * else should call this class.
 *
 * The schema holds the two rules no caller can walk past: one row per page
 * per language (UNIQUE), and only a registered language code (a foreign key
 * on site_languages.code).
 */
final class PageTranslationRepository extends Repository
{
    /**
     * Every language one page has text in.
     *
     * @return array<string, array<string, mixed>> keyed by language_code
     */
    public function findForPage(int $pageId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM page_translations WHERE page_id = :page_id ORDER BY id ASC');
        $stmt->execute(['page_id' => $pageId]);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(string) $row['language_code']] = $row;
        }

        return $rows;
    }

    /**
     * findForPage() for many pages in one query, for lists that name every
     * page they show.
     *
     * @param list<int> $pageIds
     * @return array<int, array<string, array<string, mixed>>> page id => language_code => row
     */
    public function findForPages(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(
            array_map('intval', $pageIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM page_translations WHERE page_id IN ({$placeholders}) ORDER BY page_id ASC, id ASC"
        );
        $stmt->execute($pageIds);

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['page_id']][(string) $row['language_code']] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $pageId, string $languageCode): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM page_translations WHERE page_id = :page_id AND language_code = :language_code LIMIT 1'
        );
        $stmt->execute(['page_id' => $pageId, 'language_code' => $languageCode]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function exists(int $pageId, string $languageCode): bool
    {
        return $this->find($pageId, $languageCode) !== null;
    }

    /**
     * Writes one language's text for one page: a new row, or the existing
     * row's three fields replaced.
     *
     * One statement, so a save never reads first and cannot race another
     * save into a duplicate. Takes part in a transaction that is already
     * open on the connection, as App\Service\PageTemplates\PageTemplateInstaller
     * needs.
     */
    public function save(
        int $pageId,
        string $languageCode,
        ?string $title,
        ?string $metaTitle,
        ?string $metaDescription,
        ?string $slug = null
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO page_translations
                (page_id, language_code, slug, title, meta_title, meta_description, created_at, updated_at)
             VALUES
                (:page_id, :language_code, :slug, :title, :meta_title, :meta_description, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                slug = :slug_update,
                title = :title_update,
                meta_title = :meta_title_update,
                meta_description = :meta_description_update,
                updated_at = NOW()'
        );
        // Every parameter named once: native prepares (App\Database) do not
        // allow a name to be used twice in one statement.
        $stmt->execute([
            'page_id' => $pageId,
            'language_code' => $languageCode,
            'title' => $title,
            'meta_title' => $metaTitle,
            'meta_description' => $metaDescription,
            'slug' => $slug,
            'slug_update' => $slug,
            'title_update' => $title,
            'meta_title_update' => $metaTitle,
            'meta_description_update' => $metaDescription,
        ]);
    }

    /**
     * The published page one localized address belongs to, in one language.
     *
     * A `pages` row, reached through this table because the ADDRESS is what
     * is being looked up and this table owns addresses. /en/about-us asks for
     * the page whose ENGLISH address is "about-us", and gets nothing when
     * only its Dutch address matches — resolving across languages here is
     * exactly what would publish Dutch content under an English URL.
     *
     * A route-bound page can never come out of this: its address is its
     * route, so its localized slug is NULL, and NULL never equals a submitted
     * slug.
     *
     * @return array<string, mixed>|null a `pages` row
     */
    public function findPageBySlug(string $slug, string $languageCode): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT p.*
               FROM page_translations t
               JOIN pages p ON p.id = t.page_id
              WHERE t.language_code = :language_code
                AND t.slug = :slug
                AND p.status = 'published'
              LIMIT 1"
        );
        $stmt->execute(['language_code' => $languageCode, 'slug' => $slug]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** Is this address already taken IN THIS LANGUAGE by another page? */
    public function slugExists(string $slug, string $languageCode, ?int $excludePageId = null): bool
    {
        if ($excludePageId !== null) {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM page_translations
                  WHERE language_code = :language_code AND slug = :slug AND page_id != :page_id LIMIT 1'
            );
            $stmt->execute(['language_code' => $languageCode, 'slug' => $slug, 'page_id' => $excludePageId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM page_translations WHERE language_code = :language_code AND slug = :slug LIMIT 1'
            );
            $stmt->execute(['language_code' => $languageCode, 'slug' => $slug]);
        }

        return $stmt->fetch() !== false;
    }

    public function delete(int $pageId, string $languageCode): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM page_translations WHERE page_id = :page_id AND language_code = :language_code'
        );
        $stmt->execute(['page_id' => $pageId, 'language_code' => $languageCode]);
    }
}

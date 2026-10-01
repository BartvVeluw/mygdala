<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Publishing\PublicationStatus;
use App\Service\Publishing\PublicationVisibility;

/**
 * The `articles` table (ARTICLES.md): the language-neutral half of an
 * article. Its words and addresses are App\Service\Articles\
 * ArticleLocalization's, and this class never names their table: a public
 * list in one language is narrowed by the ids ArticleLocalization hands it
 * (the owners with an address in that language).
 *
 * PUBLIC READS go through the Publishing Engine's one rule
 * (PublicationVisibility::listedSql()/reachableSql()) with `now` bound once
 * from PublishingClock — never `status = 'published'` written here. The
 * admin reads every row.
 */
final class ArticleRepository extends Repository
{
    private const PUBLIC_ORDER = 'a.published_at DESC, a.id DESC';

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT a.* FROM articles a WHERE a.id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /** Reachable: listed, or archived with a moment that has passed. @return array<string, mixed>|null */
    public function findReachableById(int $id, string $now): ?array
    {
        $stmt = $this->db->prepare('SELECT a.* FROM articles a WHERE a.id = :id AND ' . PublicationVisibility::reachableSql('a'));
        $stmt->execute(['id' => $id, 'now' => $now]);

        return $stmt->fetch() ?: null;
    }

    /**
     * One page of listed articles among $ids (the ones with a version in the
     * language being read, from ArticleLocalization), newest first,
     * optionally of one topic.
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findListed(string $now, array $ids, int $limit, int $offset, ?int $topicId = null): array
    {
        $query = $this->listedQuery('a.*', $now, $ids, $topicId);
        if ($query === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            $query[0] . ' ORDER BY ' . self::PUBLIC_ORDER
            . ' LIMIT ' . max(1, min(100, $limit)) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute($query[1]);

        return $stmt->fetchAll();
    }

    /** @param list<int> $ids */
    public function countListed(string $now, array $ids, ?int $topicId = null): int
    {
        $query = $this->listedQuery('COUNT(*)', $now, $ids, $topicId);
        if ($query === null) {
            return 0;
        }

        $stmt = $this->db->prepare($query[0]);
        $stmt->execute($query[1]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Listed articles among these ids, in any language (search, link pickers).
     *
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function findListedByIds(array $ids, string $now): array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT a.* FROM articles a WHERE a.id IN (' . implode(', ', $ids) . ') AND '
            . PublicationVisibility::listedSql('a') . ' ORDER BY ' . self::PUBLIC_ORDER
        );
        $stmt->execute(['now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * Every listed article, for the sitemap: one query, the columns
     * ArticleSeo::isIndexable() reads.
     *
     * @return list<array<string, mixed>>
     */
    public function findListedForSitemap(string $now): array
    {
        $stmt = $this->db->prepare(
            'SELECT a.id, a.status, a.published_at, a.noindex, a.updated_at FROM articles a WHERE '
            . PublicationVisibility::listedSql('a') . ' ORDER BY ' . self::PUBLIC_ORDER
        );
        $stmt->execute(['now' => $now]);

        return $stmt->fetchAll();
    }

    /**
     * How many listed articles each topic holds, among $ids when given (a
     * language's versions), for the topic row above the listing and the
     * sitemap.
     *
     * @param list<int>|null $ids
     * @return array<int, int> topic id => count
     */
    public function listedCountsByTopic(string $now, ?array $ids = null): array
    {
        $where = '';
        if ($ids !== null) {
            $ids = self::ids($ids);
            if ($ids === []) {
                return [];
            }
            $where = ' AND a.id IN (' . implode(', ', $ids) . ')';
        }

        $stmt = $this->db->prepare(
            'SELECT a.topic_id, COUNT(*) AS total FROM articles a'
            . ' WHERE a.topic_id IS NOT NULL AND ' . PublicationVisibility::listedSql('a') . $where
            . ' GROUP BY a.topic_id'
        );
        $stmt->execute(['now' => $now]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['topic_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Every article for the CMS, whatever its state.
     *
     * @param array{status?: string, topic_id?: int, title_ids?: list<int>} $filters
     * @return list<array<string, mixed>>
     */
    public function findForAdmin(array $filters = [], int $limit = 500): array
    {
        $where = ['1 = 1'];
        $params = [];

        $status = (string) ($filters['status'] ?? '');
        if (PublicationStatus::isValid($status)) {
            $where[] = 'a.status = :status';
            $params['status'] = PublicationStatus::normalize($status);
        }

        $topicId = (int) ($filters['topic_id'] ?? 0);
        if ($topicId > 0) {
            $where[] = 'a.topic_id = :topic_id';
            $params['topic_id'] = $topicId;
        }

        if (array_key_exists('title_ids', $filters)) {
            $ids = self::ids((array) $filters['title_ids']);
            if ($ids === []) {
                return [];
            }
            $where[] = 'a.id IN (' . implode(', ', $ids) . ')';
        }

        $stmt = $this->db->prepare(
            'SELECT a.* FROM articles a WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(a.published_at, a.created_at) DESC, a.id DESC'
            . ' LIMIT ' . max(1, min(1000, $limit))
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Which articles use any of these media items as featured image, in one
     * query (App\Service\Articles\ArticleMediaUsage).
     *
     * @param list<int> $mediaIds
     * @return list<array{id: int|string, featured_media_id: int|string}>
     */
    public function usingMedia(array $mediaIds): array
    {
        $ids = self::ids($mediaIds);
        if ($ids === []) {
            return [];
        }

        return $this->db->query(
            'SELECT id, featured_media_id FROM articles WHERE featured_media_id IN (' . implode(', ', $ids) . ')'
        )->fetchAll();
    }

    /** @return array<string, int> status => count, unknown values counted as draft */
    public function countsByStatus(): array
    {
        $counts = [];
        foreach ($this->db->query('SELECT status, COUNT(*) AS total FROM articles GROUP BY status')->fetchAll() as $row) {
            $status = PublicationStatus::normalize($row['status']);
            $counts[$status] = ($counts[$status] ?? 0) + (int) $row['total'];
        }

        return $counts;
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        $parameters = self::parameters($values);
        $columns = array_keys($parameters);

        $this->db->prepare(
            'INSERT INTO articles (' . implode(', ', $columns) . ', created_at, updated_at)'
            . ' VALUES (:' . implode(', :', $columns) . ', NOW(), NOW())'
        )->execute($parameters);

        return (int) $this->db->lastInsertId();
    }

    /** @param array<string, mixed> $values */
    public function update(int $id, array $values): void
    {
        $parameters = self::parameters($values);
        $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($parameters));
        $parameters['id'] = $id;

        $this->db->prepare('UPDATE articles SET ' . implode(', ', $assignments) . ', updated_at = NOW() WHERE id = :id')
            ->execute($parameters);
    }

    /** Status and moment only (ArticlePublishable::savePublication()). */
    public function updatePublication(int $id, string $status, ?string $publishedAt): void
    {
        $this->db->prepare('UPDATE articles SET status = :status, published_at = :published_at, updated_at = NOW() WHERE id = :id')
            ->execute(['status' => PublicationStatus::normalize($status), 'published_at' => $publishedAt, 'id' => $id]);
    }

    /** Marks the article as changed: its blocks were edited (ArticleContentOwner). */
    public function touch(int $id): void
    {
        $this->db->prepare('UPDATE articles SET updated_at = NOW() WHERE id = :id')->execute(['id' => $id]);
    }

    /** The row and, by CASCADE, its translations. Media items are not touched. */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM articles WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param list<int> $ids
     * @return array{0: string, 1: array<string, mixed>}|null null when no id can match
     */
    private function listedQuery(string $select, string $now, array $ids, ?int $topicId): ?array
    {
        $ids = self::ids($ids);
        if ($ids === []) {
            return null;
        }

        $params = ['now' => $now];
        $sql = 'SELECT ' . $select . ' FROM articles a'
            . ' WHERE a.id IN (' . implode(', ', $ids) . ') AND ' . PublicationVisibility::listedSql('a');

        if ($topicId !== null) {
            $sql .= ' AND a.topic_id = :topic_id';
            $params['topic_id'] = $topicId;
        }

        return [$sql, $params];
    }

    /**
     * Only the columns this table has; words live in article_translations.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function parameters(array $values): array
    {
        $parameters = [];

        foreach (['status', 'published_at', 'author_name', 'featured_media_id', 'topic_id', 'noindex'] as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }

            $value = $values[$column];
            $parameters[$column] = match ($column) {
                'status' => PublicationStatus::normalize($value),
                'noindex' => (int) (bool) $value,
                'featured_media_id', 'topic_id' => ($value === null || (int) $value < 1) ? null : (int) $value,
                'author_name' => trim((string) $value) === '' ? null : trim((string) $value),
                default => $value === null || $value === '' ? null : (string) $value,
            };
        }

        return $parameters;
    }

    /**
     * @param array<mixed> $ids
     * @return list<int>
     */
    private static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}

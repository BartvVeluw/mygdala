<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * The `article_topics` table (ARTICLES.md, "Onderwerpen"): one flat list,
 * ordered by hand. A topic's name and address are per language
 * (App\Service\Articles\ArticleLocalization::topics()).
 */
final class ArticleTopicRepository extends Repository
{
    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->query('SELECT * FROM article_topics ORDER BY sort_order ASC, id ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM article_topics WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->fetch() ?: null;
    }

    public function exists(int $id): bool
    {
        return $this->find($id) !== null;
    }

    public function create(int $sortOrder): int
    {
        $this->db->prepare('INSERT INTO article_topics (sort_order, created_at, updated_at) VALUES (:sort_order, NOW(), NOW())')
            ->execute(['sort_order' => $sortOrder]);

        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, int $sortOrder): void
    {
        $this->db->prepare('UPDATE article_topics SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id')
            ->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    /**
     * How many articles (any status) carry each topic, in one query: the
     * admin list's count column.
     *
     * @return array<int, int> topic id => count
     */
    public function articleCounts(): array
    {
        $counts = [];
        foreach ($this->db->query('SELECT topic_id, COUNT(*) AS total FROM articles WHERE topic_id IS NOT NULL GROUP BY topic_id')->fetchAll() as $row) {
            $counts[(int) $row['topic_id']] = (int) $row['total'];
        }

        return $counts;
    }

    /** The topic and, by CASCADE, its translations; its articles keep existing without one (SET NULL). */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM article_topics WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }
}

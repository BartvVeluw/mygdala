<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All footer_social_links SQL. The schema and why it is a table are in
 * db/migrations/20260917100000_move_social_profiles_into_footer_social_links.php.
 *
 * A plain list: one order for every row, no grouping. `network` is stored as
 * given; whether it is a network this project knows, and whether the address
 * belongs to it, is App\Service\SocialProfiles' decision, made by the admin
 * endpoints before a write and again by SocialProfiles::forFooter() before a
 * row reaches a page. Nothing here knows a network name.
 *
 * ↑/↓ work like NavigationRepository::move(): swap with the neighbour and
 * rewrite the whole order, so gaps or duplicates left by an older write can
 * never make a move do nothing.
 */
final class FooterSocialLinkRepository extends Repository
{
    /** @return list<array<string, mixed>> every row, in order */
    public function findAll(): array
    {
        return $this->db->query('SELECT * FROM footer_social_links ORDER BY sort_order ASC, id ASC')->fetchAll();
    }

    /** @return list<array<string, mixed>> the rows switched on, in order */
    public function findVisible(): array
    {
        return $this->db->query('SELECT * FROM footer_social_links WHERE is_visible = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM footer_social_links WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * A new profile goes to the end of the list.
     *
     * @param array{network: string, url: string, is_visible: bool} $data
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO footer_social_links (network, url, sort_order, is_visible, created_at, updated_at)
             VALUES (:network, :url, :sort_order, :is_visible, NOW(), NOW())'
        );
        $stmt->execute([
            'network' => $data['network'],
            'url' => $data['url'],
            'sort_order' => $this->nextSortOrder(),
            'is_visible' => $data['is_visible'] ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @param array{network: string, url: string, is_visible: bool} $data */
    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare(
            'UPDATE footer_social_links
                SET network = :network, url = :url, is_visible = :is_visible, updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([
            'network' => $data['network'],
            'url' => $data['url'],
            'is_visible' => $data['is_visible'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM footer_social_links WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * One place up or down. The first row does not move up and the last does
     * not move down; an unknown id or direction changes nothing.
     */
    public function move(int $id, string $direction): void
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return;
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $this->findAll());

        $position = array_search($id, $ids, true);
        if ($position === false) {
            return;
        }

        $target = $direction === 'up' ? $position - 1 : $position + 1;
        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('UPDATE footer_social_links SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
            foreach ($ids as $order => $rowId) {
                $stmt->execute(['sort_order' => $order, 'id' => $rowId]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function nextSortOrder(): int
    {
        $row = $this->db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order FROM footer_social_links')->fetch();

        return (int) $row['next_sort_order'];
    }
}

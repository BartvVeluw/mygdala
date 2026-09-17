<?php

namespace App\Repository;

/**
 * All homepage_hero / homepage_hero_stats SQL lives here. See
 * App\Service\HomepageHeroContent for the defaults/fallback layer built on
 * top of this, and the migration docblocks for why this is a dedicated table
 * pair rather than a reuse of page_heroes/stat_strips.
 *
 * Only what is the same in every language is written here. The words of the
 * Hero and of every stat are stored per website language through
 * App\Service\Blocks\BlockLocalization, against the row's own id
 * (db/migrations/20260917190000), in the same transaction as the write that
 * belongs to them.
 */
class HomepageHeroRepository extends Repository
{
    /**
     * @return array<string, mixed>|null null when no row exists for this slug
     */
    public function findBySlug(string $pageSlug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM homepage_hero WHERE page_slug = :page_slug LIMIT 1');
        $stmt->execute(['page_slug' => $pageSlug]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM homepage_hero WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Inserts or updates the single row for this page slug: what is the same
     * in every language. Callers must always pass the complete field set
     * (title_highlight_size, both URLs, image_path, media_type/video_path/
     * layout and is_active) even when only a subset actually changed — see
     * api/admin/update-homepage-hero.php,
     * api/admin/update-homepage-hero-image.php,
     * api/admin/update-homepage-hero-video.php and
     * api/admin/update-homepage-hero-media.php, which each merge their own
     * changed subset onto the current row's values
     * (HomepageHeroContent::settingsOf()) before calling this.
     *
     * @param array<string, string|int|bool> $values
     */
    public function upsert(string $pageSlug, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO homepage_hero
                (page_slug, title_highlight_size, primary_url, secondary_url,
                 image_path, media_type, video_path, layout,
                 is_active, created_at, updated_at)
             VALUES
                (:page_slug, :title_highlight_size, :primary_url, :secondary_url,
                 :image_path, :media_type, :video_path, :layout,
                 :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                title_highlight_size = VALUES(title_highlight_size),
                primary_url = VALUES(primary_url),
                secondary_url = VALUES(secondary_url),
                image_path = VALUES(image_path),
                media_type = VALUES(media_type),
                video_path = VALUES(video_path),
                layout = VALUES(layout),
                is_active = VALUES(is_active),
                updated_at = NOW()'
        );

        $stmt->execute([
            'page_slug' => $pageSlug,
            // Always an int percentage here — same convention as media_type
            // and layout: callers pass a value that
            // App\Service\HomepageHeroContent has already validated
            // (isHighlightSizeValid) or clamped (clampHighlightSize, which
            // is also what turns a legacy NULL into the default), this layer
            // only writes it.
            'title_highlight_size' => (int) $values['title_highlight_size'],
            'primary_url' => $values['primary_url'],
            'secondary_url' => self::nullIfEmpty($values['secondary_url'] ?? null),
            'image_path' => $values['image_path'],
            'media_type' => $values['media_type'],
            'video_path' => self::nullIfEmpty($values['video_path'] ?? null),
            'layout' => $values['layout'],
            'is_active' => $values['is_active'] ? 1 : 0,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> ordered by sort_order ASC, id ASC
     */
    public function findStatsByHeroId(int $heroId, bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM homepage_hero_stats WHERE homepage_hero_id = :homepage_hero_id';
        if ($onlyActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['homepage_hero_id' => $heroId]);

        return $stmt->fetchAll();
    }

    /**
     * Total stat rows (active and inactive) for one Hero — used to enforce
     * the max-3 cap before creating a new one; a deleted row frees a slot, an
     * individually hidden one does not.
     */
    public function countStatsByHeroId(int $heroId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM homepage_hero_stats WHERE homepage_hero_id = :homepage_hero_id');
        $stmt->execute(['homepage_hero_id' => $heroId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findStatById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM homepage_hero_stats WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Appends a new, visible stat to the end of the Hero's stats and returns
     * its id. Its two texts are words, stored per website language against
     * that id (App\Service\Blocks\BlockLocalization), in the same transaction
     * as this insert. Callers must check countStatsByHeroId() against the
     * max-3 cap first — this method does not enforce it (see
     * api/admin/create-homepage-hero-stat.php).
     */
    public function createStat(int $heroId): int
    {
        $nextSortOrder = $this->nextSortOrder($heroId);

        $stmt = $this->db->prepare(
            'INSERT INTO homepage_hero_stats
                (homepage_hero_id, sort_order, is_active, created_at, updated_at)
             VALUES
                (:homepage_hero_id, :sort_order, 1, NOW(), NOW())'
        );
        $stmt->execute([
            'homepage_hero_id' => $heroId,
            'sort_order' => $nextSortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What a stat has that is the same in every language: whether it is
     * shown. Its words are saved through BlockLocalization. The id never
     * changes, so the words of every language stay attached to it.
     *
     * @param array{is_active: bool} $values
     */
    public function updateStat(int $id, array $values): void
    {
        $stmt = $this->db->prepare(
            'UPDATE homepage_hero_stats SET
                is_active = :is_active,
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'is_active' => $values['is_active'] ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Permanently removes a stat — distinct from hiding one via is_active
     * (see updateStat). Used by the admin "Verwijderen" action, which removes
     * the stat's words first, in the same transaction
     * (BlockLocalization::deleteOwner()); also how a slot is freed back up
     * under the max-3 cap.
     */
    public function deleteStat(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM homepage_hero_stats WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Swaps sort_order with the previous/next stat (in current display
     * order) within the same Hero — same approach as
     * StatStripRepository::moveItem().
     */
    public function moveStat(int $heroId, int $itemId, string $direction): void
    {
        $items = $this->findStatsByHeroId($heroId);

        $index = null;
        foreach ($items as $i => $item) {
            if ((int) $item['id'] === $itemId) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($items)) {
            return;
        }

        $a = $items[$index];
        $b = $items[$swapWith];

        $this->updateSortOrder((int) $a['id'], (int) $b['sort_order']);
        $this->updateSortOrder((int) $b['id'], (int) $a['sort_order']);
    }

    private function updateSortOrder(int $id, int $sortOrder): void
    {
        $stmt = $this->db->prepare('UPDATE homepage_hero_stats SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['sort_order' => $sortOrder, 'id' => $id]);
    }

    private function nextSortOrder(int $heroId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 AS next_sort_order
             FROM homepage_hero_stats WHERE homepage_hero_id = :homepage_hero_id'
        );
        $stmt->execute(['homepage_hero_id' => $heroId]);

        return (int) $stmt->fetch()['next_sort_order'];
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}

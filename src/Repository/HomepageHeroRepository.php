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
     * api/admin/update-homepage-hero.php, which merges what its one form
     * changed onto the current row's values
     * (HomepageHeroContent::settingsOf()) before calling this.
     *
     * @param array<string, string|int|bool> $values
     */
    public function upsert(string $pageSlug, array $values): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO homepage_hero
                (page_slug, title_highlight_size,
                 primary_url, primary_link_type, primary_link_target_id,
                 secondary_url, secondary_link_type, secondary_link_target_id,
                 image_path, media_id, media_type, video_path, video_media_id, layout,
                 is_active, created_at, updated_at)
             VALUES
                (:page_slug, :title_highlight_size,
                 :primary_url, :primary_link_type, :primary_link_target_id,
                 :secondary_url, :secondary_link_type, :secondary_link_target_id,
                 :image_path, :media_id, :media_type, :video_path, :video_media_id, :layout,
                 :is_active, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                title_highlight_size = VALUES(title_highlight_size),
                primary_url = VALUES(primary_url),
                primary_link_type = VALUES(primary_link_type),
                primary_link_target_id = VALUES(primary_link_target_id),
                secondary_url = VALUES(secondary_url),
                secondary_link_type = VALUES(secondary_link_type),
                secondary_link_target_id = VALUES(secondary_link_target_id),
                image_path = VALUES(image_path),
                media_id = VALUES(media_id),
                media_type = VALUES(media_type),
                video_path = VALUES(video_path),
                video_media_id = VALUES(video_media_id),
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
            'primary_link_type' => self::nullIfEmpty($values['primary_link_type'] ?? null),
            'primary_link_target_id' => self::positiveOrNull($values['primary_link_target_id'] ?? null),
            'secondary_url' => self::nullIfEmpty($values['secondary_url'] ?? null),
            'secondary_link_type' => self::nullIfEmpty($values['secondary_link_type'] ?? null),
            'secondary_link_target_id' => self::positiveOrNull($values['secondary_link_target_id'] ?? null),
            'image_path' => $values['image_path'],
            'media_id' => self::positiveOrNull($values['media_id'] ?? null),
            'media_type' => $values['media_type'],
            'video_path' => self::nullIfEmpty($values['video_path'] ?? null),
            'video_media_id' => self::positiveOrNull($values['video_media_id'] ?? null),
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
     * Appends a new, visible stat to the end of the Hero's stats and returns
     * its id. Its two texts are words, stored per website language against
     * that id (App\Service\Blocks\BlockLocalization), in the same transaction
     * as this insert. Callers must check the max-3 cap first — this method
     * does not enforce it (see api/admin/update-homepage-hero.php).
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
     * Stores the order of the stats of the Homepage Hero as the one-form
     * editor posted it (App\Service\Blocks\EditorChildList::save()): the
     * first id gets sort_order 0. An id that is not a row of $heroId is left
     * alone.
     *
     * @param list<int> $orderedIds
     */
    public function reorderStats(int $heroId, array $orderedIds): void
    {
        $stmt = $this->db->prepare(
            'UPDATE homepage_hero_stats SET sort_order = :sort_order, updated_at = NOW() WHERE id = :id AND homepage_hero_id = :parent_id'
        );

        foreach (array_values($orderedIds) as $position => $id) {
            $stmt->execute(['sort_order' => $position, 'id' => (int) $id, 'parent_id' => $heroId]);
        }
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

    /** An id, or NULL for "none" (0, '' or null). */
    private static function positiveOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullIfEmpty(?string $value): ?string
    {
        return ($value !== null && $value !== '') ? $value : null;
    }
}

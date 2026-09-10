<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Brings the images this installation is ALREADY showing into the Media
 * Library, without asking anybody to upload anything again and without
 * moving a single file.
 *
 * ADOPT IN PLACE. A file keeps the path it has today — assets/images/...,
 * assets/images/sections/..., wherever it landed. Only NEW uploads go to the
 * library's own folder (assets/media/). Moving files during a migration
 * would mean a deployment in which the database and the filesystem have to
 * change together to keep the site rendering, and there is nothing to gain
 * from it: a media row is an identity, not a location. MEDIA.md records that
 * distinction so it stays deliberate.
 *
 * WHAT IT ADOPTS. Exactly the features that join the library in V1: the four
 * branding assets, a CMS page's own social image, and the images of the
 * three integrated content blocks. Portfolio, product, variant and
 * collection images are deliberately left alone — they keep working from
 * their own paths, and adopting them is a later, separate decision.
 *
 * ONE ROW PER FILE. Paths are deduplicated, so an image used by two blocks
 * becomes one media item referenced twice — which is the entire point of the
 * exercise, and is what makes "where is this used" answerable. The unique
 * index on media.path plus the "skip a column that is already set" rule
 * below make the whole migration safe to run more than once.
 *
 * ALT TEXT. The first non-empty Dutch alt text found for a file becomes that
 * media item's default. Nothing is removed: every per-image alt column keeps
 * its value and keeps winning locally, so a photo captioned differently in
 * two places stays captioned differently. See MEDIA.md.
 *
 * A MISSING FILE IS NOT AN ERROR. A path in the database whose file is gone
 * is adopted with unknown dimensions rather than skipped, so the admin can
 * show it as broken and an editor can fix it. Skipping it would leave the
 * feature invisibly on its legacy path forever.
 */
final class AdoptExistingCmsImagesIntoTheMediaLibrary extends AbstractMigration
{
    /**
     * The block/page columns to adopt: table => [path column, media id
     * column, alt column or null].
     */
    private const SOURCES = [
        'text_image_split_images' => ['image_path', 'media_id', 'alt_nl'],
        'detail_sections' => ['main_image_path', 'main_media_id', 'main_image_alt_nl'],
        'detail_section_images' => ['image_path', 'media_id', 'alt_nl'],
        'carousel_cards' => ['image_path', 'media_id', 'image_alt_nl'],
        'pages' => ['og_image_path', 'og_media_id', null],
    ];

    /** The branding settings: existing path key => new media id key. */
    private const BRANDING_KEYS = [
        'logo_path' => 'logo_media_id',
        'logo_alt_path' => 'logo_alt_media_id',
        'favicon_path' => 'favicon_media_id',
        'og_image_path' => 'og_image_media_id',
    ];

    private const EXTENSION_MIME = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'avif' => 'image/avif',
    ];

    private \PDO $pdo;
    private string $root;

    public function up(): void
    {
        if (!$this->hasTable('media')) {
            return;
        }

        $this->pdo = $this->getAdapter()->getConnection();
        $this->root = dirname(__DIR__, 2) . '/';

        foreach (self::SOURCES as $table => [$pathColumn, $mediaColumn, $altColumn]) {
            $this->adoptTable($table, $pathColumn, $mediaColumn, $altColumn);
        }

        $this->adoptBranding();
    }

    private function adoptTable(string $table, string $pathColumn, string $mediaColumn, ?string $altColumn): void
    {
        if (!$this->hasTable($table)) {
            return;
        }

        $tableObject = $this->table($table);

        if (!$tableObject->hasColumn($mediaColumn) || !$tableObject->hasColumn($pathColumn)) {
            return;
        }

        $altSelect = $altColumn !== null ? ', `' . $altColumn . '` AS alt' : ', NULL AS alt';

        // Only rows that have a path and no media id yet: a row an editor has
        // already re-pointed at a different media item is never overwritten,
        // and a second run of this migration finds nothing to do.
        $rows = $this->pdo->query(
            'SELECT id, `' . $pathColumn . '` AS path' . $altSelect
            . ' FROM `' . $table . '`'
            . ' WHERE `' . $pathColumn . '` IS NOT NULL AND `' . $pathColumn . "` <> ''"
            . ' AND `' . $mediaColumn . '` IS NULL'
        )->fetchAll(\PDO::FETCH_ASSOC);

        $link = $this->pdo->prepare('UPDATE `' . $table . '` SET `' . $mediaColumn . '` = ? WHERE id = ?');

        foreach ($rows as $row) {
            $mediaId = $this->mediaIdFor((string) $row['path'], (string) ($row['alt'] ?? ''));

            if ($mediaId !== null) {
                $link->execute([$mediaId, (int) $row['id']]);
            }
        }
    }

    /**
     * The site's own identity assets. They are settings rows rather than
     * columns, so the reference is a row too: `logo_media_id` next to the
     * `logo_path` that keeps working as the fallback.
     */
    private function adoptBranding(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }

        $read = $this->pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, ?, ?)'
        );
        $now = date('Y-m-d H:i:s');

        foreach (self::BRANDING_KEYS as $pathKey => $mediaKey) {
            $read->execute([$mediaKey]);
            if ($read->fetchColumn() !== false) {
                continue; // already adopted
            }

            $read->execute([$pathKey]);
            $stored = $read->fetchColumn();
            $path = $stored === false ? '' : (string) $stored;

            $mediaId = $this->mediaIdFor($path, '');

            if ($mediaId === null) {
                continue;
            }

            $insert->execute([$mediaKey, (string) $mediaId, $now, $now]);
        }
    }

    /**
     * The media id for a stored path, creating the row the first time that
     * path is seen. Returns null for anything this library must not own: an
     * empty value, or an absolute URL (a logo served from somewhere else is
     * not a file this site can inspect, delete, or claim to know the size of).
     */
    private function mediaIdFor(string $path, string $alt): ?int
    {
        $path = ltrim(trim($path), '/');

        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return null;
        }

        $existing = $this->pdo->prepare('SELECT id FROM media WHERE path = ?');
        $existing->execute([$path]);
        $id = $existing->fetchColumn();

        if ($id !== false) {
            $this->fillMissingAltText((int) $id, $alt);

            return (int) $id;
        }

        $absolute = $this->root . $path;

        $width = null;
        $height = null;
        $mime = self::EXTENSION_MIME[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? '';
        $size = null;
        $checksum = null;

        if (is_file($absolute)) {
            $size = filesize($absolute) ?: null;
            $checksum = hash_file('sha256', $absolute) ?: null;

            $info = @getimagesize($absolute);
            if ($info !== false) {
                $width = (int) $info[0];
                $height = (int) $info[1];
                if (isset($info['mime']) && is_string($info['mime']) && $info['mime'] !== '') {
                    $mime = $info['mime'];
                }
            }
        }

        $now = date('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO media (path, thumbnail_path, original_filename, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at)
             VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $path,
            // The file's own basename is the only "original name" a legacy
            // file has; it is what an editor will recognise it by in the
            // library, and what search matches on.
            basename($path),
            $mime,
            $width,
            $height,
            $size,
            mb_substr(trim($alt), 0, 255),
            $checksum,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A file adopted from a place that had no alt text can still get one from
     * the next place that uses it. An alt text that is already set is never
     * replaced — first non-empty wins, so the result does not depend on table
     * order beyond that.
     */
    private function fillMissingAltText(int $mediaId, string $alt): void
    {
        $alt = trim($alt);

        if ($alt === '') {
            return;
        }

        $this->pdo
            ->prepare("UPDATE media SET alt_text = ?, updated_at = ? WHERE id = ? AND (alt_text IS NULL OR alt_text = '')")
            ->execute([mb_substr($alt, 0, 255), date('Y-m-d H:i:s'), $mediaId]);
    }

    /**
     * Nothing to undo. The media rows this created describe files that were
     * already on disk and are still on disk; the columns it filled are
     * removed by the migration that added them, if that one is ever rolled
     * back. Deleting media rows here could take an item an editor has since
     * pointed something new at.
     */
    public function down(): void
    {
    }
}

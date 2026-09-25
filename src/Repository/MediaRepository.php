<?php

declare(strict_types=1);

namespace App\Repository;

/**
 * All SQL for the `media` table, and nothing else.
 *
 * DELIBERATELY IGNORANT OF EVERY FEATURE. There is no method here that knows
 * what a page, a block, a product or a logo is: the Media Library knows about
 * media, and which feature happens to use an item is that feature's own
 * knowledge, asked for through App\Service\Media\MediaUsageRegistry. Putting
 * a "find media used by products" query here is exactly the coupling
 * MODULES.md exists to prevent — Core would then have to name a Shop table.
 */
class MediaRepository extends Repository
{
    /** How many items one page of the library shows. */
    public const PAGE_SIZE = 24;

    private const COLUMNS = 'id, path, thumbnail_path, original_filename, display_name, folder_id, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at';

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by id, so a caller can
     *                                          resolve many references with
     *                                          one query instead of N
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE id IN (' . $placeholders . ')');
        $stmt->execute($ids);

        $byId = [];
        foreach ($stmt->fetchAll() as $row) {
            $byId[(int) $row['id']] = $row;
        }

        return $byId;
    }

    /** The item stored at this exact path, if the library already owns it. */
    public function findByPath(string $path): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE path = :path');
        $stmt->execute(['path' => ltrim($path, '/')]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * The item whose stored bytes hash to this checksum — how an exact
     * re-upload of a file the library already has finds its way back to the
     * existing item instead of making a second copy. Oldest first, so the
     * original is reused rather than a later duplicate.
     */
    public function findByChecksum(string $checksum): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM media WHERE checksum = :checksum ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['checksum' => $checksum]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Whether another item already carries this name.
     *
     * Compared the way the column's collation compares, which is without
     * regard to case: "Logo.png" and "logo.png" side by side in the grid are
     * one name to the person reading them. See
     * App\Service\Media\MediaService for what happens when a name is taken.
     */
    public function displayNameTaken(string $name, int $exceptId = 0): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM media WHERE display_name = :name AND id <> :id LIMIT 1');
        $stmt->execute(['name' => $name, 'id' => $exceptId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * One page of the library, newest first, optionally narrowed by a search
     * term and by a kind of file. The search is a plain LIKE over what a
     * human can actually remember about an image: the name it has in the
     * library, the name of the file they uploaded, and the alt text they
     * wrote. The kind is how the MIME type begins
     * (App\Service\Media\MediaType), and a folder narrows it to one virtual
     * folder or to the items without one. No tags, no ranking — see MEDIA.md.
     *
     * @param string|null $mimePrefix "image/", or null for every kind
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $term = '', int $limit = self::PAGE_SIZE, int $offset = 0, ?string $mimePrefix = null, ?array $mimes = null, string $folder = ''): array
    {
        [$where, $params] = $this->searchClause($term, $mimePrefix, $mimes, $folder);

        $sql = 'SELECT ' . self::COLUMNS . ' FROM media' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** How many items the same search matches, for the pager. */
    public function countSearch(string $term = '', ?string $mimePrefix = null, ?array $mimes = null, string $folder = ''): int
    {
        [$where, $params] = $this->searchClause($term, $mimePrefix, $mimes, $folder);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM media' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * LIMIT/OFFSET are interpolated as integers above rather than bound.
     * They pass through max() and an int cast first, so nothing from a
     * request ever reaches that string — and keeping them out of the
     * parameter list means the same clause works whether or not the driver
     * is emulating prepares.
     *
     * $folder is a folder filter as App\Service\Media\MediaFolderService::filter()
     * returns it: '' for every folder, 'none' for the items without one, or
     * a folder id in digits. Bound, never interpolated.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function searchClause(string $term, ?string $mimePrefix, ?array $mimes = null, string $folder = ''): array
    {
        $conditions = [];
        $params = [];
        $term = trim($term);

        if ($term !== '') {
            // Escape the LIKE wildcards themselves: an editor searching for
            // "foto_1" means the underscore, and `_` matching any character is
            // the same trap that once deleted real products here.
            $pattern = '%' . self::escapeLike($term) . '%';

            $conditions[] = "(display_name LIKE :name ESCAPE '\\\\' OR original_filename LIKE :term ESCAPE '\\\\' OR alt_text LIKE :alt ESCAPE '\\\\')";
            $params += ['name' => $pattern, 'term' => $pattern, 'alt' => $pattern];
        }

        if ($mimePrefix !== null && $mimePrefix !== '') {
            $conditions[] = "mime_type LIKE :mime ESCAPE '\\\\'";
            $params['mime'] = self::escapeLike($mimePrefix) . '%';
        }

        // Exact MIME types on top of the kind: a picker filter such as the
        // share image's raster-only list (App\Service\Media\MediaType).
        if ($mimes !== null) {
            $names = [];
            foreach (array_values($mimes) as $index => $mime) {
                $names[] = ':exact_mime_' . $index;
                $params['exact_mime_' . $index] = (string) $mime;
            }
            $conditions[] = $names === [] ? '1 = 0' : 'mime_type IN (' . implode(', ', $names) . ')';
        }

        if ($folder === 'none') {
            $conditions[] = 'folder_id IS NULL';
        } elseif (ctype_digit($folder) && (int) $folder > 0) {
            $conditions[] = 'folder_id = :folder';
            $params['folder'] = (string) (int) $folder;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param array<string, mixed> $values
     * @return int the new media id
     */
    public function create(array $values): int
    {
        $path = ltrim((string) $values['path'], '/');
        $originalFilename = mb_substr((string) ($values['original_filename'] ?? ''), 0, 255);
        $displayName = trim((string) ($values['display_name'] ?? ''));

        // Every row carries a name, also when a caller did not choose one:
        // the same name the library showed before names could be chosen.
        if ($displayName === '') {
            $displayName = $originalFilename !== '' ? $originalFilename : basename($path);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO media (path, thumbnail_path, original_filename, display_name, folder_id, mime_type, width, height, file_size, alt_text, checksum, created_at, updated_at)
             VALUES (:path, :thumbnail_path, :original_filename, :display_name, :folder_id, :mime_type, :width, :height, :file_size, :alt_text, :checksum, NOW(), NOW())'
        );

        $stmt->execute([
            'path' => $path,
            'thumbnail_path' => $values['thumbnail_path'] ?? null,
            'original_filename' => $originalFilename,
            'display_name' => mb_substr($displayName, 0, 255),
            // A folder the caller has already checked (MediaFolderService);
            // NULL is "Geen map".
            'folder_id' => isset($values['folder_id']) && (int) $values['folder_id'] > 0 ? (int) $values['folder_id'] : null,
            'mime_type' => mb_substr((string) ($values['mime_type'] ?? ''), 0, 100),
            'width' => $values['width'] ?? null,
            'height' => $values['height'] ?? null,
            'file_size' => $values['file_size'] ?? null,
            'alt_text' => mb_substr((string) ($values['alt_text'] ?? ''), 0, 255),
            'checksum' => $values['checksum'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The alt text alone, for the one caller that fills a gap without an
     * editor's form: a duplicate upload that brings an alt text for an item
     * that had none (App\Service\Media\MediaService::upload()).
     */
    public function updateMetadata(int $id, string $altText): void
    {
        $stmt = $this->db->prepare('UPDATE media SET alt_text = :alt_text, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['alt_text' => mb_substr($altText, 0, 255), 'id' => $id]);
    }

    /**
     * The name and the alt text together: what an editor owns about an item,
     * saved as one (App\Service\Media\MediaService::updateDetails(), the one
     * write behind the item screen and the quick edit). The path, the
     * checksum and the dimensions describe the FILE and are not editable
     * text: changing them would make the row lie about what is on disk.
     */
    public function updateDetails(int $id, string $name, string $altText): void
    {
        $stmt = $this->db->prepare('UPDATE media SET display_name = :name, alt_text = :alt_text, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['name' => mb_substr($name, 0, 255), 'alt_text' => mb_substr($altText, 0, 255), 'id' => $id]);
    }

    /**
     * Files items under a folder, or under none: one UPDATE for a whole
     * selection. Only media.folder_id changes. The caller has checked that
     * the folder exists; the foreign key refuses one that does not.
     *
     * @param list<int> $ids
     * @return int how many rows changed folder
     */
    public function moveToFolder(array $ids, ?int $folderId): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare('UPDATE media SET folder_id = ?, updated_at = NOW() WHERE id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$folderId], $ids));

        return $stmt->rowCount();
    }

    /**
     * Removes the row. The caller (App\Service\Media\MediaService) has
     * already established that nothing uses it and is responsible for the
     * files; the foreign keys on the integrated feature columns are the
     * second line of defence and will refuse this outright if a reference
     * somehow still exists.
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM media WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Total number of items, for the admin's "x afbeeldingen" line. */
    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM media')->fetchColumn();
    }
}

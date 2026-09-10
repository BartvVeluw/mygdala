<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MediaRepository;

/**
 * The Media Library as everything else uses it: find an item, list and
 * search, add one, change its alt text, and delete one when — and only when
 * — nothing uses it.
 *
 * This is Core. It knows about media and about nothing else: no product, no
 * personalisation preview, no order attachment, no collection. Which
 * FEATURE uses an item is asked of App\Service\Media\MediaUsageRegistry,
 * which is how a module answers for its own tables without Core ever naming
 * them (MODULES.md).
 *
 * Null-tolerant like every other read model in this project: a lookup that
 * fails logs and returns null, so a database problem degrades a page to "no
 * image" instead of a stack trace.
 */
final class MediaService
{
    /** @var array<int, MediaItem|null> per-request cache, keyed by id */
    private static array $cache = [];

    private MediaRepository $repository;
    private MediaUploader $uploader;

    /**
     * The uploader is injectable for the same narrow reason its
     * isUploadedFile() is a seam: a test needs a file that PHP did not
     * receive through a real multipart request. Production never passes one.
     */
    public function __construct(?MediaRepository $repository = null, ?MediaUploader $uploader = null)
    {
        $this->repository = $repository ?? new MediaRepository();
        $this->uploader = $uploader ?? new MediaUploader();
    }

    /** Forgets the per-request cache; every write path calls this. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Seeds the per-request cache with known items, so a test can exercise
     * everything that CONSUMES media — branding fallbacks, a block's image
     * resolution, an og:image — without a database.
     *
     * The same shape as App\Service\SiteSettings::overrideForTests(): pass
     * rows to install them, null to go back to reading the database. It works
     * because find() answers from the cache before it ever opens a
     * connection; there is no second code path for tests to drift from.
     *
     * A null ROW means "this id is known not to exist", which is what lets a
     * test exercise the "the chosen image is gone" branch without a database
     * — find() answers from the cache either way, so there is still no
     * second code path.
     *
     * @param array<int, array<string, mixed>|null>|null $rows `media` rows, keyed by id
     */
    public static function overrideForTests(?array $rows): void
    {
        self::$cache = [];

        if ($rows === null) {
            return;
        }

        foreach ($rows as $id => $row) {
            self::$cache[(int) $id] = $row === null ? null : MediaItem::fromRow($row + ['id' => (int) $id]);
        }
    }

    /**
     * One media item, or null when the id is 0/absent/unknown. The
     * `?int` signature is the point: a caller can hand over a nullable
     * `media_id` column straight from a row without an `if` of its own.
     */
    public static function find(?int $id): ?MediaItem
    {
        $id = (int) $id;

        if ($id < 1) {
            return null;
        }

        if (array_key_exists($id, self::$cache)) {
            return self::$cache[$id];
        }

        try {
            $row = (new MediaRepository())->findById($id);
        } catch (\Throwable $e) {
            error_log('[MediaService] find(' . $id . '): ' . $e->getMessage());

            return self::$cache[$id] = null;
        }

        return self::$cache[$id] = $row === null ? null : MediaItem::fromRow($row);
    }

    /**
     * Many items in one query — for a template that renders a list of rows
     * each carrying a media id, so a gallery of twelve images is one lookup
     * rather than twelve.
     *
     * @param list<int|null> $ids
     *
     * @return array<int, MediaItem> keyed by id; unknown ids simply absent
     */
    public static function findMany(array $ids): array
    {
        $wanted = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($wanted === []) {
            return [];
        }

        try {
            $rows = (new MediaRepository())->findByIds($wanted);
        } catch (\Throwable $e) {
            error_log('[MediaService] findMany: ' . $e->getMessage());

            return [];
        }

        $items = [];
        foreach ($rows as $id => $row) {
            $items[$id] = self::$cache[$id] = MediaItem::fromRow($row);
        }

        foreach ($wanted as $id) {
            if (!array_key_exists($id, self::$cache)) {
                self::$cache[$id] = null;
            }
        }

        return $items;
    }

    /**
     * Whether this id names a real media item — the check every write
     * endpoint makes before storing a `media_id` a request supplied. A
     * picker returns an id, and an id from a request is never trusted to
     * exist just because it is numeric.
     */
    public static function exists(?int $id): bool
    {
        return self::find($id) !== null;
    }

    /**
     * One page of the library.
     *
     * @return array{items: list<MediaItem>, total: int}
     */
    public function browse(string $term = '', int $page = 1, int $perPage = MediaRepository::PAGE_SIZE): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $rows = $this->repository->search($term, $perPage, ($page - 1) * $perPage);

        return [
            'items' => array_map(static fn (array $row): MediaItem => MediaItem::fromRow($row), $rows),
            'total' => $this->repository->countSearch($term),
        ];
    }

    /**
     * Stores an uploaded file and creates its media row.
     *
     * DEDUPLICATION, in two passes, because an image is re-encoded on the way
     * in. If the exact same bytes are already in the library the existing item
     * comes back and nothing is written — an editor who uploads the same logo
     * twice gets one item used twice, which is the behaviour the whole library
     * exists for.
     *
     *   1. the checksum of what the browser SENT, before anything is stored.
     *      Catches a re-upload of a file that the optimizer leaves alone (a
     *      GIF), and costs one hash.
     *   2. the checksum of what was actually STORED. A JPEG, PNG or WebP goes
     *      through App\Service\ImageOptimizer, so its stored bytes are not the
     *      bytes that arrived — and the optimizer is deterministic, so the
     *      same photo uploaded twice produces the same stored bytes and this
     *      pass recognises it. The freshly written file is then removed again
     *      rather than left as a second copy of an item that already exists.
     *
     * Only an exact checksum match counts; nothing here compares images for
     * visual similarity, and nothing should.
     *
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file one entry of $_FILES
     *
     * @return array{item: MediaItem, reused: bool}
     *
     * @throws \RuntimeException with a Dutch, user-facing message
     */
    public function upload(array $file, string $altText = ''): array
    {
        $uploader = $this->uploader;

        $checksum = $uploader->checksumOf($file);

        if ($checksum !== null) {
            $existing = $this->repository->findByChecksum($checksum);

            if ($existing !== null) {
                $item = MediaItem::fromRow($existing);

                // An alt text typed with the duplicate upload fills a gap,
                // but never overwrites one an editor already wrote for the
                // item that other places are already using.
                if ($item->altText === '' && trim($altText) !== '') {
                    $this->repository->updateMetadata($item->id, trim($altText));
                    self::clearCache();
                    $item = self::find($item->id) ?? $item;
                }

                return ['item' => $item, 'reused' => true];
            }
        }

        $stored = $uploader->store($file);

        // Pass 2: the same photo, now that it has been through the optimizer.
        $existing = $this->repository->findByChecksum($stored['checksum']);

        if ($existing !== null) {
            $uploader->deleteFile($stored['path']);
            $uploader->deleteFile($stored['thumbnail_path']);

            $item = MediaItem::fromRow($existing);

            if ($item->altText === '' && trim($altText) !== '') {
                $this->repository->updateMetadata($item->id, trim($altText));
                self::clearCache();
                $item = self::find($item->id) ?? $item;
            }

            return ['item' => $item, 'reused' => true];
        }

        try {
            $id = $this->repository->create($stored + ['alt_text' => trim($altText)]);
        } catch (\Throwable $e) {
            error_log('[MediaService] upload: ' . $e->getMessage());

            // The row is what makes the file findable; without it the file
            // is litter. Remove both rather than leaving one behind.
            $uploader->deleteFile($stored['path']);
            $uploader->deleteFile($stored['thumbnail_path']);

            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen. Probeer het opnieuw.');
        }

        self::clearCache();

        $item = self::find($id);

        if ($item === null) {
            throw new \RuntimeException('Afbeelding kon niet worden opgeslagen. Probeer het opnieuw.');
        }

        return ['item' => $item, 'reused' => false];
    }

    /**
     * Changes the one piece of metadata an editor owns. The path, the size
     * and the dimensions describe the file on disk and are not editable:
     * letting somebody type them would make the row lie.
     */
    public function updateAltText(int $id, string $altText): bool
    {
        if (self::find($id) === null) {
            return false;
        }

        $this->repository->updateMetadata($id, trim($altText));
        self::clearCache();

        return true;
    }

    /**
     * Where an item is used, grouped for display.
     *
     * @return list<MediaUsage>
     */
    public function usagesOf(int $id): array
    {
        return MediaUsageRegistry::usagesFor([$id])[$id] ?? [];
    }

    /**
     * Usage counts for a whole page of the listing, in a bounded number of
     * queries. This is the method the admin grid calls; there is no per-item
     * variant of it for exactly that reason.
     *
     * @param list<MediaItem> $items
     *
     * @return array<int, int>
     */
    public function usageCountsFor(array $items): array
    {
        return MediaUsageRegistry::countsFor(array_map(
            static fn (MediaItem $item): int => $item->id,
            $items
        ));
    }

    /**
     * Deletes a media item, its file and its own thumbnail — but only when
     * nothing uses it.
     *
     * THE ORDER MATTERS. Usage is established first, the database row goes
     * second, and the files last. A crash between the row and the files
     * leaves an unreferenced file on disk, which is invisible and harmless;
     * the other order would leave a row pointing at nothing, which is a
     * broken image on a public page. A file that could not be removed is
     * reported rather than swallowed, so an editor is never told a file is
     * gone when it is not.
     *
     * A file that is NOT the library's own — an adopted legacy image still
     * living in assets/images/ — is deliberately left on disk. The library
     * took over that file's identity, not its ownership; some other part of
     * this site may still reference it by path, and deleting it because a
     * media row was removed is exactly the kind of surprise Part K of the
     * brief rules out.
     *
     * @return array{deleted: bool, reason: string, usages: list<MediaUsage>, file_removed: bool, warning: string|null}
     */
    public function delete(int $id): array
    {
        $item = self::find($id);

        if ($item === null) {
            return ['deleted' => false, 'reason' => 'not_found', 'usages' => [], 'file_removed' => false, 'warning' => null];
        }

        // Strict: a provider that cannot answer must block the delete, never
        // be rounded down to "nothing uses it".
        $usages = MediaUsageRegistry::usagesForStrict([$id])[$id] ?? [];

        if ($usages !== []) {
            return ['deleted' => false, 'reason' => 'in_use', 'usages' => $usages, 'file_removed' => false, 'warning' => null];
        }

        try {
            $removed = $this->repository->delete($id);
        } catch (\Throwable $e) {
            // A foreign key refused it: something references this row that no
            // provider knew about. The right outcome is a refusal.
            error_log('[MediaService] delete(' . $id . '): ' . $e->getMessage());

            return ['deleted' => false, 'reason' => 'in_use', 'usages' => $usages, 'file_removed' => false, 'warning' => null];
        }

        if (!$removed) {
            return ['deleted' => false, 'reason' => 'not_found', 'usages' => [], 'file_removed' => false, 'warning' => null];
        }

        self::clearCache();

        $uploader = $this->uploader;
        $warning = null;
        $fileRemoved = false;

        if (MediaUploader::ownsPath($item->path)) {
            $fileRemoved = $uploader->deleteFile($item->path);

            if (!$fileRemoved && $item->fileExists()) {
                $warning = 'De databaseverwijzing is verwijderd, maar het bestand zelf kon niet worden gewist: ' . $item->path;
                error_log('[MediaService] could not unlink ' . $item->path);
            }
        }

        if ($item->thumbnailPath !== null) {
            $uploader->deleteFile($item->thumbnailPath);
        }

        return ['deleted' => true, 'reason' => 'ok', 'usages' => [], 'file_removed' => $fileRemoved, 'warning' => $warning];
    }

    /** Total number of items, for the admin header line. */
    public function total(): int
    {
        return $this->repository->countAll();
    }
}

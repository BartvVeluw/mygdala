<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Repository\MediaFolderRepository;
use App\Repository\MediaRepository;
use App\Service\Language\AdminTranslator;

/**
 * The virtual folders of the Media Library (MEDIA.md, "Mappen"): list them,
 * make one, rename one, delete one without deleting what is in it, and file
 * items under one.
 *
 * VIRTUAL, ALL THE WAY DOWN. A folder is a row with a name; an item's folder
 * is media.folder_id. Moving an item changes that one column: its file, its
 * thumbnail, its public URL and every media_id that points at it stay exactly
 * what they were. A folder name never becomes a path, a directory or part of
 * a URL, so there is nothing to sanitise it into — it is text, escaped where
 * it is printed like every other name.
 *
 * ONE LEVEL. A folder holds media, never another folder. A tree would bring
 * a depth limit, cycle checks, moving folders and deleting a branch; the
 * library needs none of that to be tidy, and a parent_id can be added later
 * without changing what exists (MEDIA.md, "Waarom geen submappen").
 *
 * Every write here is media.manage; the endpoints check that before they get
 * here. Uploading INTO a folder is part of adding a file (media.view) and
 * only uses existing() to check the id.
 */
final class MediaFolderService
{
    /** The longest folder name: the column's own length. */
    public const NAME_MAX_LENGTH = 100;

    /** The most items one move() files at once, the same ceiling as a delete. */
    public const MAX_MOVE_AT_ONCE = MediaService::MAX_DELETE_AT_ONCE;

    /** The filter value for the items that have no folder. */
    public const NONE = 'none';

    private MediaFolderRepository $folders;
    private MediaRepository $media;

    public function __construct(?MediaFolderRepository $folders = null, ?MediaRepository $media = null)
    {
        $this->folders = $folders ?? new MediaFolderRepository();
        $this->media = $media ?? new MediaRepository();
    }

    /**
     * Every folder by name with its item count, and how many items have no
     * folder: what the folder list beside the grid and in the picker shows.
     * Two queries, whatever the number of folders.
     *
     * @return array{folders: list<array{id: int, name: string, item_count: int}>, without_folder: int}
     */
    public function overview(): array
    {
        return [
            'folders' => $this->folders->allWithCounts(),
            'without_folder' => $this->folders->countWithoutFolder(),
        ];
    }

    /**
     * A folder filter from a request: '' (every folder), NONE, or the id of
     * a folder that exists, in digits. Anything else — a word, a negative
     * number, the id of a folder that was deleted — filters nothing, the way
     * an unknown kind does, so an old address shows the library rather than
     * an empty screen.
     */
    public function filter(string $requested): string
    {
        $requested = trim($requested);

        if ($requested === self::NONE) {
            return self::NONE;
        }

        return $this->existing($requested) !== null ? (string) (int) $requested : '';
    }

    /**
     * The id of an existing folder, or null: for a value from a request that
     * should name one (the folder an upload goes into).
     */
    public function existing(mixed $requested): ?int
    {
        $id = filter_var($requested, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            return null;
        }

        return $this->folders->findById($id) !== null ? $id : null;
    }

    /** @return array{id: int, name: string}|null */
    public function find(int $id): ?array
    {
        return $id > 0 ? $this->folders->findById($id) : null;
    }

    /**
     * @return array{ok: bool, error: string|null, id: int|null}
     */
    public function create(string $name): array
    {
        $name = self::normalise($name);
        $problem = $this->problemWith($name, 0);

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem, 'id' => null];
        }

        return ['ok' => true, 'error' => null, 'id' => $this->folders->create($name)];
    }

    /**
     * @return array{ok: bool, error: string|null, reason: string}
     *         reason is one of ok, not_found, invalid
     */
    public function rename(int $id, string $name): array
    {
        if ($this->find($id) === null) {
            return ['ok' => false, 'error' => AdminTranslator::trans('media.folder.not_found'), 'reason' => 'not_found'];
        }

        $name = self::normalise($name);
        $problem = $this->problemWith($name, $id);

        if ($problem !== null) {
            return ['ok' => false, 'error' => $problem, 'reason' => 'invalid'];
        }

        $this->folders->rename($id, $name);

        return ['ok' => true, 'error' => null, 'reason' => 'ok'];
    }

    /**
     * Deletes a folder and nothing in it: its items go back to "Geen map".
     * No file, no row of media and no reference is removed.
     *
     * @return array{ok: bool, released: int, error: string|null}
     */
    public function delete(int $id): array
    {
        if ($this->find($id) === null) {
            return ['ok' => false, 'released' => 0, 'error' => AdminTranslator::trans('media.folder.not_found')];
        }

        return ['ok' => true, 'released' => $this->folders->deleteKeepingItems($id), 'error' => null];
    }

    /**
     * Files a selection under a folder, or under none ($target NONE). A
     * folder that does not exist is refused, never created and never read as
     * "no folder": a crafted or stale id changes nothing.
     *
     * @param list<int|string> $ids
     * @return array{ok: bool, moved: int, error: string|null}
     */
    public function move(array $ids, string $target): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'default' => 0]]), $ids),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return ['ok' => false, 'moved' => 0, 'error' => AdminTranslator::trans('media.folder.move_nothing')];
        }

        if (count($ids) > self::MAX_MOVE_AT_ONCE) {
            return ['ok' => false, 'moved' => 0, 'error' => AdminTranslator::trans('media.folder.move_too_many', ['max' => self::MAX_MOVE_AT_ONCE])];
        }

        $folderId = null;

        if ($target !== self::NONE) {
            $folderId = $this->existing($target);

            if ($folderId === null) {
                return ['ok' => false, 'moved' => 0, 'error' => AdminTranslator::trans('media.folder.not_found')];
            }
        }

        $moved = $this->media->moveToFolder($ids, $folderId);
        MediaService::clearCache();

        return ['ok' => true, 'moved' => $moved, 'error' => null];
    }

    /** Surrounding space off, and runs of white space as one: what a person means. */
    private static function normalise(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private function problemWith(string $name, int $exceptId): ?string
    {
        if ($name === '') {
            return AdminTranslator::trans('media.folder.name_empty');
        }

        if (!mb_check_encoding($name, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            return AdminTranslator::trans('media.folder.name_characters');
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return AdminTranslator::trans('media.folder.name_long', ['max' => self::NAME_MAX_LENGTH]);
        }

        if ($this->folders->nameTaken($name, $exceptId)) {
            return AdminTranslator::trans('media.folder.name_taken', ['name' => $name]);
        }

        return null;
    }
}

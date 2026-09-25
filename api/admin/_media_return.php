<?php

declare(strict_types=1);

use App\Service\Media\MediaFolderService;
use App\Service\Media\MediaType;

/**
 * The way back to the library grid after a write on it (deleting or moving a
 * selection, a folder action): rebuilt from the four values the grid posts —
 * return_q, return_type, return_folder, return_page — each checked on its
 * own, never taken from the request as a URL. The same address
 * admin/media.php writes for its own paging and filters.
 *
 * $folderOverride replaces the posted folder: a new folder is opened, a
 * deleted one is left for "Geen map".
 */
function media_return_url(?string $folderOverride = null): string
{
    $folder = $folderOverride ?? (new MediaFolderService())->filter((string) ($_POST['return_folder'] ?? ''));

    $query = array_filter([
        'folder' => $folder,
        'q' => mb_substr(trim((string) ($_POST['return_q'] ?? '')), 0, 200),
        'type' => MediaType::isLibraryFilter((string) ($_POST['return_type'] ?? '')) ? (string) $_POST['return_type'] : '',
        'page' => $folderOverride === null ? max(1, (int) ($_POST['return_page'] ?? 1)) : 1,
    ], static fn (string|int $value): bool => $value !== '' && $value !== 1);

    return '/admin/media.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

<?php

/**
 * POST /api/admin/update-media.php   (media_id, name, alt_text[, ajax])
 *
 * THE one save of a media item's details: its name and its alt text, stored
 * together by App\Service\Media\MediaService::updateDetails(). Two forms send
 * here — the details form on the item screen and the quick edit dialog on the
 * grid (admin/assets/media-library.js) — so both follow exactly one set of
 * rules, and neither has a save of its own (MEDIA.md, "Naam en alt-tekst").
 *
 * Both values are checked together and either both are stored or neither.
 * A refused save goes back to the item screen with every problem named and
 * with what the editor typed still in the fields (a session flash, read once
 * by admin/media.php), never with the stored values put back over it.
 *
 * TWO ANSWERS: a redirect for the form (Post/Redirect/Get, "Opgeslagen" on
 * the item screen), JSON for the dialog, which puts the new name and alt text
 * on the card without reloading the grid. A name is a label: nothing on disk
 * is renamed and no reference follows it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaService;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('media.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$isAjax = ($_POST['ajax'] ?? '') === '1';

$respondJson = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$mediaId = filter_input(INPUT_POST, 'media_id', FILTER_VALIDATE_INT);

if ($mediaId === false || $mediaId === null || $mediaId < 1) {
    http_response_code(400);
    exit('Invalid media id.');
}

$name = (string) ($_POST['name'] ?? '');
$altText = (string) ($_POST['alt_text'] ?? '');

try {
    $result = (new MediaService())->updateDetails($mediaId, $name, $altText);
} catch (\Throwable $e) {
    error_log('[api/admin/update-media.php] ' . $e->getMessage());

    $message = AdminTranslator::trans('media.details.failed');

    if ($isAjax) {
        $respondJson(500, ['ok' => false, 'errors' => ['form' => $message]]);
    }

    $_SESSION['admin_media_errors'] = [$message];
    $_SESSION['admin_media_details_old'] = ['id' => $mediaId, 'name' => $name, 'alt_text' => $altText];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

if ($result['reason'] === 'not_found') {
    if ($isAjax) {
        $respondJson(404, ['ok' => false, 'errors' => ['form' => AdminTranslator::trans('media.rename.not_found')]]);
    }

    http_response_code(404);
    exit('Media not found.');
}

if (!$result['saved']) {
    // The editor's own input, said back with what to change. Nothing was
    // stored, neither the name nor the alt text.
    if ($isAjax) {
        $respondJson(422, ['ok' => false, 'errors' => $result['errors']]);
    }

    $_SESSION['admin_media_errors'] = array_values($result['errors']);
    $_SESSION['admin_media_details_old'] = ['id' => $mediaId, 'name' => $name, 'alt_text' => $altText, 'errors' => $result['errors']];
    header('Location: /admin/media.php?id=' . $mediaId);
    exit;
}

$item = $result['item'];

if ($isAjax) {
    $respondJson(200, [
        'ok' => true,
        'item' => [
            'id' => $item->id,
            'name' => $item->displayName(),
            'alt' => $item->altText,
        ],
    ]);
}

header('Location: /admin/media.php?id=' . $mediaId . '&saved=1');
exit;

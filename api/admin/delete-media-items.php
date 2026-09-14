<?php

/**
 * POST /api/admin/delete-media-items.php   (media_ids[], return_q, return_type, return_page[, ajax])
 *
 * Deletes a selection of media items at once, each one only when nothing uses
 * it. The rule is delete-media.php's, asked for a whole selection:
 * App\Service\Media\MediaService::deleteMany() establishes the usage of every
 * selected item strictly BEFORE anything is removed, deletes what nothing
 * uses, and hands back what it kept and why. There is no "delete anyway", for
 * the reason delete-media.php gives.
 *
 * A PARTIAL RESULT IS THE NORMAL ONE. Four items selected with one still in
 * use is three deleted and one kept with the places that use it — not four
 * refusals. Every item stands on its own; nothing about removing three unused
 * images depends on the fourth.
 *
 * WHERE, ONLY AS FAR AS THE READER MAY OPEN IT. A kept item is reported with
 * the places that use it named only when this administrator may open the
 * screen of that place; the others are counted. What to keep was decided on
 * every usage regardless (App\Service\Media\VisibleMediaUsages).
 *
 * TWO ANSWERS, like rename-media.php: a redirect back to the grid page the
 * selection came from, with a session flash, for the plain form; JSON for the
 * confirmation dialog in admin/assets/media-library.js. The way back is
 * rebuilt from three validated values, never taken from the request as a
 * URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\Media\MediaService;
use App\Service\Media\MediaType;
use App\Service\Media\VisibleMediaUsages;

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

$returnQuery = array_filter([
    'q' => mb_substr(trim((string) ($_POST['return_q'] ?? '')), 0, 200),
    'type' => MediaType::isKnown((string) ($_POST['return_type'] ?? '')) ? (string) $_POST['return_type'] : '',
    'page' => max(1, (int) ($_POST['return_page'] ?? 1)),
], static fn (string|int $value): bool => $value !== '' && $value !== 1);

$returnTo = '/admin/media.php' . ($returnQuery === [] ? '' : '?' . http_build_query($returnQuery));

$fail = static function (int $status, string $message) use ($isAjax, $respondJson, $returnTo): never {
    if ($isAjax) {
        $respondJson($status, ['ok' => false, 'error' => $message]);
    }

    $_SESSION['admin_media_errors'] = [$message];
    header('Location: ' . $returnTo);
    exit;
};

$submitted = $_POST['media_ids'] ?? [];
$ids = [];

foreach (is_array($submitted) ? $submitted : [] as $value) {
    $id = filter_var($value, FILTER_VALIDATE_INT);

    if ($id !== false && $id > 0) {
        $ids[$id] = $id;
    }
}

if ($ids === []) {
    $fail(400, AdminTranslator::trans('media.bulk.nothing_selected'));
}

if (count($ids) > MediaService::MAX_DELETE_AT_ONCE) {
    $fail(400, AdminTranslator::trans('media.bulk.too_many', ['max' => MediaService::MAX_DELETE_AT_ONCE]));
}

try {
    $result = (new MediaService())->deleteMany(array_values($ids));
} catch (\RuntimeException $e) {
    // "Could not establish where these are used" — never rounded down to
    // "nothing uses them". Nothing was deleted.
    $fail(503, $e->getMessage());
} catch (\Throwable $e) {
    error_log('[api/admin/delete-media-items.php] ' . $e->getMessage());

    $fail(500, AdminTranslator::trans('media.bulk.failed'));
}

$deletedCount = count($result['deleted']);
$keptCount = count($result['in_use']);

$summary = [];

if ($deletedCount > 0) {
    $summary[] = $deletedCount === 1
        ? AdminTranslator::trans('media.bulk.deleted_one')
        : AdminTranslator::trans('media.bulk.deleted', ['count' => $deletedCount]);
}

if ($keptCount > 0) {
    $summary[] = $keptCount === 1
        ? AdminTranslator::trans('media.bulk.kept_one')
        : AdminTranslator::trans('media.bulk.kept', ['count' => $keptCount]);
}

if ($result['not_found'] !== []) {
    $summary[] = AdminTranslator::trans('media.bulk.not_found', ['count' => count($result['not_found'])]);
}

// One line per kept item: which file, and where it is still used, so an
// editor can go and unpick it without opening every card. The same sentence
// serves the JSON and the flash, and names only what this administrator may
// open.
$details = array_map(
    static fn (array $kept): string => VisibleMediaUsages::of($kept['usages'], AdminAuth::can(...))
        ->keptSentence($kept['item']->displayName()),
    $result['in_use']
);

if ($isAjax) {
    $respondJson(200, [
        'ok' => true,
        'message' => implode(' ', $summary),
        'details' => array_merge($details, $result['warnings']),
        'deleted' => array_map(static fn ($item): int => $item->id, $result['deleted']),
        'kept' => array_map(static fn (array $kept): int => $kept['item']->id, $result['in_use']),
    ]);
}

if ($deletedCount > 0 || $result['not_found'] !== []) {
    $_SESSION['admin_media_notice'] = implode(' ', $summary);
}

$errors = array_merge($keptCount > 0 && $deletedCount === 0 ? $summary : [], $details, $result['warnings']);

if ($errors !== []) {
    $_SESSION['admin_media_errors'] = $errors;
}

header('Location: ' . $returnTo);
exit;

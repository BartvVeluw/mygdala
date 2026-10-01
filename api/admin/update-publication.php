<?php

/**
 * POST /api/admin/update-publication.php   (type, id, status, published_at)
 *
 * Changes the publication of one record of any kind on the Publishing Engine
 * — a blog post now, an article later — without its owner's whole editor:
 * the endpoint behind a "Publiceren", "Archiveren" or "Terug naar concept"
 * button in an overview (docs/publishing/ARCHITECTURE.md, "Een publicatie wijzigen"). The
 * owner's own editor keeps its own one-form endpoint and runs the same rules
 * inside it (api/admin/update-blog-post.php).
 *
 * The four guards first, in the project's order. The permission guard asks
 * for ANY kind's permission, because which kind is meant is only read after
 * the CSRF check; App\Service\Publishing\PublishingService then demands that
 * kind's OWN permission, the same move ContentBlockAccess makes for blocks.
 *
 *   unknown or disabled type, an id that is not a record of it  -> 404
 *   no permission for this kind                                  -> 403
 *   refused by the rules (status, date, the owner's own rule)    -> back to the editor with the reasons
 *   saved                                                        -> back to the editor, saved
 *
 * The way back is the provider's own adminPath(), never a URL from the
 * request. Nothing a record says is echoed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Publishing\PublishingService;

AdminAuth::requireLoginForApi();
PublishingService::requireAnyForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$result = PublishingService::change(
    $_POST['type'] ?? null,
    $_POST['id'] ?? null,
    $_POST['status'] ?? null,
    $_POST['published_at'] ?? '',
);

if ($result['outcome'] === PublishingService::NOT_FOUND) {
    http_response_code(404);
    exit('Not found');
}

if ($result['outcome'] === PublishingService::FORBIDDEN) {
    http_response_code(403);
    exit('Forbidden');
}

$_SESSION['admin_publishing_flash'] = $result['outcome'] === PublishingService::SAVED
    ? ['type' => 'success', 'messages' => []]
    : ['type' => 'error', 'messages' => $result['errors']];

$back = $result['provider']->adminPath((int) $result['id']);
if ($result['outcome'] === PublishingService::SAVED) {
    $back .= (str_contains($back, '?') ? '&' : '?') . 'updated=1';
}

header('Location: ' . $back);
exit;

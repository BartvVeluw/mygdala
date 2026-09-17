<?php

/**
 * POST /api/admin/delete-carousel-card-tag.php
 *
 * Permanently deletes one tag of a carousel card.
 *
 * The tag's label in every website language goes first, in the same
 * transaction as the row (BlockLocalization::deleteOwner()): there is no
 * foreign key that could take it along, and once the row is gone nothing
 * would find it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\AdminAuth;
use App\Service\Blocks\BlockLocalization;
use App\Service\Csrf;
use App\Service\CardCarouselContent;
use App\Repository\CardCarouselRepository;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('pages.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$tagId = filter_input(INPUT_POST, 'tag_id', FILTER_VALIDATE_INT);
if ($tagId === false || $tagId === null || $tagId < 1) {
    http_response_code(400);
    exit('Invalid tag id.');
}

$repository = new CardCarouselRepository();
$tag = $repository->findTagById($tagId);

if ($tag === null) {
    http_response_code(404);
    exit('Tag not found.');
}

$redirect = '/admin/carousel-card.php?card_id=' . (int) $tag['card_id'];

$db = Database::connection();

try {
    $db->beginTransaction();

    BlockLocalization::deleteOwner('carousel_card_tags', $tagId);
    $repository->deleteTag($tagId);

    $db->commit();
    CardCarouselContent::clearCache();
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/delete-carousel-card-tag.php] ' . $e->getMessage());
    $_SESSION['admin_carousel_card_tag_errors'] = ['Tag kon niet worden verwijderd.'];
    header('Location: ' . $redirect);
    exit;
}

header('Location: ' . $redirect . '&saved=1');
exit;

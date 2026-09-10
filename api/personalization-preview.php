<?php

/**
 * POST /api/personalization-preview.php   (multipart/form-data)
 *
 * Accepts the COMPOSED preview of ONE personalization view — the picture of
 * the finished product exactly as the customer saw it, rasterised by their
 * own browser — validates it completely server-side, stores it beside the
 * customer's uploads outside the webroot, and answers with the random token
 * the cart carries from then on.
 *
 * Fields:
 *   product_id  the product being personalized
 *   view_key    which view (front, back, ...) this picture is of
 *   file        the PNG (see PersonalizationPreviewComposer)
 *
 * Response (200): { "token": "…32 hex…", "view_key": "voorkant" }
 * Response (4xx/5xx): { "error": "…" }
 *
 * ## Why the browser sends a raster at all
 *
 * The structured personalization is and stays the source of truth, and the
 * CMS still reconstructs the preview from it. What it cannot reproduce is the
 * customer's exact typography: the text is set in a webfont the browser
 * downloaded, and GD can only draw with a local TTF/OTF. So the browser sends
 * what it actually rendered, and this endpoint makes sure what lands on disk
 * is a real image and nothing else — decoded and re-encoded through GD, so
 * the stored bytes are pixels this server produced.
 *
 * A snapshot is SUPPLEMENTARY throughout: it never replaces the customer's
 * own upload, an order without one is complete, and a failure here is
 * deliberately not fatal to the customer's order — the browser simply
 * continues without a token.
 *
 * ## Why this endpoint has no CSRF token
 *
 * Same reasoning as api/personalization-upload.php: an anonymous public
 * endpoint with no session and no side effect on anybody's account. What it
 * has instead is the same abuse ceiling, a strict content check, and a sweep
 * that removes anything never ordered.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('personalization');


use App\Database;
use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Repository\ProductRepository;
use App\Service\ContactRateLimiter;
use App\Service\Personalization\PersonalizationPreviewComposer;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationUploadStorage;
use App\Service\Personalization\ProductPersonalizationContent;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

/** A composed preview is posted once per view per add-to-cart, so this is generous. */
const PERSONALIZATION_PREVIEW_MAX_ATTEMPTS = 60;
const PERSONALIZATION_PREVIEW_WINDOW_SECONDS = 600;

/** 1-in-N chance that a successful post also sweeps abandoned drafts. */
const PERSONALIZATION_PREVIEW_CLEANUP_CHANCE = 20;

function personalizationPreviewFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    personalizationPreviewFail(405, 'Method not allowed');
}

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    personalizationPreviewFail(400, 'Ongeldig product.');
}

$viewKey = $_POST['view_key'] ?? '';
$viewKey = is_string($viewKey) ? trim($viewKey) : '';

if (!PersonalizationRules::isValidKey($viewKey)) {
    personalizationPreviewFail(400, 'Ongeldige weergave.');
}

try {
    $db = Database::connection();

    $limiter = new ContactRateLimiter(
        $db,
        ContactRateLimiter::PERSONALIZATION_UPLOAD_SALT,
        PERSONALIZATION_PREVIEW_MAX_ATTEMPTS,
        PERSONALIZATION_PREVIEW_WINDOW_SECONDS
    );

    if (!$limiter->allow((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        personalizationPreviewFail(429, 'Te veel verzoeken achter elkaar. Probeer het over een paar minuten opnieuw.');
    }

    if ((new ProductRepository($db))->findActiveById($productId) === null) {
        personalizationPreviewFail(404, 'Dit product is niet beschikbaar.');
    }

    $config = ProductPersonalizationContent::forProduct($productId);

    if ($config === null) {
        personalizationPreviewFail(422, 'Dit product kan niet worden gepersonaliseerd.');
    }

    // The view must be one this product really has, so a token can never be
    // presented later as a side of the product that does not exist.
    $viewExists = false;
    foreach ($config['views'] as $view) {
        if ($view['view_key'] === $viewKey) {
            $viewExists = true;
            break;
        }
    }

    if (!$viewExists) {
        personalizationPreviewFail(422, 'Onbekende weergave voor dit product.');
    }
} catch (\Throwable $e) {
    error_log('[api/personalization-preview.php] ' . $e->getMessage());
    personalizationPreviewFail(500, 'Het voorbeeld kon niet worden opgeslagen. Probeer het later opnieuw.');
}

try {
    $validated = (new PersonalizationPreviewComposer())->validate($_FILES['file'] ?? null);
} catch (\RuntimeException $e) {
    personalizationPreviewFail(422, $e->getMessage());
}

$token = PersonalizationRules::newUploadToken();
$storage = new PersonalizationUploadStorage();
$storedFilename = null;

try {
    $storedFilename = $storage->storeComposed($token, $validated['bytes']);

    (new PersonalizationPreviewSnapshotRepository($db))->create([
        'token' => $token,
        'product_id' => $productId,
        'view_key' => $viewKey,
        'stored_filename' => $storedFilename,
        'image_width' => $validated['width'],
        'image_height' => $validated['height'],
        'byte_size' => strlen($validated['bytes']),
    ]);
} catch (\Throwable $e) {
    // Never leave a file behind that no database row points at.
    if ($storedFilename !== null) {
        $storage->delete($storedFilename);
    }

    error_log('[api/personalization-preview.php] ' . $e->getMessage());
    personalizationPreviewFail(500, 'Het voorbeeld kon niet worden opgeslagen.');
}

/**
 * Opportunistic cleanup of abandoned drafts, exactly as
 * api/personalization-upload.php sweeps abandoned uploads: a small bounded
 * batch on roughly one in N successful posts, never a cron job, and a CLAIMED
 * snapshot is untouchable both in the query and again in the delete.
 */
try {
    if (random_int(1, PERSONALIZATION_PREVIEW_CLEANUP_CHANCE) === 1) {
        $snapshots = new PersonalizationPreviewSnapshotRepository($db);
        foreach ($snapshots->findExpiredUnclaimed(PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS) as $expired) {
            if ($snapshots->deleteUnclaimed((int) $expired['id'])) {
                $storage->delete((string) $expired['stored_filename']);
            }
        }
    }
} catch (\Throwable $e) {
    error_log('[api/personalization-preview.php] cleanup: ' . $e->getMessage());
}

echo json_encode(['token' => $token, 'view_key' => $viewKey]);

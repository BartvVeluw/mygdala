<?php

/**
 * POST /api/personalization-upload.php   (multipart/form-data)
 *
 * Accepts ONE customer personalization image for ONE product, validates it
 * completely server-side, stores it outside the webroot and answers with the
 * random token the browser uses from then on. The browser never learns a
 * filename, a path or a database id.
 *
 * Fields:
 *   product_id  the product being personalized (must be active, must have
 *               personalization enabled, and its zone must allow images)
 *   zone_key    optional; defaults to the single Version 1 zone
 *   file        the image (PNG or JPG only — see PersonalizationUploadValidator)
 *
 * Response (200):
 *   { "token": "…32 hex…", "preview_url": "/api/personalization-image.php?token=…",
 *     "original_filename": "logo.png", "width": 1200, "height": 800 }
 * Response (4xx/5xx): { "error": "…" }
 *
 * ## Why this endpoint has no CSRF token
 *
 * It is an anonymous public endpoint with no session and no side effect on
 * anybody's account: the only thing a forged cross-site request could achieve
 * is uploading a file as the visitor themselves, which they can do here
 * anyway. What it does need — and has — is an abuse ceiling
 * (App\Service\ContactRateLimiter, with its own salt and its own limits) and
 * a strict content check, because it writes files to disk for anonymous
 * callers. A stored file that is never used by an order is disposable and is
 * swept away automatically; see the cleanup below.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('personalization');


use App\Database;
use App\Repository\PersonalizationUploadRepository;
use App\Repository\ProductRepository;
use App\Service\ContactRateLimiter;
use App\Service\Personalization\PersonalizationRules;
use App\Service\Personalization\PersonalizationUploadStorage;
use App\Service\Personalization\PersonalizationUploadValidator;
use App\Service\Personalization\ProductPersonalizationContent;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

/** Uploads per visitor per window — generous for real use, a ceiling for a script. */
const PERSONALIZATION_UPLOAD_MAX_ATTEMPTS = 30;
const PERSONALIZATION_UPLOAD_WINDOW_SECONDS = 600;

/** 1-in-N chance that a successful upload also sweeps abandoned uploads. */
const PERSONALIZATION_CLEANUP_CHANCE = 20;

function personalizationUploadFail(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    personalizationUploadFail(405, 'Method not allowed');
}

$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    personalizationUploadFail(400, 'Ongeldig product.');
}

$zoneKey = $_POST['zone_key'] ?? PersonalizationRules::DEFAULT_ZONE_KEY;
$zoneKey = is_string($zoneKey) && $zoneKey !== '' ? $zoneKey : PersonalizationRules::DEFAULT_ZONE_KEY;

try {
    $db = Database::connection();

    $limiter = new ContactRateLimiter(
        $db,
        ContactRateLimiter::PERSONALIZATION_UPLOAD_SALT,
        PERSONALIZATION_UPLOAD_MAX_ATTEMPTS,
        PERSONALIZATION_UPLOAD_WINDOW_SECONDS
    );

    if (!$limiter->allow((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        personalizationUploadFail(429, 'Te veel uploads achter elkaar. Probeer het over een paar minuten opnieuw.');
    }

    // The product must be a real, purchasable product — not merely a row.
    if ((new ProductRepository($db))->findActiveById($productId) === null) {
        personalizationUploadFail(404, 'Dit product is niet beschikbaar.');
    }

    $config = ProductPersonalizationContent::forProduct($productId);

    if ($config === null) {
        personalizationUploadFail(422, 'Dit product kan niet worden gepersonaliseerd.');
    }

    // A product can now have several zones across several views, so the
    // upload is checked against the ONE zone it claims to be for: uploading
    // into a text-only zone is refused even when another zone on the same
    // product does accept images.
    $located = ProductPersonalizationContent::locateZone($config, $zoneKey);

    if ($located === null || !$located['zone']['allow_image']) {
        personalizationUploadFail(422, 'Voor dit onderdeel kun je geen afbeelding uploaden.');
    }
} catch (\Throwable $e) {
    // personalizationUploadFail() exits rather than throwing, so nothing
    // above can reach this except a genuine database/infrastructure failure.
    error_log('[api/personalization-upload.php] ' . $e->getMessage());
    personalizationUploadFail(500, 'Uploaden lukt op dit moment niet. Probeer het later opnieuw.');
}

$file = $_FILES['file'] ?? null;

if (!is_array($file)) {
    personalizationUploadFail(400, 'Geen bestand ontvangen.');
}

try {
    $validated = (new PersonalizationUploadValidator())->validate($file);
} catch (\RuntimeException $e) {
    // Customer-facing, already written for a customer (Dutch) and free of any
    // technical detail — see PersonalizationUploadValidator.
    personalizationUploadFail(422, $e->getMessage());
}

$token = PersonalizationRules::newUploadToken();
$storage = new PersonalizationUploadStorage();
$stored = null;

try {
    $stored = $storage->store($token, $validated['tmp_path'], $validated['extension'], $validated['preview_bytes']);

    $uploads = new PersonalizationUploadRepository($db);
    $uploads->create([
        'token' => $token,
        'product_id' => $productId,
        'stored_filename' => $stored['stored_filename'],
        'preview_filename' => $stored['preview_filename'],
        'original_filename' => $validated['original_filename'],
        'mime_type' => $validated['mime'],
        'image_width' => $validated['width'],
        'image_height' => $validated['height'],
        'byte_size' => $validated['size'],
    ]);
} catch (\Throwable $e) {
    // Never leave a file behind that no database row points at.
    if ($stored !== null) {
        $storage->delete($stored['stored_filename']);
        $storage->delete($stored['preview_filename']);
    }

    error_log('[api/personalization-upload.php] ' . $e->getMessage());
    personalizationUploadFail(500, 'De afbeelding kon niet worden opgeslagen. Probeer het opnieuw.');
}

/**
 * Opportunistic cleanup of ABANDONED uploads: files a visitor uploaded and
 * then never ordered. Deliberately not a cron job — this project only has one
 * scheduled task (the PostNL rate sync) and a feature should not require a
 * second one to stay tidy. A small, bounded batch runs on roughly one in
 * PERSONALIZATION_CLEANUP_CHANCE successful uploads, so the work is spread
 * out and never delays a customer noticeably. A CLAIMED upload — one an order
 * points at — is never touched, in the query and again in the delete.
 */
try {
    if (random_int(1, PERSONALIZATION_CLEANUP_CHANCE) === 1) {
        $uploads = new PersonalizationUploadRepository($db);
        foreach ($uploads->findExpiredUnclaimed(PersonalizationRules::UNCLAIMED_UPLOAD_TTL_HOURS) as $expired) {
            if ($uploads->deleteUnclaimed($expired['id'])) {
                $storage->delete($expired['stored_filename']);
                $storage->delete($expired['preview_filename']);
            }
        }
    }
} catch (\Throwable $e) {
    // Housekeeping must never break a successful upload.
    error_log('[api/personalization-upload.php] cleanup: ' . $e->getMessage());
}

echo json_encode([
    'token' => $token,
    'preview_url' => '/api/personalization-image.php?token=' . $token,
    'original_filename' => $validated['original_filename'],
    'width' => $validated['width'],
    'height' => $validated['height'],
]);

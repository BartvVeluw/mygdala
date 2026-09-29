<?php

/**
 * POST /api/order-field-upload.php   (multipart/form-data)
 *
 * The customer's picture for an "Afbeelding uploaden" order question (Shop
 * Admin UX & Order Fields 2.0, App\Service\OrderFields\OrderFieldUploads,
 * MODULES.md "Bestelvelden"). The product page sends it the moment the
 * picture is chosen, so a cart line only ever gets a picture that arrived
 * and passed every check.
 *
 * Upload:
 *   action=upload, product_id, field_id, language, file
 *   200 { "token": "…64 hex…", "filename": "luna.jpg", "width": 3024, "height": 4032, "size": 2310044 }
 *   4xx { "error": "…", "reason": "too_large" | "type" | … }   a sentence for the customer
 *
 * Remove (the customer replaced or removed the picture on the page):
 *   action=discard, token
 *   200 { "ok": true }   also when there was nothing to remove: the answer
 *                        never tells whether a token existed
 *
 * The token is the browser's only key to the picture. It is never stored in
 * the clear (the database keeps its SHA-256), never shown in a URL, and
 * there is no public route that serves the picture back: the page shows its
 * own local preview (URL.createObjectURL) and only the CMS reads the file.
 *
 * WHY NO CSRF TOKEN: like the other public shop endpoints (cart check,
 * checkout, the personalization upload) this is anonymous and sessionless.
 * A forged cross-site request could only upload a picture as the visitor
 * themselves, or discard a picture whose 256-bit token it would have to know
 * already. What an anonymous endpoint that writes files needs — and has — is
 * an abuse ceiling (App\Service\ContactRateLimiter, its own salt, an IPv6
 * visitor counted per /64), a ceiling on all temporary pictures together
 * (OrderFieldUploadPolicy::MAX_TEMPORARY_BYTES) and a strict content check.
 * Every upload also gives expired pictures a small chance to be swept
 * (OrderFieldUploads::sweep()).
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');

use App\Database;
use App\Service\ContactRateLimiter;
use App\Service\Language\SiteText;
use App\Service\OrderFields\OrderFieldUploadException;
use App\Service\OrderFields\OrderFieldUploads;
use App\Service\Routing\ApiLanguage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

/** Uploads per visitor per ten minutes: room for real retries, a ceiling for a script. */
const ORDER_FIELD_UPLOAD_MAX_ATTEMPTS = 30;
const ORDER_FIELD_UPLOAD_WINDOW_SECONDS = 600;

function orderFieldUploadAnswer(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    orderFieldUploadAnswer(405, ['error' => 'Method not allowed']);
}

$language = ApiLanguage::apply($_POST['language'] ?? null);

// A body larger than post_max_size arrives as an EMPTY $_POST and $_FILES:
// say "too large" rather than "no file".
if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    orderFieldUploadAnswer(413, [
        'error' => SiteText::pick(['nl' => 'Deze afbeelding is te groot.', 'en' => 'This picture is too large.'], $language),
        'reason' => 'too_large',
    ]);
}

$action = $_POST['action'] ?? 'upload';

try {
    $uploads = new OrderFieldUploads(Database::connection());

    if ($action === 'discard') {
        $uploads->discard($_POST['token'] ?? null);
        orderFieldUploadAnswer(200, ['ok' => true]);
    }

    if ($action !== 'upload') {
        orderFieldUploadAnswer(400, ['error' => 'Unknown action.']);
    }

    $limiter = new ContactRateLimiter(
        Database::connection(),
        ContactRateLimiter::ORDER_FIELD_UPLOAD_SALT,
        ORDER_FIELD_UPLOAD_MAX_ATTEMPTS,
        ORDER_FIELD_UPLOAD_WINDOW_SECONDS
    );
    // An IPv6 visitor counts per /64: one connection gets a whole /64 and can
    // pick a new address in it for every request.
    $visitor = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $packed = @inet_pton($visitor);
    if (is_string($packed) && strlen($packed) === 16) {
        $visitor = (string) inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    if (!$limiter->allow($visitor)) {
        orderFieldUploadAnswer(429, [
            'error' => SiteText::pick([
                'nl' => 'Te veel uploads achter elkaar. Probeer het over een paar minuten opnieuw.',
                'en' => 'Too many uploads in a row. Please try again in a few minutes.',
            ], $language),
            'reason' => 'rate_limited',
        ]);
    }

    $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $fieldId = filter_var($_POST['field_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId === false || $fieldId === false) {
        orderFieldUploadAnswer(400, ['error' => 'Invalid product or question.', 'reason' => 'question']);
    }

    $stored = $uploads->upload($productId, $fieldId, $_FILES['file'] ?? null, $language);
} catch (OrderFieldUploadException $e) {
    orderFieldUploadAnswer($e->status, ['error' => $e->getMessage(), 'reason' => $e->kind]);
} catch (\Throwable $e) {
    error_log('[api/order-field-upload.php] ' . $e->getMessage());
    orderFieldUploadAnswer(500, [
        'error' => SiteText::pick(['nl' => 'Het uploaden is mislukt. Probeer het opnieuw.', 'en' => 'The upload failed. Please try again.'], $language),
        'reason' => 'failed',
    ]);
}

// Housekeeping never breaks a successful upload.
try {
    if (random_int(1, OrderFieldUploads::SWEEP_CHANCE) === 1) {
        $uploads->sweep();
    }
} catch (\Throwable $e) {
    error_log('[api/order-field-upload.php] sweep: ' . $e->getMessage());
}

orderFieldUploadAnswer(200, [
    'token' => $stored['token'],
    'filename' => $stored['original_filename'],
    'width' => $stored['width'],
    'height' => $stored['height'],
    'size' => $stored['byte_size'],
]);

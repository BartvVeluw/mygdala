<?php

/**
 * POST /api/stock-notification.php
 *
 * "Mail me when this is available again" (Shop Product & Ordering 2.0,
 * App\Service\Inventory\StockNotifications, MODULES.md "Terug op voorraad"):
 * one transactional mail, for exactly the unit the visitor looked at — the
 * product, or the variant they chose. No account, no newsletter.
 *
 * Request body (JSON):
 *   { "product_id": 3, "variant_id": 7 | null, "email": "…", "language": "nl",
 *     "hp-note": "" }                        (the honeypot: a person leaves it empty)
 *
 * Responses:
 *   200 { "ok": true, "available": false }   stored — or the address already
 *                                            waits for this unit: the same
 *                                            answer, so nobody can find out
 *                                            which addresses are known
 *   200 { "ok": true, "available": true }    it can be ordered right now;
 *                                            nothing stored
 *   422 { "error": "…" }                     not an e-mail address, or no such
 *                                            unit for sale
 *   429 { "error": "…" }                     too many requests from this visitor
 *
 * The language only picks the words of the mail (and of this answer), never
 * a product or an address. A filled honeypot gets the ordinary success answer
 * and nothing is stored.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
// This endpoint belongs to a module. Refused before anything is read,
// validated or written when that module is switched off — see
// App\Module\ModuleGuard.
\App\Module\ModuleGuard::requireApi('shop');

use App\Database;
use App\Service\ContactRateLimiter;
use App\Service\Inventory\StockNotifications;
use App\Service\Language\SiteText;
use App\Service\Routing\ApiLanguage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

/** Twenty requests per visitor per ten minutes: plenty for a person, a ceiling for a script. */
const STOCK_NOTIFICATION_MAX_ATTEMPTS = 20;
const STOCK_NOTIFICATION_WINDOW_SECONDS = 600;

function answer(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    answer(400, ['error' => 'Invalid request body.']);
}

$language = ApiLanguage::apply($body['language'] ?? null);

try {
    $limiter = new ContactRateLimiter(
        Database::connection(),
        ContactRateLimiter::STOCK_NOTIFICATION_SALT,
        STOCK_NOTIFICATION_MAX_ATTEMPTS,
        STOCK_NOTIFICATION_WINDOW_SECONDS
    );
    if (!$limiter->allow((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        answer(429, ['error' => SiteText::pick(['nl' => 'Te veel verzoeken. Probeer het later opnieuw.', 'en' => 'Too many requests. Please try again later.'])]);
    }
} catch (\Throwable $e) {
    error_log('[api/stock-notification.php] rate limit: ' . $e->getMessage());
    answer(503, ['error' => SiteText::pick(['nl' => 'Dit lukt nu even niet. Probeer het later opnieuw.', 'en' => 'This is not possible right now. Please try again later.'])]);
}

// A filled honeypot is a script: it gets the ordinary answer and nothing else.
if (trim((string) ($body['hp-note'] ?? '')) !== '') {
    answer(200, ['ok' => true, 'available' => false]);
}

$productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT);
$variantId = ($body['variant_id'] ?? null) === null ? null : filter_var($body['variant_id'], FILTER_VALIDATE_INT);
$email = StockNotifications::normaliseEmail($body['email'] ?? null);

if ($email === null) {
    answer(422, ['error' => SiteText::pick(['nl' => 'Vul een geldig e-mailadres in.', 'en' => 'Please enter a valid email address.'])]);
}

$unavailable = SiteText::pick(['nl' => 'Voor dit product kun je geen melding aanvragen.', 'en' => 'You cannot ask for a notification for this product.']);
if ($productId === false || $productId < 1 || $variantId === false || ($variantId !== null && $variantId < 1)) {
    answer(422, ['error' => $unavailable]);
}

try {
    $result = (new StockNotifications())->subscribe($productId, $variantId, $email, $language);
} catch (\Throwable $e) {
    error_log('[api/stock-notification.php] ' . $e->getMessage());
    answer(500, ['error' => SiteText::pick(['nl' => 'Dit lukt nu even niet. Probeer het later opnieuw.', 'en' => 'This is not possible right now. Please try again later.'])]);
}

if ($result === StockNotifications::UNAVAILABLE) {
    answer(422, ['error' => $unavailable]);
}

answer(200, ['ok' => true, 'available' => $result === StockNotifications::AVAILABLE]);

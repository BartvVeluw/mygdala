<?php

/**
 * POST /api/admin/create-footer-column.php — admin/footer.php's
 * "+ Kolom toevoegen" inline form.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\LocalizedValue;
use App\Repository\FooterRepository;

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

$titleNl = trim((string) ($_POST['title_nl'] ?? ''));
$titleEn = trim((string) ($_POST['title_en'] ?? ''));

// Only the SITE'S OWN language is required. The other one is a translation,
// and a translation is optional by definition: App\Service\Language\LocalizedValue
// falls back to the primary language wherever one is missing. Requiring both
// was harmless while every editor printed both fields; now that a
// single-language site shows one, it would make this form impossible to
// submit at all (MULTILINGUAL.md).
if (LocalizedValue::ofDutchEnglish($titleNl, $titleEn)->primaryValue() === ''
    || mb_strlen($titleNl) > 100
    || mb_strlen($titleEn) > 100
) {
    $_SESSION['admin_footer_error'] = 'Titel is verplicht (max. 100 tekens).';
    header('Location: /admin/footer.php');
    exit;
}

try {
    (new FooterRepository())->createColumn(['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => true]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-footer-column.php] ' . $e->getMessage());
    $_SESSION['admin_footer_error'] = 'Kolom kon niet worden aangemaakt.';
    header('Location: /admin/footer.php');
    exit;
}

header('Location: /admin/footer.php?saved=1');
exit;

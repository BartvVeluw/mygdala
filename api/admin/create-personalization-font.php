<?php

/**
 * POST /api/admin/create-personalization-font.php
 *
 * Adds one uploaded font to the GLOBAL engraving font library.
 *
 * The file is validated by App\Service\Personalization\PersonalizationFontUploader
 * — allowed extension, real signature, size cap — and stored under a
 * server-generated filename in assets/fonts/personalization/. Nothing the
 * browser sent is ever used to build a path; the original filename survives
 * only as display metadata on the row.
 *
 * `font_key` is derived from the administrator's label and made unique here,
 * because it is the identifier a placed order records for the rest of time.
 * It is never editable afterwards for the same reason.
 *
 * A new font starts ACTIVE: the administrator uploaded it in order to use it,
 * and the row can be switched off in one click on the same screen.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PersonalizationFontRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationFontUploader;
use App\Service\Personalization\PersonalizationRules;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('personalization.manage');

/**
 * Back to the font library, always a literal local path.
 *
 * @param list<string> $errors
 * @param array<string, mixed> $old
 */
function fontFail(array $errors, array $old = []): never
{
    $_SESSION['admin_font_errors'] = $errors;
    $_SESSION['admin_font_old'] = $old;

    header('Location: /admin/personalization-fonts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$label = trim((string) ($_POST['label'] ?? ''));
$errors = [];

if ($label === '') {
    $errors[] = 'Geef het lettertype een naam.';
} elseif (mb_strlen($label) > 100) {
    $errors[] = 'De naam van een lettertype mag maximaal 100 tekens zijn.';
}

$uploader = new PersonalizationFontUploader();
$stored = null;

if ($errors === []) {
    try {
        $stored = $uploader->store($_FILES['font_file'] ?? []);
    } catch (\RuntimeException $e) {
        $errors[] = $e->getMessage();
    }
}

if ($errors !== []) {
    if ($stored !== null) {
        $uploader->delete($stored['file_path']);
    }

    fontFail($errors, ['label' => $label]);
}

try {
    $repository = new PersonalizationFontRepository();

    // The key is derived from the label, then made unique. A collision is
    // resolved with a numeric suffix rather than rejected: two fonts can
    // legitimately be called "Script", and the administrator should not have
    // to invent an identifier they never see.
    $base = PersonalizationRules::toKey($label, 'font');
    $key = $base;
    $suffix = 2;

    while ($repository->keyExists($key)) {
        $key = substr($base, 0, 28) . '_' . $suffix;
        $suffix++;
    }

    $repository->create([
        'font_key' => $key,
        'label' => $label,
        'source' => 'upload',
        'css_stack' => null,
        'file_path' => $stored['file_path'],
        'file_format' => $stored['file_format'],
        'original_filename' => $stored['original_filename'],
        'byte_size' => $stored['byte_size'],
        'is_active' => true,
    ]);

    PersonalizationFonts::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/create-personalization-font.php] ' . $e->getMessage());
    $uploader->delete($stored['file_path']);
    fontFail(['Het lettertype kon niet worden opgeslagen. Probeer het opnieuw.'], ['label' => $label]);
}

header('Location: /admin/personalization-fonts.php?created=1');
exit;

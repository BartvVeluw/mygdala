<?php

/**
 * POST /api/admin/update-contact-card.php
 *
 * Saves one Contactkaart block
 * (admin/contact-card.php?section=<page>:<key>). Same guard order and
 * PRG/session-flash pattern as api/admin/update-rich-text-section.php, and
 * the same "a page-builder-attached section is valid only when the page AND
 * its content row already exist" gate — an arbitrary page_slug:section_key
 * pair from the request is never trusted beyond that.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\ContactCardContent;
use App\Service\Csrf;
use App\Repository\PageRepository;
use App\Repository\ContactCardRepository;

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

$sectionParam = (string) ($_POST['section'] ?? '');
[$pageSlug, $sectionKey] = array_pad(explode(':', $sectionParam, 2), 2, null);

$repository = new ContactCardRepository();

if ($pageSlug === null || $pageSlug === '' || $sectionKey === null || $sectionKey === ''
    || (new PageRepository())->findByContentKey($pageSlug) === null
    || $repository->findBySlugAndKey($pageSlug, $sectionKey) === null
) {
    http_response_code(404);
    exit('Unknown section.');
}

$fields = [
    'title_nl' => trim((string) ($_POST['title_nl'] ?? '')),
    'title_en' => trim((string) ($_POST['title_en'] ?? '')),
    'body_nl' => trim((string) ($_POST['body_nl'] ?? '')),
    'body_en' => trim((string) ($_POST['body_en'] ?? '')),
    'button_label_nl' => trim((string) ($_POST['button_label_nl'] ?? '')),
    'button_label_en' => trim((string) ($_POST['button_label_en'] ?? '')),
    'button_url' => trim((string) ($_POST['button_url'] ?? '')),
    'is_active' => isset($_POST['is_active']),
];

$errors = [];
if ($fields['title_nl'] === '') {
    $errors[] = 'De kop is verplicht.';
}

// A URL without a label would be an invisible link; a label alone is fine —
// an empty URL means "mail the address from Site-instellingen", which
// App\Service\ContactCardContent resolves at render time.
if ($fields['button_url'] !== '' && $fields['button_label_nl'] === '') {
    $errors[] = 'Vul een label voor de knop in, of laat ook de URL leeg.';
}

if ($errors !== []) {
    $_SESSION['admin_contact_card_errors'] = $errors;
    $_SESSION['admin_contact_card_old'] = $fields;
    header('Location: /admin/contact-card.php?section=' . urlencode($sectionParam));
    exit;
}

try {
    $repository->upsertSection($pageSlug, $sectionKey, $fields);
    ContactCardContent::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-contact-card.php] ' . $e->getMessage());

    $_SESSION['admin_contact_card_errors'] = ['Kon niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_contact_card_old'] = $fields;
    header('Location: /admin/contact-card.php?section=' . urlencode($sectionParam));
    exit;
}

header('Location: /admin/contact-card.php?section=' . urlencode($sectionParam) . '&saved=1');
exit;

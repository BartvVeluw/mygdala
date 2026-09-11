<?php

/**
 * POST /api/admin/update-content-language.php
 *
 * WHICH LANGUAGE VERSION OF THE WEBSITE CONTENT the signed-in administrator
 * is editing. The CMS shell's "Editing content: [NL] [EN]" switch posts here
 * and nothing else does.
 *
 * NO PERMISSION BEYOND BEING LOGGED IN, and that is the point: this endpoint
 * can only ever write to the account making the request. There is no user id
 * in the form, so there is nothing to tamper with — one CMS user cannot
 * change what language a colleague is editing in, and neither can a Super
 * Admin.
 *
 * IT CHANGES NOT ONE WORD OF THE WEBSITE, and not one word of the CMS
 * interface either. It writes a single column on `admin_users` and cannot
 * reach `site_settings` or the column that holds a person's CMS language
 * (MULTILINGUAL.md). All three language states are separate, and
 * Tests\Service\MultilingualBoundaryTest fails the build if this file learns
 * about the other two.
 *
 * WHY IT REDIRECTS BACK TO THE SCREEN YOU WERE ON. Which language's fields an
 * editor form shows is decided on the SERVER, so the page has to be rendered
 * again to change language. Sending the editor back where they were is the
 * difference between a language switch and a navigation.
 *
 * THE RETURN PATH IS NOT TRUSTED. It comes from the form, so it is checked
 * against one rule with no room in it: a single absolute path under /admin/,
 * no scheme, no host, no protocol-relative "//evil.example" and no
 * backslashes. Anything else falls back to the dashboard. An open redirect
 * out of an authenticated admin panel is a phishing primitive, and "it is
 * only a language switch" is exactly how one gets shipped.
 *
 * A BREAK-GLASS SESSION IS REFUSED rather than silently ignored: it has no
 * account row to store a preference on.
 *
 * Same PRG/session-flash pattern as every other write endpoint here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\ContentEditingLanguage;

AdminAuth::requireLoginForApi();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

/**
 * The one rule described above. Kept as a closure rather than a service
 * because it is three lines and this is its only caller; a helper class
 * would invite a second, looser caller.
 */
$safeReturnPath = static function (mixed $raw): string {
    $path = trim((string) $raw);

    if ($path === '' || strlen($path) > 512) {
        return '/admin/index.php';
    }

    // Must be a path on this host, under /admin/, and nothing that a browser
    // could read as a host: "//host", "/\host", or anything with a scheme.
    if (!str_starts_with($path, '/admin/')) {
        return '/admin/index.php';
    }

    if (str_contains($path, '\\') || str_contains($path, "\n") || str_contains($path, "\r")) {
        return '/admin/index.php';
    }

    return $path;
};

$returnTo = $safeReturnPath($_POST['return_to'] ?? '');

$userId = AdminAuth::userId();

if ($userId === null) {
    // Break-glass, or a session whose account disappeared between the page
    // render and this request. Either way there is nowhere to write.
    $_SESSION['admin_account_errors'] = ['Deze sessie heeft geen account om voorkeuren op te bewaren.'];
    header('Location: ' . $returnTo);
    exit;
}

// ContentEditingLanguage::persist() normalises against the site's own content
// languages, so whatever the form sent stops being request input right there:
// an unknown code becomes the default website language instead of reaching
// the database or a column name.
try {
    ContentEditingLanguage::persist($userId, (string) ($_POST['content_editing_language'] ?? ''));
} catch (\Throwable $e) {
    error_log('[api/admin/update-content-language.php] ' . $e->getMessage());

    $_SESSION['admin_account_errors'] = ['De bewerktaal kon niet worden opgeslagen. Probeer het opnieuw.'];
}

header('Location: ' . $returnTo);
exit;

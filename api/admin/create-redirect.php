<?php

/**
 * POST /api/admin/create-redirect.php
 *
 * Creates one redirect from admin/redirect.php's "+ Redirect toevoegen" form.
 * Same guard order and PRG/session-flash pattern as every other admin
 * endpoint: login, permission, method, CSRF, then validate, then write.
 *
 * Everything that decides whether this redirect may exist — the source not
 * colliding with a live URL, the destination being a usable path or a safe
 * absolute URL, the status code being one of this application's two, and the
 * result not making a loop — lives in App\Service\Redirects\RedirectValidator
 * and is enforced here rather than by the form.
 *
 * origin is fixed to 'manual' and never read from the request: an editor's row
 * and a row a page rename produced behave differently on the next rename (see
 * App\Service\Redirects\SlugChangeRedirects), so that flag is the
 * application's to set, not the browser's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/_redirect_input.php';

use App\Repository\RedirectRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Redirects\Redirect;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$input = redirect_input_from_post(null);

if ($input['errors'] !== []) {
    $_SESSION['admin_redirect_errors'] = $input['errors'];
    $_SESSION['admin_redirect_old'] = $input['old'];
    header('Location: /admin/redirect.php');
    exit;
}

try {
    (new RedirectRepository())->create($input['data'] + ['origin' => Redirect::ORIGIN_MANUAL]);
} catch (\Throwable $e) {
    error_log('[api/admin/create-redirect.php] ' . $e->getMessage());
    $_SESSION['admin_redirect_errors'] = ['Redirect kon niet worden aangemaakt. Probeer het opnieuw.'];
    $_SESSION['admin_redirect_old'] = $input['old'];
    header('Location: /admin/redirect.php');
    exit;
}

header('Location: /admin/redirects.php?created=1');
exit;

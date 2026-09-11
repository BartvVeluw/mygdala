<?php

/**
 * POST /api/admin/update-blog-settings.php
 *
 * Saves the Blog's own settings (admin/blog-settings.php). One form, one
 * endpoint, and every key it writes is on that form — so this save can never
 * blank a setting the screen did not show.
 *
 * The four switches each arrive with a hidden companion field, so an unticked
 * checkbox really means "off" rather than "not submitted". Posts per page is
 * clamped here as well as when it is read: a stored value out of range would
 * otherwise be a listing that shows everything or nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blog\BlogSettings;
use App\Service\Csrf;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('blog.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$perPage = (int) ($_POST[BlogSettings::POSTS_PER_PAGE] ?? BlogSettings::DEFAULT_POSTS_PER_PAGE);
$perPage = max(BlogSettings::MIN_POSTS_PER_PAGE, min(BlogSettings::MAX_POSTS_PER_PAGE, $perPage));

$flag = static fn (string $key): string => ($_POST[$key] ?? '0') === '1' ? '1' : '0';

try {
    BlogSettings::save([
        BlogSettings::TITLE => mb_substr(trim((string) ($_POST[BlogSettings::TITLE] ?? '')), 0, BlogSettings::MAX_TITLE_LENGTH),
        BlogSettings::TITLE_EN => mb_substr(trim((string) ($_POST[BlogSettings::TITLE_EN] ?? '')), 0, BlogSettings::MAX_TITLE_LENGTH),
        BlogSettings::INTRO => mb_substr(trim((string) ($_POST[BlogSettings::INTRO] ?? '')), 0, BlogSettings::MAX_INTRO_LENGTH),
        BlogSettings::INTRO_EN => mb_substr(trim((string) ($_POST[BlogSettings::INTRO_EN] ?? '')), 0, BlogSettings::MAX_INTRO_LENGTH),
        BlogSettings::POSTS_PER_PAGE => (string) $perPage,
        BlogSettings::SHOW_AUTHOR => $flag(BlogSettings::SHOW_AUTHOR),
        BlogSettings::SHOW_DATE => $flag(BlogSettings::SHOW_DATE),
        BlogSettings::RELATED_POSTS => $flag(BlogSettings::RELATED_POSTS),
        BlogSettings::RSS_ENABLED => $flag(BlogSettings::RSS_ENABLED),
    ]);

    $_SESSION['admin_blog_settings_flash'] = AdminTranslator::trans('validation.bloginstellingen_opgeslagen');
} catch (\Throwable $e) {
    error_log('[api/admin/update-blog-settings.php] ' . $e->getMessage());
    $_SESSION['admin_blog_settings_errors'] = ['De instellingen konden niet worden opgeslagen. Probeer het opnieuw.'];

    header('Location: /admin/blog-settings.php');
    exit;
}

// ?saved=1 on the successful path only — see update-blog-category.php.
header('Location: /admin/blog-settings.php?saved=1');
exit;

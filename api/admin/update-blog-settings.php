<?php

/**
 * POST /api/admin/update-blog-settings.php
 *
 * Saves the Blog's own settings (admin/blog-settings.php). One form, one
 * endpoint, and every key it writes is on that form — so this save can never
 * blank a setting the screen did not show.
 *
 * TWO STORES, ONE TRANSACTION. The title and the introduction are website
 * text and go to App\Service\Blog\BlogLocalizedSettings (one row per website
 * language in `site_setting_translations`); the page size and the four
 * switches go to the module's own `blog_settings`. One click cannot end up
 * half applied.
 *
 * ONE LANGUAGE PER REQUEST (Multilingual 2.0 phase 5). The form carries the
 * two texts in the language `language_code` names, which must be an active
 * website language. Every other language's words stay exactly as they are, so
 * switching the editing language cannot overwrite a translation with a stale
 * copy. `language_code` never becomes a column name: it is checked against
 * the registry and handed to the store, which refuses anything else.
 *
 * NEITHER TEXT IS REQUIRED, in any language: an empty title means the Blog is
 * called "Blog" and an empty introduction means no paragraph is printed. Too
 * long is refused rather than silently cut, so an editor hears about a paste
 * that did not fit.
 *
 * The four switches each arrive with a hidden companion field, so an unticked
 * checkbox really means "off" rather than "not submitted". Posts per page is
 * clamped here as well as when it is read: a stored value out of range would
 * otherwise be a listing that shows everything or nothing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Service\Language\AdminTranslator;
use App\Service\AdminAuth;
use App\Service\Blog\BlogLocalizedSettings;
use App\Service\Blog\BlogSettings;
use App\Service\Csrf;
use App\Service\Language\LanguageCode;
use App\Service\Language\SiteLanguages;

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

$language = LanguageCode::normalise((string) ($_POST['language_code'] ?? '')) ?? '';

$words = [
    BlogLocalizedSettings::TITLE => trim((string) ($_POST[BlogLocalizedSettings::TITLE] ?? '')),
    BlogLocalizedSettings::INTRO => trim((string) ($_POST[BlogLocalizedSettings::INTRO] ?? '')),
];

$errors = [];

if ($language === '' || !SiteLanguages::isActive($language)) {
    $errors[] = AdminTranslator::trans('validation.language_unknown');
}

foreach (array_keys(BlogLocalizedSettings::problems($words)) as $key) {
    $errors[] = $key === BlogLocalizedSettings::TITLE
        ? AdminTranslator::trans('validation.title_max_chars', ['v1' => BlogLocalizedSettings::TITLE_MAX_LENGTH])
        : AdminTranslator::trans('validation.text_too_long');
}

if ($errors !== []) {
    $_SESSION['admin_blog_settings_errors'] = $errors;
    $_SESSION['admin_blog_settings_old'] = $words + ['language_code' => $language];

    header('Location: /admin/blog-settings.php');
    exit;
}

$db = Database::connection();

try {
    $db->beginTransaction();

    BlogSettings::save([
        BlogSettings::POSTS_PER_PAGE => (string) $perPage,
        BlogSettings::SHOW_AUTHOR => $flag(BlogSettings::SHOW_AUTHOR),
        BlogSettings::SHOW_DATE => $flag(BlogSettings::SHOW_DATE),
        BlogSettings::RELATED_POSTS => $flag(BlogSettings::RELATED_POSTS),
        BlogSettings::RSS_ENABLED => $flag(BlogSettings::RSS_ENABLED),
    ]);

    BlogLocalizedSettings::save($language, $words);

    $db->commit();

    BlogSettings::clearCache();
    BlogLocalizedSettings::clearCache();

    $_SESSION['admin_blog_settings_flash'] = AdminTranslator::trans('validation.bloginstellingen_opgeslagen');
} catch (\Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('[api/admin/update-blog-settings.php] ' . $e->getMessage());
    $_SESSION['admin_blog_settings_errors'] = ['De instellingen konden niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_blog_settings_old'] = $words + ['language_code' => $language];

    header('Location: /admin/blog-settings.php');
    exit;
}

// ?saved=1 on the successful path only — see update-blog-category.php.
header('Location: /admin/blog-settings.php?saved=1');
exit;

<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/../partials/breadcrumb.php';

use App\Repository\PageRepository;
use App\Service\AdminAuth;
use App\Service\Breadcrumbs\PageBreadcrumb;
use App\Service\Language\SiteText;
use App\Service\PageAssets;
use App\Service\PageContent;
use App\Service\SectionRegistry;

/**
 * A page as a visitor would see it, for a signed-in editor, published or not:
 * what "Voorbeeld bekijken" on admin/page.php opens.
 *
 * WHY AN ADMIN SCREEN, AND NOT THE PAGE'S OWN ADDRESS WITH A FLAG. A draft is
 * a 404 on its public address for everyone (pagina.php, and the templates of
 * the fixed pages), and that stays exactly so: nothing public reads a preview
 * parameter, a token or the admin session. The preview is a separate address
 * under /admin/, behind the page editor's own guard — signed in, and allowed
 * pages.manage. Not signed in means the login screen; signed out means the
 * session is gone and the preview with it; and robots.txt keeps crawlers out
 * of /admin/ on top of that. The smallest safe mechanism is the one every
 * admin screen already relies on, so there is no second one to get right.
 *
 * WHAT IT RENDERS. The pieces every public page template is made of, in the
 * same order: the page's own head (partials/page-head.php, where a draft is
 * noindex by itself through App\Service\PageSeo::isIndexable()), the assets of
 * its blocks, the site header, its one ordered list of blocks, the footer and
 * the collected scripts. The templates of the fixed pages differ from
 * pagina.php only in the content key they pass, so this is the page as its own
 * template shows it, with its STORED content — not what is being typed in the
 * editor at that moment. A small bar says it is a preview and leads back.
 *
 * WHAT IT NEVER DOES is change anything: the page keeps its status and its
 * timestamps, and stays out of the sitemap while it is a draft. Visitor
 * statistics skip /admin paths (App\Service\Analytics\PageViewTracker), so an
 * editor looking at a draft is never counted as a visitor. See PAGE-EDITOR.md.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

$idParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$page = ($idParam === false || $idParam === null || $idParam < 1)
    ? null
    : (new PageRepository())->findById($idParam);

if ($page === null) {
    http_response_code(404);
    exit(admin_t('screen.pagina_gevonden'));
}

$pageId = (int) $page['id'];
$contentKey = (string) $page['content_key'];
$isDraft = !PageContent::isPublished($page);

// The account, its permissions and its interface language have all been read
// by now. Closing the admin session releases its lock while the page renders,
// and keeps whatever the page itself does with a session of its own — a form
// block restoring a failed submission (App\Service\Forms\PublicFormSession) —
// away from the admin one.
session_write_close();

// For one signed-in person, possibly showing content nobody else may see yet:
// never kept by a cache and never indexed, whatever the page's own head says.
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

\App\Service\Forms\PublicFormSession::prime();

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= $h(SiteText::documentLanguage()) ?>" data-primary-lang="<?= $h(SiteText::documentLanguage()) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require dirname(__DIR__) . '/partials/page-head.php'; ?>
<?php
// The page's own assets, exactly as its template asks for them, plus the bar's
// stylesheet, asked for by the one screen that renders the bar.
SectionRegistry::collectPageAssets($contentKey);
PageAssets::requireStyle('assets/css/page-preview.css');
require dirname(__DIR__) . '/partials/page-assets.php';
?>
</head>
<body>
<?php require dirname(__DIR__) . '/partials/header.php'; ?>

<main id="main">
  <?php /* The preview shows what a visitor gets, the trail included — so the
           switch on the Pagina tab can be checked here rather than only on
           the live page. A draft previews as the page it will be. */ ?>
  <?php render_breadcrumb(PageBreadcrumb::forPage($page)); ?>
  <?php SectionRegistry::renderPage($contentKey); ?>
</main>

<?php require dirname(__DIR__) . '/partials/footer.php'; ?>

<?php /* After the page, so it comes last in the reading order and never sits
         between the skip link and the content. It is pinned on screen by
         assets/css/page-preview.css. */ ?>
<aside class="page-preview-bar" aria-label="<?= admin_te('page.preview_bar_label') ?>">
  <strong class="page-preview-bar__badge"><?= admin_te('page.preview_badge') ?></strong>
  <span><?= $isDraft ? admin_te('page.preview_draft') : admin_te('page.preview_published') ?></span>
  <a class="page-preview-bar__link" href="/admin/page.php?id=<?= $pageId ?>"><?= admin_te('page.preview_back') ?></a>
</aside>

<?php require dirname(__DIR__) . '/partials/page-scripts.php'; ?>
</body>
</html>

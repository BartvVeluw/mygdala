<?php

/**
 * Shared persistent sidebar for logged-in admin pages. Expects
 * AdminAuth::requireLogin() (or requirePermission()) to already have run in
 * the including page.
 *
 * Kept as _header.php (not renamed) so every existing admin page's
 * `require __DIR__ . '/_header.php'` keeps working — only its markup
 * changed, from a top nav bar to a left sidebar. It renders directly into
 * <body>, ahead of each page's own <main class="admin-main">; admin.css
 * positions the sidebar fixed and gives .admin-main a matching left margin,
 * so no page needs a wrapping layout element.
 *
 * The entries themselves — label, URL, the permission that opens them and
 * which admin scripts highlight them — live in App\Service\AdminNavigation,
 * because the login redirect and the "no access" page need the same answer.
 * Only entries the signed-in user is authorised for are rendered; that is
 * presentation, NOT access control: each page and each write endpoint
 * enforces its own permission server-side (see AdminAuth).
 *
 * "Pagina's" is a single entry pointing at admin/pages.php, the overview of
 * every CMS page (see App\Repository\PageRepository). It used to be one
 * sidebar link per hardcoded page plus a separate "Informatiepagina's" link;
 * with pages now an open-ended set the owner creates and deletes at will,
 * one entry that stays correct beats a list that silently goes stale. It
 * highlights for the overview, the per-page screen and every section editor
 * reached from it (e.g. /admin/cta-band.php?section=shop:main).
 *
 * Nav items are visually grouped by spacing/dividers instead of text
 * headings (see admin.css .admin-sidebar__divider) — the item labels and
 * icons carry the meaning on their own. A divider is drawn between two
 * visible entries from different groups, so a hidden section never leaves a
 * doubled or dangling divider behind.
 *
 * The shell is also where help lives (ADMIN-UI.md): admin-ui.js is loaded
 * here, first thing in <body> and not deferred, so every screen that renders
 * the shell gets field help without asking for it, and a stored "help off"
 * is applied before anything is painted. The help switch is printed twice —
 * at the top of the sidebar, and beside the menu button on a narrow screen,
 * where the sidebar is folded away — and admin.css shows one of the two.
 */

use App\Service\AdminAuth;
use App\Service\AdminNavigation;
use App\Service\Csrf;
use App\Service\Language\ContentEditingLanguage;
use App\Service\Language\ContentLanguages;
use App\Service\Language\LanguageRegistry;

require_once __DIR__ . '/_translate.php';
require_once __DIR__ . '/_admin_ui.php';

$csrfToken = Csrf::token();

$adminScriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$adminActiveGroup = AdminNavigation::activeKeyForScript($adminScriptName);
$adminVisibleNavItems = AdminNavigation::visibleItems();
$adminCurrentUserName = AdminAuth::userName();
$adminCurrentUserIsSuperAdmin = AdminAuth::isSuperAdmin();

/**
 * The CMS-wide "which language version of the content am I editing" switch.
 *
 * It lives in the shell and nowhere else, which is the whole correction: the
 * language an editor writes in is one piece of state for the entire CMS, not
 * a tab strip re-chosen on every screen (MULTILINGUAL.md). Every editor form
 * renders the fields of whatever this says, server-side.
 *
 * It is NOT the CMS interface language, which is a different preference on
 * the same person and lives under My account. Both can be set either way
 * round: a Dutch CMS editing English content is a normal Tuesday for a Dutch
 * owner writing an English page.
 *
 * A break-glass session has no row to store a preference on, so it reads the
 * site's default website language and the switch is not offered.
 */
$adminContentLanguages = ContentLanguages::enabled();
$adminEditingLanguage = ContentEditingLanguage::current();
$adminShowsContentLanguageSwitch = count($adminContentLanguages) > 1 && AdminAuth::userId() !== null;
$adminReturnPath = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
if (!str_starts_with($adminReturnPath, '/admin/')) {
    $adminReturnPath = '/admin/index.php';
}

/**
 * Small inline icon set (Feather-style: 24x24, stroke-based) so the sidebar
 * doesn't need an icon font/library dependency for ~10 glyphs. One key per
 * sidebar entry.
 */
const ADMIN_NAV_ICONS = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
    'portfolio' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.7"/><path d="M21 16l-5.5-5.5L7 19"/>',
    'media' => '<rect x="2.5" y="6.5" width="15" height="13" rx="2"/><circle cx="7" cy="11" r="1.5"/><path d="M17.5 16L13 11.5 6.5 18"/><path d="M7 3.5h12a2 2 0 0 1 2 2v10"/>',
    'products' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
    'collections' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="8" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="13" y="13" width="8" height="8" rx="1.5"/><path d="M13 17h8"/>',
    'related_products' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'personalization' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'shipping' => '<rect x="1" y="7" width="15" height="10" rx="1.5"/><path d="M16 10h3.5l3.5 3.5V17h-7z"/><circle cx="6" cy="19.5" r="1.8"/><circle cx="18" cy="19.5" r="1.8"/>',
    'shop_settings' => '<path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z"/><circle cx="12" cy="11" r="2.2"/><path d="M12 6.8v1.4M12 13.8v1.4M7.8 11h1.4M14.8 11h1.4"/>',
    'carrier_rates' =>'<path d="M3 6h18M3 12h18M3 18h18"/><circle cx="8" cy="6" r="1.8" fill="currentColor" stroke="none"/><circle cx="14" cy="12" r="1.8" fill="currentColor" stroke="none"/><circle cx="10" cy="18" r="1.8" fill="currentColor" stroke="none"/>',
    'orders' => '<path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'contact_requests' => '<path d="M4 4h16v16H4z"/><path d="M4 6l8 6 8-6"/>',
    'pages' => '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v5h5"/><path d="M8 12h8M8 15.5h8M8 19h5"/>',
    'withdrawal_requests' => '<path d="M4 12a8 8 0 1 0 2.34-5.66"/><path d="M4 4v5h5"/><path d="M12 8v4l3 2"/>',
    'navigation' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'footer' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 15h18"/><path d="M7 18h4"/>',
    'header_footer' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M3 16h18"/><path d="M14.5 6.5h3M6.5 18.5h4"/>',
    'settings' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h13"/><circle cx="14" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    'theme' => '<circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="9.5" r="1.3" fill="currentColor" stroke="none"/><circle cx="14" cy="8" r="1.3" fill="currentColor" stroke="none"/><circle cx="17" cy="12.5" r="1.3" fill="currentColor" stroke="none"/><path d="M12 21a3 3 0 0 1 0-6 2 2 0 0 0 0-4"/>',
    'users' => '<path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M17 4.2a3.5 3.5 0 0 1 0 6.6"/><path d="M21 20v-1.5a4 4 0 0 0-3-3.87"/>',
    'redirects' => '<path d="M4 17h9a5 5 0 0 0 5-5V6"/><path d="M14.5 9.5L18 6l3.5 3.5"/><circle cx="4" cy="17" r="1.8" fill="currentColor" stroke="none"/>',
    'forms' => '<path d="M6 3h12a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
    'content_blocks' => '<rect x="3" y="3.5" width="18" height="6" rx="1.5"/><rect x="3" y="12.5" width="8" height="8" rx="1.5"/><rect x="13" y="12.5" width="8" height="8" rx="1.5"/>',
    'form_submissions' => '<path d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M8.5 10.5l2.5 2.5 4.5-4.5"/>',
    'blog' => '<rect x="3" y="4" width="14" height="16" rx="2"/><path d="M17 8h2a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2"/><path d="M7 8h6M7 12h6M7 16h4"/>',
    'blog_categories' => '<path d="M3 7a2 2 0 0 1 2-2h3.6l2 2H19a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M3 11h18"/>',
    'blog_tags' => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0l-7-7A2 2 0 0 1 3 12.2V5a2 2 0 0 1 2-2h7.2a2 2 0 0 1 1.4.6l7 7a2 2 0 0 1 0 2.8z"/><circle cx="7.6" cy="7.6" r="1.4" fill="currentColor" stroke="none"/>',
    'blog_settings' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.2 5.2l2.1 2.1M16.7 16.7l2.1 2.1M18.8 5.2l-2.1 2.1M7.3 16.7l-2.1 2.1"/>',
];

function adminNavIcon(string $key): string
{
    $inner = ADMIN_NAV_ICONS[$key] ?? '';

    return '<svg class="admin-sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}
?>
<?php admin_ui_script(); ?>
<input type="checkbox" id="admin-sidebar-toggle" class="admin-sidebar-toggle-checkbox" hidden>
<?php /* The narrow-screen top row: the menu button, and the help switch beside
         it, reachable without opening the menu. One wrapper around the two;
         the checkbox stays a sibling of the <aside>, which its :checked ~ rule
         in admin.css needs. */ ?>
<div class="admin-topbar">
  <label for="admin-sidebar-toggle" class="admin-sidebar-toggle"><span aria-hidden="true"><?= admin_t('header.text', ['v1' => admin_te('shell.menu')]) ?></label>
  <?= admin_help_toggle('topbar') ?>
</div>
<aside class="admin-sidebar" id="admin-sidebar">
  <div class="admin-sidebar__head">
    <a href="/admin/index.php" class="admin-sidebar__brand"><?= htmlspecialchars(\App\Service\SiteSettings::get('site_name'), ENT_QUOTES, 'UTF-8') ?></a>
    <?= admin_help_toggle('sidebar') ?>
  </div>

<?php if ($adminShowsContentLanguageSwitch): ?>
  <?php /* A form rather than links: it changes stored state, so it is a POST
           with a CSRF token like every other write in this CMS. The return
           path sends the editor back to the screen they were on, because
           changing language is not navigation. */ ?>
  <form method="post" action="/api/admin/update-content-language.php" class="admin-sidebar__contentlang">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_to" value="<?= htmlspecialchars($adminReturnPath, ENT_QUOTES, 'UTF-8') ?>">
    <p class="admin-sidebar__contentlang-label" id="admin-contentlang-label"><?= admin_te('language.editing_switch') ?></p>
    <div class="admin-sidebar__contentlang-options" role="group" aria-labelledby="admin-contentlang-label">
      <?php foreach ($adminContentLanguages as $adminContentLanguage): ?>
        <button type="submit" name="content_editing_language" value="<?= htmlspecialchars($adminContentLanguage, ENT_QUOTES, 'UTF-8') ?>"
                class="admin-sidebar__contentlang-option<?= $adminContentLanguage === $adminEditingLanguage ? ' is-active' : '' ?>"
                aria-pressed="<?= $adminContentLanguage === $adminEditingLanguage ? 'true' : 'false' ?>"
                title="<?= htmlspecialchars(LanguageRegistry::label($adminContentLanguage, \App\Service\Language\AdminLocale::current()), ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars(strtoupper($adminContentLanguage), ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php endforeach; ?>
    </div>
  </form>
<?php endif; ?>

  <nav class="admin-sidebar__nav" aria-label="<?= admin_te('shell.nav_label') ?>">
    <?php $previousNavGroup = null; ?>
    <?php foreach ($adminVisibleNavItems as $navItem): ?>
      <?php if ($previousNavGroup !== null && $navItem['group'] !== $previousNavGroup): ?>
        <div class="admin-sidebar__divider" role="separator"></div>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($navItem['url'], ENT_QUOTES, 'UTF-8') ?>" class="admin-sidebar__link<?= $adminActiveGroup === $navItem['key'] ? ' is-active' : '' ?>"><?= adminNavIcon($navItem['icon']) ?><span><?= htmlspecialchars($navItem['label'], ENT_QUOTES, 'UTF-8') ?></span></a>
      <?php $previousNavGroup = $navItem['group']; ?>
    <?php endforeach; ?>
  </nav>

  <div class="admin-sidebar__account">
    <p class="admin-sidebar__account-name"><?= htmlspecialchars($adminCurrentUserName, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="admin-sidebar__account-role"><?= admin_te($adminCurrentUserIsSuperAdmin ? 'shell.role.super_admin' : 'shell.role.user') ?></p>
    <a href="/admin/account.php" class="admin-sidebar__account-link"><?= admin_te('shell.my_account') ?></a>
  </div>

  <form method="post" action="/admin/logout.php" class="admin-sidebar__logout">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit"><?= admin_te('shell.logout') ?></button>
  </form>
</aside>

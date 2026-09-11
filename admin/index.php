<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_labels.php';
require_once __DIR__ . '/_translate.php';

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\AdminAuth;

AdminAuth::requireLogin();
AdminAuth::requirePermission('dashboard.view');

/**
 * Dashboard landing page: a short summary of the website itself, then one
 * panel per ENABLED module, then the navigation cards into the main admin
 * areas, and finally the website statistics
 * (admin/_dashboard_analytics.php, backed by
 * App\Service\Analytics\AnalyticsDashboard).
 *
 * ## What is Core's and what is a module's
 *
 * This file used to BE the shop dashboard: four order KPIs, an attention list
 * built from orders, products and personalization, and a table of recent
 * orders, all queried here. On a site without a webshop that is not a
 * dashboard, it is an apology. So the shop half moved to the Shop
 * (admin/_dashboard_shop.php, contributed through
 * App\Module\ShopModule::dashboardPanels()) and what stayed is what every CMS
 * has: how much content there is, where to go next, and how the site is being
 * used.
 *
 * Deliberately NOT a widget framework. A panel is a partial path and a card is
 * an array; there is no registration API, no layout engine and no per-user
 * arrangement. If a second module ever wants a panel, it returns a second path.
 *
 * The statistics render behind this page's existing `dashboard.view` check and
 * have no permission of their own: they are aggregate numbers with no personal
 * data in them, and adding `analytics.view` would blank part of the dashboard
 * for every existing CMS user until someone re-ticked a box.
 *
 * ## Permissions
 *
 * Every section here is shown only to a user who may open the screen it links
 * into, and a section with no data for this user is not rendered at all — the
 * same rule the sidebar follows. Each module panel applies that rule to its
 * own data itself.
 */
$canManagePages = AdminAuth::can('pages.manage');

$now = new \DateTimeImmutable();

$pageCounts = ['published' => 0, 'draft' => 0];
$coreLoadFailed = false;

if ($canManagePages) {
    try {
        // Counted in PHP from the overview the pages screen already loads,
        // rather than adding a COUNT query for a number this small.
        foreach ((new PageRepository())->findAllForAdmin() as $page) {
            $key = (string) ($page['status'] ?? '') === 'published' ? 'published' : 'draft';
            $pageCounts[$key]++;
        }
    } catch (\Throwable $e) {
        error_log('[admin/index.php] ' . $e->getMessage());
        $coreLoadFailed = true;
    }
}

/**
 * Each card carries the permission its target page requires, and cards the
 * signed-in user may not open are left out entirely rather than rendered as
 * dead links into a 403 — the same rule the sidebar follows. `order` places a
 * module's cards among Core's; see App\Module\ModuleDefinition.
 */
$dashboardCards = array_merge(
    [
        [
            'icon' => 'pages',
            'title' => admin_t('dashboard.card_content_title'),
            'desc' => admin_t('dashboard.card_content_desc'),
            'href' => '/admin/pages.php',
            'cta' => admin_t('dashboard.card_content_cta'),
            'permission' => 'pages.manage',
            'order' => 100,
        ],
        [
            'icon' => 'settings',
            'title' => admin_t('dashboard.card_settings_title'),
            'desc' => admin_t('dashboard.card_settings_desc'),
            'href' => '/admin/settings.php',
            'cta' => admin_t('dashboard.card_settings_cta'),
            'permission' => 'settings.manage',
            'order' => 800,
        ],
        [
            'icon' => 'users',
            'title' => admin_t('dashboard.card_users_title'),
            'desc' => admin_t('dashboard.card_users_desc'),
            'href' => '/admin/users.php',
            'cta' => admin_t('dashboard.card_users_cta'),
            'permission' => 'users.manage',
            'order' => 900,
        ],
    ],
    ModuleRegistry::collect('dashboardCards')
);

usort($dashboardCards, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

$dashboardCards = array_values(array_filter(
    $dashboardCards,
    static fn (array $card): bool => AdminAuth::can($card['permission'])
));

$dashboardPanels = ModuleRegistry::collect('dashboardPanels');

const DASHBOARD_ICONS = [
    'pages' => '<path d="M3 9.5 12 3l9 6.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h3v-6h6v6h3a1 1 0 0 0 1-1V9.5"/>',
    'products' => '<path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8"/><path d="M12 13v9"/>',
    'orders' => '<path d="M6 3h12v18l-2.5-1.5L13 21l-2.5-1.5L8 21l-2-1.5V3z"/><path d="M9 8h6M9 12h6M9 16h4"/>',
    'settings' => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h13"/><circle cx="14" cy="6" r="2"/><circle cx="8" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    'users' => '<path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M17 4.2a3.5 3.5 0 0 1 0 6.6"/><path d="M21 20v-1.5a4 4 0 0 0-3-3.87"/>',
];

function dashboardIcon(string $key): string
{
    $inner = DASHBOARD_ICONS[$key] ?? '';

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
}

$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars(\App\Service\Language\AdminLocale::current(), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= admin_te('dashboard.title') ?> <?= admin_te('dashboard.admin') ?></title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <h1><?= admin_te('dashboard.title') ?></h1>
  <p class="admin-dashboard-intro"><?= admin_te('dashboard.intro') ?></p>

  <?php if ($coreLoadFailed): ?>
    <p class="admin-alert admin-alert--error"><?= admin_te('dashboard.pages_failed') ?></p>
  <?php endif; ?>

  <?php if ($dashboardCards === [] && $dashboardPanels === [] && !$canManagePages): ?>
    <p class="admin-text-muted"><?= admin_te('dashboard.no_permissions') ?></p>
  <?php endif; ?>

  <?php if ($canManagePages && !$coreLoadFailed): ?>
    <section class="admin-kpi-grid" aria-label="<?= admin_te('dashboard.content_label') ?>">
      <div class="admin-kpi">
        <p class="admin-kpi__label"><?= admin_te('dashboard.published_pages') ?></p>
        <p class="admin-kpi__value"><?= (int) $pageCounts['published'] ?></p>
        <p class="admin-kpi__note"><?= admin_te('dashboard.published_pages_note') ?></p>
      </div>
      <div class="admin-kpi">
        <p class="admin-kpi__label"><?= admin_te('dashboard.drafts') ?></p>
        <p class="admin-kpi__value"><?= (int) $pageCounts['draft'] ?></p>
        <p class="admin-kpi__note"><?= admin_te('dashboard.drafts_note') ?></p>
      </div>
    </section>
  <?php endif; ?>

  <?php /* One panel per enabled module, in registration order. */ ?>
  <?php foreach ($dashboardPanels as $dashboardPanel): ?>
    <?php require $dashboardPanel; ?>
  <?php endforeach; ?>

  <?php if ($dashboardCards !== []): ?>
    <h2 class="admin-dashboard-heading"><?= admin_te('dashboard.where_to_work') ?></h2>
    <div class="admin-dashboard-grid">
      <?php foreach ($dashboardCards as $card): ?>
        <a href="<?= $h($card['href']) ?>" class="admin-dashboard-card">
          <span class="admin-dashboard-card__icon"><?= dashboardIcon($card['icon']) ?></span>
          <p class="admin-dashboard-card__title"><?= $h($card['title']) ?></p>
          <p class="admin-dashboard-card__desc"><?= $h($card['desc']) ?></p>
          <span class="admin-dashboard-card__cta"><?= $h($card['cta']) ?> &#8594;</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php require __DIR__ . '/_dashboard_analytics.php'; ?>
</main>
</body>
</html>

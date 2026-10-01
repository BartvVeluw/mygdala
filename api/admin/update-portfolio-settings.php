<?php

/**
 * POST /api/admin/update-portfolio-settings.php
 *
 * Saves the Portfolio's own settings (admin/portfolio.php, "Instellingen"):
 * the default project layout (Portfolio layout 2.0,
 * App\Service\PortfolioProjectLayout), the site setting
 * `portfolio_project_layout`. Every project whose own layout is "Gebruik
 * standaardinstelling" follows it from the next request on; a project with a
 * choice of its own is not touched, because nothing about it is written
 * here.
 *
 * A word from the closed list only: anything else is refused with a message
 * and nothing is stored. portfolio.manage, which nobody holds while the
 * Portfolio is off (api/admin/CLAUDE.md), so no module guard.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\Language\AdminTranslator;
use App\Service\PortfolioProjectLayout;
use App\Service\SiteSettings;

AdminAuth::requireLoginForApi();
AdminAuth::requirePermissionForApi('portfolio.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed');
}

if (!Csrf::validate($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid or missing CSRF token.');
}

$layout = (string) ($_POST['project_layout'] ?? '');

if (!in_array($layout, PortfolioProjectLayout::DEFAULTS, true)) {
    $_SESSION['admin_portfolio_errors'] = [AdminTranslator::trans('validation.portfolio_layout_unknown')];
    header('Location: /admin/portfolio.php#portfolio-instellingen');
    exit;
}

try {
    (new SiteSettingRepository())->upsertMany([PortfolioProjectLayout::SETTING => $layout]);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-portfolio-settings.php] ' . $e->getMessage());
    $_SESSION['admin_portfolio_errors'] = [AdminTranslator::trans('editor_rows.error_save_failed')];
    header('Location: /admin/portfolio.php#portfolio-instellingen');
    exit;
}

header('Location: /admin/portfolio.php?saved=1#portfolio-instellingen');
exit;

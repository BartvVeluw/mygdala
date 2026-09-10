<?php

/**
 * POST /api/admin/update-header-footer-settings.php
 *
 * The shared header's call-to-action button, the footer's slogan and the
 * site's social profiles — all App\Service\SiteSettings keys (see
 * db/migrations/20260909220000_pin_header_cta_and_footer_slogan.php), owned
 * on the read side by App\Service\HeaderCta, App\Service\FooterService and
 * App\Service\SocialProfiles.
 *
 * ONE form for all three sections, like update-footer-settings.php and
 * unlike update-site-settings.php: every field this screen has is submitted
 * on every save, so each key is written explicitly, including a checkbox's
 * "off" state. That is what makes turning the button or the slogan off
 * actually work — an unchecked checkbox sends nothing at all, so a handler
 * that only writes the keys present in $_POST could never store a 0.
 *
 * WHAT IS REFUSED. The link type must be one App\Service\HeaderCta allows,
 * and its companion field is validated by the same App\Service\LinkResolver
 * the nav and footer editors use — no raw PHP filename, no unregistered
 * route. Every social URL must pass App\Service\SocialProfiles, i.e. https,
 * a real host, and a host that belongs to the network whose field it is.
 * The network keys themselves come from that closed registry and never from
 * the request, so a POST cannot introduce a network, an icon or a class
 * name. An EMPTY companion field or social URL is always allowed and simply
 * means "not configured yet".
 *
 * Same PRG/session-flash pattern as api/admin/update-site-settings.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Repository\PageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Service\HeaderCta;
use App\Service\LinkResolver;
use App\Service\SiteSettings;
use App\Service\SocialProfiles;

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

$field = static fn (string $name): string => trim((string) ($_POST[$name] ?? ''));

$errors = [];

$ctaLabelNl = $field('header_cta_label_nl');
$ctaLabelEn = $field('header_cta_label_en');
$ctaLinkType = $field('header_cta_link_type');
$ctaPageId = $field('header_cta_target_page_id');
$ctaRoute = $field('header_cta_target_route');
$ctaExternalUrl = $field('header_cta_external_url');

if (!in_array($ctaLinkType, HeaderCta::LINK_TYPES, true)) {
    $errors[] = 'Kies een geldig type bestemming voor de knop.';
    $ctaLinkType = 'page';
}

if (mb_strlen($ctaLabelNl) > 100 || mb_strlen($ctaLabelEn) > 100) {
    $errors[] = 'De tekst van de knop mag maximaal 100 tekens lang zijn.';
}

// Only the companion field of the CHOSEN type is kept and checked; the other
// two are cleared, so a stored target can never be a leftover of a type
// nobody selected any more. An empty field means "no bestemming gekozen" and
// is allowed — the button then simply does not render, and the admin screen
// says so.
$ctaPageId = $ctaLinkType === 'page' ? $ctaPageId : '';
$ctaRoute = $ctaLinkType === 'route' ? $ctaRoute : '';
$ctaExternalUrl = $ctaLinkType === 'external' ? $ctaExternalUrl : '';

$ctaTargetGiven = $ctaPageId !== '' || $ctaRoute !== '' || $ctaExternalUrl !== '';

if ($ctaTargetGiven) {
    $linkError = LinkResolver::validate(
        $ctaLinkType,
        $ctaPageId === '' ? null : (int) $ctaPageId,
        $ctaRoute === '' ? null : $ctaRoute,
        $ctaExternalUrl === '' ? null : $ctaExternalUrl,
        null,
        HeaderCta::LINK_TYPES,
        new PageRepository()
    );

    if ($linkError !== null) {
        $errors[] = $linkError;
    }
}

$sloganNl = $field('footer_slogan_nl');
$sloganEn = $field('footer_slogan_en');

if (mb_strlen($sloganNl) > 200 || mb_strlen($sloganEn) > 200) {
    $errors[] = 'De footer-slogan mag maximaal 200 tekens lang zijn.';
}

$socialValues = [];

foreach (SocialProfiles::networks() as $network => $definition) {
    $url = $field($definition['key']);

    if ($url !== '' && !SocialProfiles::isValidProfileUrl($network, $url)) {
        $errors[] = sprintf(
            'De link voor %s moet een volledig https-adres van %s zelf zijn.',
            $definition['label'],
            $definition['label']
        );
    }

    // Kept either way: on an error nothing is written, and this is what the
    // screen shows back so the editor can correct their own typing instead
    // of retyping every field.
    $socialValues[$definition['key']] = $url;
}

$values = [
    'header_cta_enabled' => isset($_POST['header_cta_enabled']) ? '1' : '0',
    'header_cta_label_nl' => $ctaLabelNl,
    'header_cta_label_en' => $ctaLabelEn,
    'header_cta_link_type' => $ctaLinkType,
    'header_cta_target_page_id' => $ctaPageId,
    'header_cta_target_route' => $ctaRoute,
    'header_cta_external_url' => $ctaExternalUrl,
    'header_cta_open_in_new_tab' => isset($_POST['header_cta_open_in_new_tab']) ? '1' : '0',
    'footer_slogan_enabled' => isset($_POST['footer_slogan_enabled']) ? '1' : '0',
    'footer_slogan_nl' => $sloganNl,
    'footer_slogan_en' => $sloganEn,
] + $socialValues;

if ($errors !== []) {
    $_SESSION['admin_header_footer_errors'] = $errors;
    $_SESSION['admin_header_footer_old'] = $values;
    header('Location: /admin/header-footer.php');
    exit;
}

try {
    (new SiteSettingRepository())->upsertMany($values);
    SiteSettings::clearCache();
} catch (\Throwable $e) {
    error_log('[api/admin/update-header-footer-settings.php] ' . $e->getMessage());

    $_SESSION['admin_header_footer_errors'] = ['Instellingen konden niet worden opgeslagen. Probeer het opnieuw.'];
    $_SESSION['admin_header_footer_old'] = $values;
    header('Location: /admin/header-footer.php');
    exit;
}

header('Location: /admin/header-footer.php?saved=1');
exit;

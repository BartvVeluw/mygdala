<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\AdminAuth;

/**
 * The old "Header & footer" screen, later "Slotregel & social media". Since
 * Footer phase B everything it held lives on the one Footer screen
 * (admin/footer.php): the closing line in the card Slotregel & copyright,
 * the social profiles as rows in the card Social media. The header's button
 * moved to Header & navigatie in Navigation phase A.
 *
 * Kept only so a bookmark or an old link still lands somewhere useful. The
 * same guards as the screen it points to, so the redirect tells a signed-out
 * visitor nothing; then a 302, not a 301, because a browser caches a
 * permanent redirect for good and this address may one day mean something
 * else. It renders nothing and writes nothing.
 */

AdminAuth::requireLogin();
AdminAuth::requirePermission('pages.manage');

header('Location: /admin/footer.php#footer-bottom', true, 302);
exit;

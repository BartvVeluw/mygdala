<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

/**
 * Apache's ErrorDocument for 404 — the one place a request that Apache itself
 * could not resolve reaches PHP.
 *
 * WHY IT EXISTS. This application has no front controller (see PROJECT-MAP.md
 * and .htaccess): a public request either hits a real PHP file at the project
 * root, or matches one of three narrow rewrites, or is refused by Apache
 * before any PHP runs. That last group is exactly where a legacy URL lands —
 * /oud_pad (an underscore, which the CMS rewrite's [a-z0-9-] pattern never
 * matched), /oude/pagina (two segments), /legacy.html, /Oude-Pagina — and
 * until this file existed it got Apache's stock "Not Found" page and nothing
 * else. One ErrorDocument line in .htaccess is the smallest change that lets
 * the Redirect Manager see those URLs at all, and it cannot shadow anything:
 * Apache only reaches it once it has established that nothing serves the
 * request.
 *
 * ORDER. The redirect lookup runs first and exits on a match
 * (App\Service\Redirects\RedirectGate). Everything that is not a moved URL
 * falls through to the same 404 document the rest of the site renders, so an
 * unknown URL now looks the same whether Apache or PHP decided it was unknown.
 *
 * The status code is set explicitly rather than inherited: PHP resets it to
 * 200 for the ErrorDocument script, and a 404 page that answers 200 is a soft
 * 404 — the one thing SEO.md is most careful to avoid.
 */

$requestPath = \App\Service\Redirects\RedirectPath::fromRequestUri(
    (string) ($_SERVER['REQUEST_URI'] ?? '/')
) ?? '/';

\App\Service\Redirects\RedirectGate::handleOr404();

http_response_code(404);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * A missing asset, endpoint or admin screen is not something a visitor is
 * reading, and building the site's whole shell — navigation, footer, theme,
 * every database query behind them — for a stray /assets/... request would be
 * a page nobody sees. Those get the same terse answer App\Module\ModuleGuard
 * already gives an endpoint of a disabled module.
 */
$isMachineRoute = false;
foreach (['/api/', '/admin/', '/assets/', '/vendor/'] as $prefix) {
    if (str_starts_with($requestPath . '/', $prefix)) {
        $isMachineRoute = true;
        break;
    }
}

if ($isMachineRoute || ($method !== 'GET' && $method !== 'HEAD')) {
    header('Content-Type: text/plain; charset=UTF-8');
    echo "404 Not Found\n";
    exit;
}

require __DIR__ . '/partials/route-not-found-page.php';

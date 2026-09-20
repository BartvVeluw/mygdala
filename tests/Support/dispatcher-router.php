<?php

declare(strict_types=1);

/**
 * Test support, never part of the application: the `.htaccess` rules of
 * Multilingual 2.0 phase 6, expressed for PHP's built-in web server.
 *
 * Tests\Support\BuiltInServer normally serves FILES — a test asks for
 * /pagina.php?slug=… rather than /over-ons, because `php -S` reads no
 * `.htaccess`. That is fine for everything except the one thing phase 6
 * added: the routing itself (docs/multilingual/ROUTING.md). A test that wants
 * to know whether /en/over-ons resolves, whether /nl/over-ons redirects and
 * what /de/ answers has to go through dispatcher.php, and this file is what
 * puts it in the way.
 *
 * IT MIRRORS `.htaccess`, in the same order and for the same reasons:
 *
 *   1. a technical namespace is never routed — a missing file there answers
 *      like the machine route it is, not with the site's whole shell;
 *   2. sitemap.xml and robots.txt have their own generators;
 *   3. an existing file or directory wins, which is what keeps every
 *      root-level template and every asset reachable;
 *   4. everything else goes to dispatcher.php.
 *
 * It deliberately does NOT try to be Apache. The `.htaccess` file stays the
 * production truth, and Tests\Service\DispatcherRoutingTest says in its own
 * docblock that the two are read together.
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$root = dirname(__DIR__, 2);

foreach (['admin', 'api', 'assets', 'uploads', 'vendor', 'db', 'src', 'docker', 'docs', 'partials', 'scripts', 'tests'] as $namespace) {
    if ($path === '/' . $namespace || str_starts_with($path, '/' . $namespace . '/')) {
        // Let the built-in server answer: the real file when there is one,
        // its own 404 when there is not.
        return false;
    }
}

if ($path === '/sitemap.xml') {
    require $root . '/sitemap.php';

    return true;
}

if ($path === '/robots.txt') {
    require $root . '/robots.php';

    return true;
}

// An existing file or directory is served as it always was — the emergency
// hatch `.htaccess`'s `!-f` / `!-d` conditions provide in production.
$candidate = $root . $path;
if ($path !== '/' && (is_file($candidate) || is_dir($candidate))) {
    return false;
}

require $root . '/dispatcher.php';

return true;

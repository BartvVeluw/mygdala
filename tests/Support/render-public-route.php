<?php

/**
 * Test support, never part of the application: renders one public route
 * against whatever database this PROCESS is pointed at, and prints the HTML
 * it produced.
 *
 * It exists for the same reason complete-setup-cli.php does — App\Database
 * holds one static connection per process, so anything that must talk to a
 * throwaway database runs in its own process (Tests\Support\ScratchInstall).
 * And "what does a brand-new installation actually SHOW a visitor" is a
 * question the ordinary test database cannot answer at all: it is a copy of
 * development, so every page it renders is full of this site.
 *
 * Usage, through ScratchInstall::runScript():
 *
 *     runScript('tests/Support/render-public-route.php', ['index.php'])
 *
 * The argument is a root-level template — the same file Apache would reach
 * for that URL. This is not an HTTP client and does not pretend to be one:
 * it prepares the request superglobals a template reads, includes the
 * template, and prints the buffer. What it proves is what the application
 * renders, which is exactly the layer the fresh-install claim is about; the
 * webserver in front of it is the same webserver on every installation.
 *
 * Request headers are deliberately hostile to anything that might read them:
 * the Host is a domain that cannot exist, so a canonical URL, a sitemap
 * entry or an og:url built from the request rather than from configuration
 * would be immediately obvious in the output.
 */

declare(strict_types=1);

$target = $argv[1] ?? 'index.php';

if (!preg_match('/^[a-z0-9-]+\.php$/', $target)) {
    fwrite(STDERR, "Not a root-level template: {$target}\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
if (!is_file($root . '/' . $target)) {
    fwrite(STDERR, "No such template: {$target}\n");
    exit(1);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/' . $target;
$_SERVER['SCRIPT_NAME'] = '/' . $target;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $target;
$_SERVER['HTTP_HOST'] = 'request-host.invalid';
$_SERVER['SERVER_NAME'] = 'request-host.invalid';
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'FreshInstallRenderTest/1.0';

ob_start();
require $root . '/' . $target;
echo ob_get_clean();

exit(0);

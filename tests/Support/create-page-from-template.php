<?php

/**
 * Creates one CMS page from a page template, in whatever database
 * DB_DATABASE points at.
 *
 * Used by Tests\Install\FreshInstallTest to prove the claim this cleanup
 * rests on: the pages a fresh install no longer receives — Diensten, Over
 * ons, Contact — are pages an editor can make afterwards, with the ordinary
 * *Nieuwe pagina* flow and no seeded content anywhere in sight.
 *
 * It runs in its own process because App\Database keeps one static
 * connection per process, so a test cannot point the application at a
 * scratch database without dragging the rest of the run along with it.
 *
 * Usage:
 *   php tests/Support/create-page-from-template.php <template-key> <slug> <title>
 *
 * Prints the new page's id on success; exits non-zero with the reason on
 * failure. Not part of the shipped site: nothing under tests/ is deployed.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Repository\PageRepository;
use App\Service\PageService;
use App\Service\PageTemplates\PageTemplates;
use App\Service\PageTemplates\PageTemplateInstaller;

[$templateKey, $slug, $title] = array_slice($argv, 1) + [null, null, null];

if ($templateKey === null || $slug === null || $title === null) {
    fwrite(STDERR, "usage: create-page-from-template.php <template-key> <slug> <title>\n");
    exit(2);
}

if (!PageTemplates::has($templateKey)) {
    fwrite(STDERR, "unknown template: {$templateKey}\n");
    exit(2);
}

try {
    $repository = new PageRepository();

    $id = PageTemplateInstaller::install(PageTemplates::resolve($templateKey), [
        'content_key' => PageService::generateContentKey($repository, $slug),
        'slug' => $slug,
        'title' => $title,
        'status' => 'draft',
        'meta_title' => null,
        'meta_title_en' => null,
        'meta_description' => null,
        'meta_description_en' => null,
    ]);
} catch (\Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}

echo $id, "\n";

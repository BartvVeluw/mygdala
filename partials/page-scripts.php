<?php

declare(strict_types=1);

/**
 * Every script a public page turned out to need, printed just before
 * </body> — the other half of partials/page-assets.php.
 *
 * Scripts are collected the same way stylesheets are, but they have no
 * ordering problem to solve: by the time this runs every block on the page
 * has rendered, so anything a block asked for on its way past is already in
 * the list. Third-party libraries come first, then Core, then whatever the
 * page's own blocks and routes asked for.
 *
 * See App\Service\PageAssets.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Service\PageAssets;

PageAssets::renderScripts();

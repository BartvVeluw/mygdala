<?php

/**
 * CLI helper: rebuilds the site search's block text index (Search 2.0,
 * SEARCH.md "De tekst van de blokken") from the content blocks themselves:
 * App\Service\Search\BlockSearchIndex::rebuild().
 *
 * Nothing breaks if this never runs. The index is derived data, and the
 * first search after it went out of date — after an update, a new language,
 * a module switched on — rebuilds it by itself. Run this to rebuild it
 * before anyone searches, or after editing block rows by hand in the
 * database. It only reads the blocks and rewrites the index; safe to run at
 * any time and as often as you like.
 *
 * Usage:
 *   php scripts/rebuild-search-index.php [--if-needed]
 *
 *   --if-needed   only when the index was built with other rules (exit 0 and
 *                 say so otherwise)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Search\BlockSearchIndex;

try {
    if (in_array('--if-needed', $argv, true) && BlockSearchIndex::isCurrent()) {
        echo "The search index is up to date.\n";
        exit(0);
    }

    $started = microtime(true);
    $pages = BlockSearchIndex::rebuild();
    printf("Rebuilt the search index from %d page(s) in %.1f s.\n", $pages, microtime(true) - $started);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not rebuild the search index: ' . $e->getMessage() . "\n");
    exit(1);
}

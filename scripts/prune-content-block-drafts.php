<?php

/**
 * CLI helper: removes content block DRAFTS nobody saved or cancelled within
 * ContentBlockDrafts::STALE_AFTER_HOURS (Content Blocks Lifecycle 1.0,
 * CONTENT-BLOCKS.md, "De levensloop van een nieuw blok") — a block chosen in
 * the picker whose editor was left with the back button or a closed tab.
 *
 * Nothing breaks if this never runs: a draft is on no page, and the next
 * block choice in the CMS runs the same sweep (api/admin/add-page-section.php).
 * Running it only makes sure a site where nobody adds a block for weeks does
 * not keep a forgotten draft's words, child rows and uploaded files. A placed
 * block is never touched (it has no draft row), and neither is a Media
 * Library item a draft chose: its content goes the way Annuleren's does
 * (ContentBlockDrafts::discard()).
 *
 * Usage:
 *   php scripts/prune-content-block-drafts.php [--limit=500] [--dry-run]
 *
 * As a Vimexx cronjob (daily is plenty):
 *   /usr/bin/php /home/<account>/domains/<domain>/public_html/scripts/prune-content-block-drafts.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\ContentBlockDraftRepository;
use App\Service\Blocks\ContentBlockDrafts;

$limit = 500;
$dryRun = in_array('--dry-run', $argv, true);
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--limit=')) {
        $value = substr($argument, 8);
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            fwrite(STDERR, "Invalid value for --limit: expected a positive number.\n");
            exit(1);
        }
        $limit = (int) $value;
    }
}

try {
    if ($dryRun) {
        $stale = (new ContentBlockDraftRepository())->findOlderThan(ContentBlockDrafts::STALE_AFTER_HOURS, $limit);
        echo 'Would remove ' . count($stale) . " stale content block draft(s).\n";
        exit(0);
    }

    $removed = ContentBlockDrafts::purgeStale($limit);
    echo 'Removed ' . $removed . " stale content block draft(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not prune content block drafts: ' . $e->getMessage() . "\n");
    exit(1);
}

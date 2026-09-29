<?php

/**
 * CLI helper: deletes EXPIRED temporary customer pictures of "Afbeelding
 * uploaden" order questions (Shop Admin UX & Order Fields 2.0), row and
 * files — pictures a visitor uploaded on a product page and never ordered.
 *
 * Nothing breaks if this never runs: every upload already gives the sweep a
 * one-in-twenty chance (api/order-field-upload.php), and an expired picture
 * can never be ordered (App\Service\OrderFields\OrderFieldUploads::resolve()).
 * Running it only makes sure a quiet shop does not keep a visitor's picture
 * longer than OrderFieldUploadPolicy::TTL_HOURS. A picture that belongs to an
 * order is never touched: it lives as long as the order.
 *
 * Usage:
 *   php scripts/prune-order-field-uploads.php [--limit=500] [--dry-run]
 *
 * As a Vimexx cronjob (daily is plenty):
 *   /usr/bin/php /home/<account>/domains/<domain>/public_html/scripts/prune-order-field-uploads.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\OrderFieldUploadRepository;
use App\Service\OrderFields\OrderFieldUploads;

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
        $expired = (new OrderFieldUploadRepository())->findExpiredUnclaimed($limit);
        echo 'Would delete ' . count($expired) . " expired temporary picture(s).\n";
        exit(0);
    }

    $deleted = (new OrderFieldUploads())->sweep($limit);
    echo 'Deleted ' . $deleted . " expired temporary picture(s).\n";
} catch (\Throwable $e) {
    fwrite(STDERR, 'Could not prune order-field uploads: ' . $e->getMessage() . "\n");
    exit(1);
}

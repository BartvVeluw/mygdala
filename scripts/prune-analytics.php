<?php

/**
 * CLI helper: enforces the retention limits on the website statistics.
 *
 * Two different jobs, with two very different reasons:
 *
 *  1. Old PAGEVIEWS are deleted (default: after 400 days). Housekeeping — it
 *     keeps a table that grows on every visit from growing forever on shared
 *     hosting. 400 days rather than 365 so a year-on-year comparison still has
 *     something to compare against.
 *
 *  2. Old visitor SALTS are deleted (default: after 60 days). This one is a
 *     privacy control, not housekeeping. A visitor hash is only checkable
 *     against an IP address while the salt that produced it still exists;
 *     dropping the salt makes that day's hashes permanently unlinkable to any
 *     person, by anyone, including us. The pageviews stay and keep counting —
 *     they are already anonymous aggregates at that point.
 *
 * Nothing breaks if this never runs: the dashboard only ever reads the last
 * 30 days, and no code path needs a salt older than today. Not running it
 * only means the table keeps growing and old salts stay around.
 *
 * Usage:
 *   php scripts/prune-analytics.php [--views-days=400] [--salt-days=60] [--dry-run]
 *
 * As a Vimexx cronjob (monthly is plenty):
 *   /usr/bin/php /home/<account>/domains/<domain>/public_html/scripts/prune-analytics.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Repository\PageViewRepository;

/**
 * Reads --name=<positive int> from the arguments, falling back to $default.
 * An unparseable or non-positive value is a mistake worth stopping for
 * rather than silently treating as "delete everything".
 *
 * @param list<string> $argv
 */
function pruneOption(array $argv, string $name, int $default): int
{
    foreach ($argv as $argument) {
        if (!str_starts_with($argument, '--' . $name . '=')) {
            continue;
        }

        $value = substr($argument, strlen($name) + 3);
        if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
            fwrite(STDERR, "Invalid value for --{$name}: expected a positive number of days.\n");
            exit(1);
        }

        return (int) $value;
    }

    return $default;
}

$viewsDays = pruneOption($argv, 'views-days', 400);
$saltDays = pruneOption($argv, 'salt-days', 60);
$dryRun = in_array('--dry-run', $argv, true);

$viewsCutoff = (new DateTimeImmutable('now'))->modify('-' . $viewsDays . ' days')->format('Y-m-d H:i:s');
$saltCutoff = (new DateTimeImmutable('now'))->modify('-' . $saltDays . ' days')->format('Y-m-d');

echo "Pageviews recorded before {$viewsCutoff} will be deleted.\n";
echo "Visitor salts for days before {$saltCutoff} will be deleted.\n";

if ($dryRun) {
    echo "--dry-run given: nothing was deleted.\n";
    exit(0);
}

$repository = new PageViewRepository();

$deletedViews = $repository->deletePageViewsBefore($viewsCutoff);
$deletedSalts = $repository->deleteSaltsBefore($saltCutoff);

echo "Deleted {$deletedViews} pageview(s) and {$deletedSalts} visitor salt(s).\n";

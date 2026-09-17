<?php

/**
 * CLI helper: finds words in `block_translations` that no longer belong to
 * anything, and removes the ones that provably cannot (Multilingual 2.0,
 * docs/multilingual/ARCHITECTURE.md).
 *
 * Why this exists at all: a block's words point at its content row by table
 * name and id, which cannot be a foreign key. Every delete that goes through
 * the CMS takes the words along in the same transaction
 * (App\Service\SectionRegistry::delete()), so on a healthy installation this
 * reports nothing. It is the safety net for what did not go through the CMS:
 * a row deleted by hand in Adminer, an import, a restored backup.
 *
 * Three kinds, and only the first is ever deleted:
 *
 *  - words whose owner row is gone             -> removed with --purge
 *  - words in a field the block no longer declares -> reported only
 *  - words of a table no registered block declares  -> reported only; that is
 *    also what a switched-off module's block looks like, and switching a
 *    module off never touches its data
 *
 * Usage:
 *   php scripts/block-translation-orphans.php            (report, exit 1 when anything is found)
 *   php scripts/block-translation-orphans.php --purge    (report, then remove the first kind)
 *
 * As a Vimexx cronjob (monthly is plenty):
 *   /usr/bin/php /home/<account>/domains/<domain>/public_html/scripts/block-translation-orphans.php --purge
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Blocks\BlockLocalization;

$purge = in_array('--purge', $argv, true);
$report = BlockLocalization::orphans();

foreach ($report['missing_owner'] as $row) {
    echo "Owner gone:        {$row['owner_table']} #{$row['owner_id']} ({$row['rows']} row(s))\n";
}

foreach ($report['undeclared_field'] as $row) {
    echo "Undeclared field:  {$row['owner_table']}.{$row['field']} ({$row['rows']} row(s))\n";
}

foreach ($report['unregistered_table'] as $row) {
    echo "Unregistered table: {$row['owner_table']} ({$row['rows']} row(s))\n";
}

$found = count($report['missing_owner']) + count($report['undeclared_field']) + count($report['unregistered_table']);

if ($found === 0) {
    echo "No orphaned block words.\n";
    exit(0);
}

if (!$purge) {
    echo "Nothing was deleted. Run with --purge to remove the words whose owner is gone.\n";
    exit(1);
}

$removed = BlockLocalization::purgeOrphans();
echo "Removed {$removed} row(s) whose owner is gone. Reported-only rows were left alone.\n";
exit(0);

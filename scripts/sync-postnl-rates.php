<?php

/**
 * CLI entry point for the PostNL carrier-rate sync (App\Service\Shipping\
 * PostNl\PostNlRateSyncService) — meant to run on a schedule via a Vimexx
 * cronjob (safe to run once a day or once a week, see MAIN.MD "PostNL-
 * synchronisatie"). Never touches checkout: this only ever updates
 * `carrier_rates` rows; api/checkout.php always reads whatever is already
 * stored there and never talks to PostNL itself.
 *
 * Usage:
 *   php scripts/sync-postnl-rates.php              Run the sync for real.
 *   php scripts/sync-postnl-rates.php --dry-run     Fetch/parse/validate and
 *                                                    print what WOULD change,
 *                                                    without writing anything
 *                                                    (no rate changes, no
 *                                                    sync-run log entry).
 *   php scripts/sync-postnl-rates.php --dump-text   Fetch the current PostNL
 *                                                    tariff PDF and print its
 *                                                    extracted plain text,
 *                                                    then exit — no parsing,
 *                                                    no validation, no
 *                                                    database access at all.
 *                                                    Use this once after
 *                                                    deploying to confirm the
 *                                                    live document still
 *                                                    reads the way
 *                                                    PostNlRateParser expects
 *                                                    (see its docblock —
 *                                                    PostNL doesn't guarantee
 *                                                    this document's layout).
 *
 * Exit code 0 on a successful (or partially successful, e.g. one rate code
 * flagged for review) run, 1 on failure — so a cron failure-notification
 * setup (e.g. Vimexx emailing on non-zero exit / any output) actually fires
 * when the source couldn't be reached or parsed at all.
 *
 * Vimexx cron command (replace the path with your actual hosting path —
 * this is a documented example, not a real deployed path):
 *   0 6 * * * /usr/bin/php /home/<vimexx-account>/domains/<your-domain>/public_html/scripts/sync-postnl-rates.php >> /home/<vimexx-account>/domains/<your-domain>/logs/postnl-sync.log 2>&1
 * (runs once daily at 06:00; a weekly schedule, e.g. "0 6 * * 1" for every
 * Monday, is equally safe — see MAIN.MD.)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Service\Shipping\PostNl\PostNlFetchException;
use App\Service\Shipping\PostNl\PostNlRateFetcher;
use App\Service\Shipping\PostNl\PostNlRateSyncService;

$args = array_slice($argv, 1);

if (in_array('--dump-text', $args, true)) {
    try {
        echo (new PostNlRateFetcher())->fetchTariffText(), "\n";
        exit(0);
    } catch (PostNlFetchException $e) {
        fwrite(STDERR, 'Fetch failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$dryRun = in_array('--dry-run', $args, true);

$result = (new PostNlRateSyncService())->sync(triggeredBy: 'cron', dryRun: $dryRun);

echo ($dryRun ? '[DRY RUN] ' : '') . $result->message . "\n";

foreach (['Gewijzigd' => $result->changed, 'Ongewijzigd' => $result->unchanged, 'Handmatige modus (niet toegepast)' => $result->pendingManual, 'Gemarkeerd voor controle' => $result->flaggedForReview, 'Waarschuwingen' => $result->warnings] as $heading => $lines) {
    if ($lines === []) {
        continue;
    }
    echo "\n{$heading}:\n";
    foreach ($lines as $line) {
        echo "  - {$line}\n";
    }
}

exit($result->success ? 0 : 1);

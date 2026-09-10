<?php

declare(strict_types=1);

/**
 * The website-statistics block on the CMS dashboard (admin/index.php).
 *
 * Presentation only: every number comes from one call to
 * App\Service\Analytics\AnalyticsDashboard::summary(), so nothing here knows
 * what "this month so far" means or how a period is compared — see that class
 * for the reporting rules and the privacy trade-offs behind them.
 *
 * Kept as its own partial rather than inlined into admin/index.php for the
 * reason the personalization screens are separate files: the dashboard is a
 * navigation page that happened to gain a report, and mixing the report's
 * ~200 lines into the card grid would make both harder to change. It is
 * included by admin/index.php and nothing else.
 *
 * The trend chart is hand-built SVG. A charting library would be the only
 * front-end dependency in the entire CMS, for one chart of thirty bars that
 * needs no interaction — so the chart is rendered server-side, works with
 * JavaScript disabled, and its per-day figures live in <title> elements the
 * browser shows as a native tooltip.
 *
 * Permission: this renders behind admin/index.php's existing
 * `dashboard.view`, deliberately without a permission of its own. The
 * statistics are aggregate and contain no personal data, and inventing
 * `analytics.view` would silently blank part of the dashboard for every
 * existing CMS user until someone re-ticked a box.
 */

use App\Service\Analytics\AnalyticsDashboard;

$stats = AnalyticsDashboard::summary();

$statsH = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$statsNumber = static fn (int $value): string => number_format($value, 0, ',', '.');

/** Short Dutch month names — setlocale() is not dependable on shared hosting. */
const ADMIN_STATS_MONTHS = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec',
];

/** '2026-09-08' -> '8 sep'. */
function adminStatsShortDate(string $isoDate): string
{
    [$year, $month, $day] = array_map('intval', explode('-', $isoDate) + [0, 0, 0]);

    return $day . ' ' . (ADMIN_STATS_MONTHS[$month] ?? '?');
}

/** '2026-09' -> 'september 2026' for the month comparison labels. */
function adminStatsMonthLabel(string $isoMonth): string
{
    $full = [
        1 => 'januari', 2 => 'februari', 3 => 'maart', 4 => 'april', 5 => 'mei', 6 => 'juni',
        7 => 'juli', 8 => 'augustus', 9 => 'september', 10 => 'oktober', 11 => 'november', 12 => 'december',
    ];

    [$year, $month] = array_map('intval', explode('-', $isoMonth) + [0, 0]);

    return ($full[$month] ?? '?') . ' ' . $year;
}

/**
 * The "+12% t.o.v. gisteren" line under a figure.
 *
 * A percentage needs something to be a percentage OF: when the previous
 * period saw nothing at all, the raw comparison number is shown instead of a
 * meaningless "+100%".
 */
function adminStatsDelta(int $current, int $previous, string $periodLabel): string
{
    $change = AnalyticsDashboard::percentageChange($current, $previous);
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    if ($change === null) {
        return '<span class="admin-stat-card__delta is-neutral">'
            . $escape($periodLabel . ': ' . number_format($previous, 0, ',', '.'))
            . '</span>';
    }

    $modifier = $change > 0 ? 'is-up' : ($change < 0 ? 'is-down' : 'is-neutral');
    $arrow = $change > 0 ? '&#8593;' : ($change < 0 ? '&#8595;' : '&#8594;');
    $sign = $change > 0 ? '+' : '';

    return '<span class="admin-stat-card__delta ' . $modifier . '">'
        . $arrow . ' ' . $escape($sign . $change . '% t.o.v. ' . $periodLabel)
        . '</span>';
}
?>
<section class="admin-stats" aria-labelledby="admin-stats-heading">
  <div class="admin-stats__head">
    <h2 id="admin-stats-heading">Websitestatistieken</h2>
    <p class="admin-stats__intro">
      Eigen meting op de server — geen Google Analytics, geen cookies en geen IP-adressen in de database.
      Bezoekersaantallen zijn daardoor een benadering: een bezoeker die op vijf dagen terugkomt telt als vijf.
    </p>
  </div>

<?php if (!$stats['has_data']): ?>
  <p class="admin-text-muted">
    Er zijn nog geen bezoeken geregistreerd. Zodra iemand de website bezoekt verschijnen hier de cijfers —
    bezoeken aan het CMS zelf en herkenbare zoekmachines/robots worden bewust niet meegeteld.
  </p>
<?php else: ?>

  <div class="admin-stats__grid">
    <div class="admin-stat-card">
      <p class="admin-stat-card__label">Weergaven vandaag</p>
      <p class="admin-stat-card__value"><?= $statsNumber($stats['today']['pageviews']) ?></p>
      <?= adminStatsDelta($stats['today']['pageviews'], $stats['yesterday']['pageviews'], 'gisteren') ?>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-card__label">Bezoekers vandaag</p>
      <p class="admin-stat-card__value"><?= $statsNumber($stats['today']['visitors']) ?></p>
      <?= adminStatsDelta($stats['today']['visitors'], $stats['yesterday']['visitors'], 'gisteren') ?>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-card__label">Weergaven deze maand</p>
      <p class="admin-stat-card__value"><?= $statsNumber($stats['month']['pageviews']) ?></p>
      <?= adminStatsDelta($stats['month']['pageviews'], $stats['previous_month']['pageviews'], 'vorige maand') ?>
    </div>
    <div class="admin-stat-card">
      <p class="admin-stat-card__label">Bezoekers deze maand</p>
      <p class="admin-stat-card__value"><?= $statsNumber($stats['month']['visitors']) ?></p>
      <?= adminStatsDelta($stats['month']['visitors'], $stats['previous_month']['visitors'], 'vorige maand') ?>
    </div>
  </div>

  <p class="admin-stats__footnote">
    De vergelijking gebruikt even lange periodes: vandaag tot dit tijdstip tegenover gisteren tot hetzelfde
    tijdstip, en <?= $statsH(adminStatsMonthLabel($stats['month_label'])) ?> tot nu tegenover evenveel dagen
    van <?= $statsH(adminStatsMonthLabel($stats['previous_month_label'])) ?>.
  </p>

  <div class="admin-stats__panels">
    <div class="admin-stats__panel admin-stats__panel--chart">
      <h3>Laatste <?= AnalyticsDashboard::CHART_DAYS ?> dagen</h3>
      <?php
        $chart = $stats['chart'];
        $maxValue = 0;
        foreach ($chart as $day) {
            $maxValue = max($maxValue, $day['pageviews']);
        }
        // A flat zero row still needs a scale to draw against.
        $scale = max(1, $maxValue);

        $chartWidth = 960;
        $chartHeight = 240;
        $padLeft = 44;
        $padRight = 10;
        $padTop = 14;
        $padBottom = 30;
        $plotWidth = $chartWidth - $padLeft - $padRight;
        $plotHeight = $chartHeight - $padTop - $padBottom;
        $slot = $plotWidth / max(1, count($chart));
        $viewBarWidth = $slot * 0.58;
        $visitorBarWidth = $slot * 0.26;

        $yFor = static fn (int $value): float => $padTop + $plotHeight - ($value / $scale) * $plotHeight;
      ?>
      <svg class="admin-stats__chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img"
           aria-label="Weergaven en bezoekers per dag over de laatste <?= AnalyticsDashboard::CHART_DAYS ?> dagen">
        <?php foreach ([0, (int) round($scale / 2), $scale] as $gridValue): ?>
          <?php $gridY = $yFor($gridValue); ?>
          <line x1="<?= $padLeft ?>" y1="<?= round($gridY, 1) ?>" x2="<?= $chartWidth - $padRight ?>" y2="<?= round($gridY, 1) ?>"
                class="admin-stats__gridline" />
          <text x="<?= $padLeft - 8 ?>" y="<?= round($gridY + 4, 1) ?>" class="admin-stats__axis" text-anchor="end"><?= $gridValue ?></text>
        <?php endforeach; ?>

        <?php foreach ($chart as $index => $day): ?>
          <?php
            $slotX = $padLeft + $index * $slot;
            $viewX = $slotX + ($slot - $viewBarWidth) / 2;
            $visitorX = $slotX + ($slot - $visitorBarWidth) / 2;
            $viewY = $yFor($day['pageviews']);
            $visitorY = $yFor($day['visitors']);
            $label = adminStatsShortDate($day['date']) . ': '
                . $statsNumber($day['pageviews']) . ' weergaven, '
                . $statsNumber($day['visitors']) . ' bezoekers';
          ?>
          <g>
            <title><?= $statsH($label) ?></title>
            <?php if ($day['pageviews'] > 0): ?>
              <rect x="<?= round($viewX, 1) ?>" y="<?= round($viewY, 1) ?>" width="<?= round($viewBarWidth, 1) ?>"
                    height="<?= round($padTop + $plotHeight - $viewY, 1) ?>" rx="2" class="admin-stats__bar" />
            <?php endif; ?>
            <?php if ($day['visitors'] > 0): ?>
              <rect x="<?= round($visitorX, 1) ?>" y="<?= round($visitorY, 1) ?>" width="<?= round($visitorBarWidth, 1) ?>"
                    height="<?= round($padTop + $plotHeight - $visitorY, 1) ?>" rx="2" class="admin-stats__bar admin-stats__bar--visitors" />
            <?php endif; ?>
            <?php if ($index === 0 || $index === count($chart) - 1 || $index === intdiv(count($chart), 2)): ?>
              <text x="<?= round($slotX + $slot / 2, 1) ?>" y="<?= $chartHeight - 10 ?>" class="admin-stats__axis"
                    text-anchor="middle"><?= $statsH(adminStatsShortDate($day['date'])) ?></text>
            <?php endif; ?>
          </g>
        <?php endforeach; ?>
      </svg>

      <p class="admin-stats__legend">
        <span class="admin-stats__key"><span class="admin-stats__swatch"></span> Weergaven</span>
        <span class="admin-stats__key"><span class="admin-stats__swatch admin-stats__swatch--visitors"></span> Bezoekers</span>
      </p>
    </div>

    <div class="admin-stats__panel">
      <h3>Meest bekeken pagina's</h3>
      <?php if ($stats['top_pages'] === []): ?>
        <p class="admin-text-muted">Nog geen weergaven in deze periode.</p>
      <?php else: ?>
        <table class="admin-stats__table">
          <thead>
            <tr><th scope="col">Pagina</th><th scope="col">Weergaven</th><th scope="col">Bezoekers</th></tr>
          </thead>
          <tbody>
            <?php foreach ($stats['top_pages'] as $page): ?>
              <tr>
                <td><a href="<?= $statsH($page['path']) ?>" target="_blank" rel="noopener"><?= $statsH($page['path']) ?></a></td>
                <td><?= $statsNumber($page['pageviews']) ?></td>
                <td><?= $statsNumber($page['visitors']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p class="admin-stats__panel-note">Laatste <?= AnalyticsDashboard::CHART_DAYS ?> dagen.</p>
    </div>

    <div class="admin-stats__panel">
      <h3>Bezoekers komen van</h3>
      <?php if ($stats['top_referrers'] === []): ?>
        <p class="admin-text-muted">
          Nog geen bezoeken vanaf een andere website. Bezoekers die de site rechtstreeks openen
          (bookmark, ingetypt adres) hebben geen herkomst.
        </p>
      <?php else: ?>
        <table class="admin-stats__table">
          <thead>
            <tr><th scope="col">Website</th><th scope="col">Weergaven</th></tr>
          </thead>
          <tbody>
            <?php foreach ($stats['top_referrers'] as $referrer): ?>
              <tr>
                <td><?= $statsH($referrer['host']) ?></td>
                <td><?= $statsNumber($referrer['pageviews']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p class="admin-stats__panel-note">Laatste <?= AnalyticsDashboard::CHART_DAYS ?> dagen, alleen externe websites.</p>
    </div>
  </div>

<?php endif; ?>
</section>

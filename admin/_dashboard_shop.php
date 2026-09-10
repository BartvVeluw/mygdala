<?php

/**
 * The Shop's panel on the CMS dashboard: today's and this month's figures,
 * what still needs doing, and the last few orders.
 *
 * It used to be the body of admin/index.php, which meant the dashboard of a
 * site without a webshop would still have queried `orders` and `products` and
 * rendered a turnover of nought. It is contributed now
 * (App\Module\ShopModule::dashboardPanels()), so with the Shop switched off
 * nothing includes this file and none of these queries runs.
 *
 * Deliberately not a reporting system. Everything here is read straight from
 * the existing `orders` and `products` rows at page load, in four small
 * aggregate queries. There is no event tracking, no per-day history table and
 * no background job: this is shop management, and the website statistics lower
 * down the dashboard are a separate thing that must not get tangled up in it.
 * The rules behind the numbers live in App\Service\DashboardMetrics (which
 * orders are revenue) and App\Service\DashboardAttention (what counts as a
 * problem); the SQL lives in App\Repository\DashboardRepository. This file
 * only asks and renders.
 *
 * PERMISSIONS. Each section is loaded only for a user who may open the screen
 * it links into, and a section with no data for this user is not rendered at
 * all — the same rule the sidebar already follows. A user without
 * `orders.view` therefore never sees a turnover figure, not even a hidden one:
 * the queries behind it are not run. Note that while the Shop is off NOBODY
 * holds those permissions (App\Service\AdminPermissions), so this file could
 * not produce anything even if something did include it.
 *
 * Expects $h (the dashboard's htmlspecialchars() helper) and $now.
 */

use App\Module\ModuleRegistry;
use App\Repository\DashboardRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductPersonalizationRepository;
use App\Service\AdminAuth;
use App\Service\DashboardAttention;
use App\Service\DashboardMetrics;

$canViewOrders = AdminAuth::can('orders.view');
$canViewProducts = AdminAuth::can('products.view');
$canManageProducts = AdminAuth::can('products.manage');

// Personalisatie is its own module, and the attention list is the one place
// the Shop panel shows something belonging to it. Asking the registry keeps
// that explicit: with Personalisatie off, its tables are not read at all.
$canManagePersonalization = ModuleRegistry::isEnabled('personalization')
    && AdminAuth::can('personalization.manage');

$summary = null;
$recentOrders = [];
$ordersAwaitingHandling = null;
$activeProducts = [];
$personalizationRows = [];
$shopLoadFailed = false;

try {
    $dashboard = new DashboardRepository();

    if ($canViewOrders) {
        $day = DashboardMetrics::dayWindow($now);
        $month = DashboardMetrics::monthWindow($now);

        $summary = [
            'today' => DashboardMetrics::period(
                $dashboard->orderTotalsBetween(DashboardMetrics::REVENUE_STATUSES, $day['from'], $day['until'])
            ),
            'month' => DashboardMetrics::period(
                $dashboard->orderTotalsBetween(DashboardMetrics::REVENUE_STATUSES, $month['from'], $month['until'])
            ),
        ];

        $recentOrders = $dashboard->findRecentOrders(DashboardMetrics::RECENT_ORDER_LIMIT);
        $ordersAwaitingHandling = $dashboard->countOrdersAwaitingHandling(
            DashboardMetrics::PAID_STATUS,
            OrderRepository::FULFILMENT_OPEN
        );
    }

    if ($canViewProducts) {
        $activeProducts = $dashboard->findActiveProductsForAttention();
    }

    if ($canManagePersonalization) {
        $personalizationRows = (new ProductPersonalizationRepository())->findAllConfigured();
    }
} catch (\Throwable $e) {
    // Same fallback philosophy as every other admin screen: the page still
    // renders (its navigation cards are what someone came for), this panel
    // just says the figures could not be loaded.
    error_log('[admin/_dashboard_shop.php] ' . $e->getMessage());
    $shopLoadFailed = true;
    $summary = null;
    $recentOrders = [];
    $ordersAwaitingHandling = null;
    $activeProducts = [];
    $personalizationRows = [];
}

$attentionItems = DashboardAttention::build(
    $ordersAwaitingHandling,
    $activeProducts,
    $personalizationRows,
    $canManageProducts
);
$attentionTotal = count($attentionItems);
$visibleAttentionItems = array_slice($attentionItems, 0, DashboardAttention::MAX_VISIBLE);

// Whether this user can see any of the shop overview at all. Someone with
// only `pages.manage` gets the navigation cards and nothing else, rather than
// three empty panels explaining what they may not look at.
$showsShopOverview = $canViewOrders || $canViewProducts || $canManagePersonalization;
?>

<?php if ($shopLoadFailed): ?>
  <p class="admin-alert admin-alert--error">De winkelgegevens konden niet worden geladen. De onderdelen hieronder werken gewoon.</p>
<?php endif; ?>

<?php if ($summary !== null): ?>
  <section class="admin-kpi-grid" aria-label="Winkeloverzicht">
    <?php
      // Only paid orders count — see App\Service\DashboardMetrics for why
      // pending/failed/canceled/expired are excluded and refunds subtracted.
      $kpis = [
          [
              'label' => 'Bestellingen vandaag',
              'value' => (string) $summary['today']['order_count'],
              'note' => 'Betaalde bestellingen van vandaag.',
          ],
          [
              'label' => 'Bestellingen deze maand',
              'value' => (string) $summary['month']['order_count'],
              'note' => 'Betaalde bestellingen sinds de 1e van deze maand.',
          ],
          [
              'label' => 'Omzet deze maand',
              'value' => '&euro; ' . $h(DashboardMetrics::formatAmount($summary['month']['revenue'])),
              'note' => $summary['month']['refunded'] > 0.0
                  ? 'Betaald minus terugbetaald (&euro; ' . $h(DashboardMetrics::formatAmount($summary['month']['refunded'])) . ' terugbetaald).'
                  : 'Betaald minus terugbetaald.',
          ],
          [
              'label' => 'Gemiddelde orderwaarde',
              'value' => '&euro; ' . $h(DashboardMetrics::formatAmount($summary['month']['average_order_value'])),
              'note' => 'Omzet deze maand gedeeld door het aantal bestellingen.',
          ],
      ];
    ?>
    <?php foreach ($kpis as $kpi): ?>
      <div class="admin-kpi">
        <p class="admin-kpi__label"><?= $h($kpi['label']) ?></p>
        <p class="admin-kpi__value"><?= $kpi['value'] ?></p>
        <p class="admin-kpi__note"><?= $kpi['note'] ?></p>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($showsShopOverview): ?>
  <section class="admin-card admin-attention">
    <h2>Aandacht nodig</h2>

    <?php if ($visibleAttentionItems === []): ?>
      <?php /* Deliberately neutral: this section only ever covers the
               onderdelen deze gebruiker mag zien, dus het mag niet klinken
               als een uitspraak over de hele winkel. */ ?>
      <p class="admin-text-muted">Niets te doen — er staat op dit moment niets open.</p>
    <?php else: ?>
      <ul class="admin-attention__list">
        <?php foreach ($visibleAttentionItems as $item): ?>
          <li class="admin-attention__item admin-attention__item--<?= $h($item['type']) ?>">
            <a class="admin-attention__link" href="<?= $h($item['href']) ?>">
              <span class="admin-attention__title"><?= $h($item['title']) ?></span>
              <span class="admin-attention__detail"><?= $h($item['detail']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($attentionTotal > count($visibleAttentionItems)): ?>
        <p class="admin-text-muted admin-attention__more">
          En nog <?= $attentionTotal - count($visibleAttentionItems) ?> ander<?= $attentionTotal - count($visibleAttentionItems) === 1 ? '' : 'e' ?> punt<?= $attentionTotal - count($visibleAttentionItems) === 1 ? '' : 'en' ?>.
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($canViewOrders): ?>
  <section class="admin-card">
    <div class="admin-card__heading">
      <h2>Recente bestellingen</h2>
      <a class="admin-btn-text" href="/admin/orders.php">Alle bestellingen &#8594;</a>
    </div>

    <?php if ($recentOrders === []): ?>
      <p class="admin-text-muted">Er zijn nog geen bestellingen.</p>
    <?php else: ?>
      <?php /* Deliberately every payment status, unlike the figures above:
               this is "wat is er net gebeurd", and a mislukte of nog niet
               betaalde bestelling hoort daar ook bij. */ ?>
      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Datum</th>
              <th>Klant</th>
              <th>Totaal</th>
              <th>Betaalstatus</th>
              <th>Afhandeling</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentOrders as $order): ?>
              <?php
                $orderId = (int) $order['id'];
                $createdAt = new \DateTimeImmutable((string) $order['created_at']);
                $orderNumber = OrderRepository::formatOrderNumber($orderId, $createdAt);
                $customerName = trim((string) ($order['customer_name'] ?? ''));
                $paymentStatus = (string) $order['status'];
                $fulfilmentStatus = (string) $order['fulfilment_status'];
              ?>
              <tr>
                <td><a href="/admin/order.php?id=<?= $orderId ?>"><?= $h($orderNumber) ?></a></td>
                <td><?= $h($createdAt->format('d-m-Y H:i')) ?></td>
                <td>
                  <?php if ($customerName === ''): ?>
                    <span class="admin-text-muted">Onbekend</span>
                  <?php else: ?>
                    <?= $h($customerName) ?>
                  <?php endif; ?>
                </td>
                <td>&euro; <?= $h(DashboardMetrics::formatAmount((float) $order['total'])) ?></td>
                <td><span class="admin-badge admin-badge--<?= $h($paymentStatus) ?>"><?= $h(adminPaymentStatusLabel($paymentStatus)) ?></span></td>
                <td><span class="admin-badge admin-badge--<?= adminFulfilmentBadgeModifier($fulfilmentStatus) ?>"><?= $h($fulfilmentStatus) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

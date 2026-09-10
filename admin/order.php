<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/_labels.php';
require_once __DIR__ . '/_order_personalization.php';

use App\Service\AdminAuth;
use App\Service\Csrf;
use App\Repository\InvoiceRepository;
use App\Repository\OrderItemPersonalizationRepository;
use App\Repository\PersonalizationPreviewSnapshotRepository;
use App\Repository\OrderRepository;

AdminAuth::requireLogin();
AdminAuth::requirePermission('orders.view');

// orders.view opens this screen read-only; every action on it (handling
// status, invoice, confirmation mail) needs orders.manage, which the
// endpoints behind those forms check again for themselves.
$canManageOrders = AdminAuth::can('orders.manage');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id < 1) {
    http_response_code(400);
    exit('Ongeldig ordernummer.');
}

$orderRepository = new OrderRepository();

try {
    $order = $orderRepository->findByIdForAdmin($id);
    $items = $order !== null ? $orderRepository->findItems($id) : [];
    $refunds = $order !== null ? $orderRepository->findRefunds($id) : [];
    $invoice = $order !== null ? (new InvoiceRepository())->findByOrderId($id) : null;
    /**
     * Personalisatie per bestelregel — an empty array for every order that
     * has none, which is every order placed before this feature existed and
     * every order of ordinary products. Keyed by order_items.id.
     */
    $personalizations = $order !== null
        ? (new OrderItemPersonalizationRepository())->findByOrderIdGrouped($id)
        : [];

    /**
     * The COMPOSED preview of each personalization view — the picture the
     * customer's browser rasterised when they ordered. Keyed by
     * order_items.id and then by view_key. Empty for every order placed
     * before snapshots existed, which is exactly what the renderer expects:
     * it falls back to the reconstruction it always drew.
     */
    $previewSnapshots = $order !== null
        ? (new PersonalizationPreviewSnapshotRepository())->findByOrderIdGrouped($id)
        : [];
} catch (\Throwable $e) {
    error_log('[admin/order.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Bestelling kon niet worden geladen.');
}

if ($order === null) {
    http_response_code(404);
    exit('Bestelling niet gevonden.');
}

$updated = isset($_GET['updated']);
$invoiceGenerated = isset($_GET['invoice_generated']);
$emailResent = isset($_GET['email_resent']);
$emailResendFailed = isset($_GET['email_resend_failed']);
$csrfToken = Csrf::token();
$orderNumber = OrderRepository::formatOrderNumber((int) $order['id'], new \DateTimeImmutable((string) $order['created_at']));
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bestelling <?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<?php /* Only for sizing the reconstructed personalization previews below —
         the same file the product editor's engraving-area editor uses, since
         both need the one rule that turns a zone height into a font size. */ ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/personalization-admin.js') ?>" defer></script>
<?php /* The engraving fonts this order actually used, loaded from the site's
         own font folder. Built from the order's own record of the font rather
         than from the live library, so a face that has since been deactivated
         or deleted still renders here exactly as the customer chose it. */ ?>
<style><?= orderPersonalizationFontFaceCss($personalizations) ?></style>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/orders.php">&larr; Terug naar bestellingen</a></p>
  <h1>Bestelling <?= htmlspecialchars($orderNumber, ENT_QUOTES, 'UTF-8') ?></h1>

  <?php if ($updated): ?>
    <p class="admin-alert admin-alert--success">Afhandelingsstatus bijgewerkt.</p>
  <?php endif; ?>
  <?php if ($invoiceGenerated): ?>
    <p class="admin-alert admin-alert--success">Factuur gegenereerd.</p>
  <?php endif; ?>
  <?php if ($emailResent): ?>
    <p class="admin-alert admin-alert--success">Bevestigingsmail opnieuw verstuurd.</p>
  <?php endif; ?>
  <?php if ($emailResendFailed): ?>
    <p class="admin-alert admin-alert--error">Bevestigingsmail kon niet opnieuw worden verstuurd (order niet betaald of nog geen factuur).</p>
  <?php endif; ?>

  <?php
    $shippingAddress = OrderRepository::resolveShippingAddress($order);
    $billingAddress = OrderRepository::resolveBillingAddress($order);
    $billingIsSameAsShipping = !empty($order['billing_same_as_shipping']);

    $formatAddressBlock = static function (array $address) use ($order): string {
        $name = trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? ''));
        $lines = [$name !== '' ? $name : (string) $order['customer_name']];
        if (!empty($address['company'])) {
            $lines[] = (string) $address['company'];
        }
        $houseNumber = trim((string) ($address['house_number'] ?? ''));
        $street = trim((string) ($address['street'] ?? ''));
        $addition = trim((string) ($address['house_number_addition'] ?? ''));
        $separator = ($addition !== '' && ctype_digit($addition[0])) ? '-' : '';
        $lines[] = $houseNumber === '' ? $street : trim($street . ' ' . $houseNumber . $separator . $addition);
        $lines[] = trim(($address['postal_code'] ?? '') . ' ' . ($address['city'] ?? ''));
        $lines[] = (string) ($address['country'] ?? '');

        return implode("\n", array_filter($lines, static fn ($l) => $l !== ''));
    };
  ?>

  <section class="admin-card">
    <h2>Klant &amp; verzending</h2>
    <p>
      <?= htmlspecialchars((string) $order['customer_email'], ENT_QUOTES, 'UTF-8') ?><br>
      <?php if (!empty($order['customer_phone'])): ?>
        <?= htmlspecialchars((string) $order['customer_phone'], ENT_QUOTES, 'UTF-8') ?><br>
      <?php endif; ?>
    </p>
    <p><?= nl2br(htmlspecialchars($formatAddressBlock($shippingAddress), ENT_QUOTES, 'UTF-8')) ?></p>
    <p>Verzendmethode: <strong><?= htmlspecialchars(adminShippingMethodLabel($order['shipping_method'] ?? null, (string) $order['shipping_cost']), ENT_QUOTES, 'UTF-8') ?></strong></p>
  </section>

  <section class="admin-card">
    <h2>Facturatiegegevens</h2>
    <?php if ($billingIsSameAsShipping): ?>
      <p class="admin-text-muted">Zelfde als verzendadres.</p>
    <?php else: ?>
      <p><?= nl2br(htmlspecialchars($formatAddressBlock($billingAddress), ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Producten</h2>
    <div class="admin-table-wrap">
    <table class="admin-table">
      <thead>
        <tr><th>Product</th><th>Aantal</th><th>Prijs</th><th>Subtotaal</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item): ?>
          <tr>
            <td>
              <?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?>
              <?php if (!empty($item['variant_label'])): ?>
                <br><span class="admin-text-muted"><?= htmlspecialchars((string) $item['variant_label'], ENT_QUOTES, 'UTF-8') ?></span>
              <?php endif; ?>
            </td>
            <td><?= (int) $item['quantity'] ?></td>
            <td>
              &euro; <?= number_format((float) $item['unit_price'], 2, ',', '.') ?>
              <?php if (!empty($item['personalization_surcharge']) && (float) $item['personalization_surcharge'] > 0): ?>
                <?php /* What that unit price is made of, straight from the
                         order line's own columns — never recomputed from a
                         product that may have changed since. */ ?>
                <br><span class="admin-text-muted">
                  &euro; <?= number_format((float) $item['base_unit_price'], 2, ',', '.') ?> product
                  + &euro; <?= number_format((float) $item['personalization_surcharge'], 2, ',', '.') ?> personalisatie
                </span>
              <?php endif; ?>
            </td>
            <td>&euro; <?= number_format((float) $item['unit_price'] * (int) $item['quantity'], 2, ',', '.') ?></td>
          </tr>
          <?php
            /**
             * Personalisatie belongs to THIS line, so it is rendered directly
             * under it rather than in a separate section further down the page
             * — with two personalized lines on one order, "which text belongs
             * to which product" must never be something the owner has to work
             * out. An order line without personalization (every historical
             * order, every ordinary product) renders no extra row at all.
             */
            $itemPersonalizations = $personalizations[(int) ($item['id'] ?? 0)] ?? [];
          ?>
          <?php if ($itemPersonalizations !== []): ?>
            <tr class="admin-personalization-row">
              <td colspan="4"><?php renderOrderItemPersonalizations($itemPersonalizations, $previewSnapshots[(int) ($item['id'] ?? 0)] ?? []); ?></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p>Verzendkosten: &euro; <?= number_format((float) $order['shipping_cost'], 2, ',', '.') ?></p>
    <p class="admin-total">Totaal: &euro; <?= number_format((float) $order['total'], 2, ',', '.') ?></p>
  </section>

  <section class="admin-card">
    <h2>Betaling (Mollie)</h2>
    <p>Status: <strong><?= htmlspecialchars(adminPaymentStatusLabel((string) $order['status']), ENT_QUOTES, 'UTF-8') ?></strong></p>
    <p>Mollie-status: <?= htmlspecialchars((string) ($order['mollie_status'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></p>
    <p>Mollie betalings-ID: <?php if (!empty($order['mollie_payment_id'])): ?><code><?= htmlspecialchars((string) $order['mollie_payment_id'], ENT_QUOTES, 'UTF-8') ?></code><?php else: ?>—<?php endif; ?></p>
    <?php $refundedAmount = (float) ($order['refunded_amount'] ?? 0.0); ?>
    <p>Terugbetaling: <strong><?= htmlspecialchars(adminRefundStatusLabel($refundedAmount, (float) $order['total']), ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if ($refundedAmount > 0): ?>
        (&euro; <?= number_format($refundedAmount, 2, ',', '.') ?> van &euro; <?= number_format((float) $order['total'], 2, ',', '.') ?>)
      <?php endif; ?>
    </p>
    <?php if ($refunds !== []): ?>
      <div class="admin-table-wrap">
      <table class="admin-table">
        <thead><tr><th>Datum</th><th>Bedrag</th><th>Status</th><th>Mollie refund-ID</th></tr></thead>
        <tbody>
          <?php foreach ($refunds as $refund): ?>
            <tr>
              <td><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $refund['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
              <td>&euro; <?= number_format((float) $refund['amount'], 2, ',', '.') ?></td>
              <td><?= htmlspecialchars(adminRefundStatusLabelFor((string) $refund['status']), ENT_QUOTES, 'UTF-8') ?></td>
              <td><code><?= htmlspecialchars((string) $refund['mollie_refund_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Factuur</h2>
    <?php if ($invoice !== null): ?>
      <p>Factuurnummer: <strong><?= htmlspecialchars((string) $invoice['invoice_number'], ENT_QUOTES, 'UTF-8') ?></strong></p>
      <p>Factuurdatum: <?= htmlspecialchars(date('d-m-Y', strtotime((string) $invoice['invoice_date'])), ENT_QUOTES, 'UTF-8') ?></p>
      <p>
        <a href="/api/admin/invoice-download.php?order_id=<?= (int) $order['id'] ?>" target="_blank" rel="noopener">Bekijk factuur (PDF)</a>
        &middot;
        <a href="/api/admin/invoice-download.php?order_id=<?= (int) $order['id'] ?>&mode=download">Download PDF</a>
      </p>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/api/admin/resend-order-confirmation.php" class="admin-fulfilment-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <button type="submit">Verstuur bevestigingsmail opnieuw</button>
      </form>
      <?php endif; ?>
    <?php elseif ($order['status'] === 'paid'): ?>
      <p class="admin-text-muted">Nog geen factuur voor deze bestelling.</p>
      <?php if ($canManageOrders): ?>
      <form method="post" action="/api/admin/generate-invoice.php" class="admin-fulfilment-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <button type="submit">Genereer factuur</button>
      </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="admin-text-muted">Facturen worden alleen aangemaakt voor betaalde bestellingen.</p>
    <?php endif; ?>
  </section>

  <?php
    /**
     * Handling status: the owner's own "still to do / dealt with" flag,
     * completely independent of the Mollie payment status shown above — a
     * refund or any other payment change never moves an order back to Open.
     * Marking as handled requires a paid order (enforced again in
     * api/admin/update-fulfilment-status.php and in OrderRepository); the
     * reopen action is always available on a handled order.
     */
    $fulfilmentStatus = (string) $order['fulfilment_status'];
    $isHandled = $fulfilmentStatus === OrderRepository::FULFILMENT_HANDLED;
  ?>
  <section class="admin-card">
    <h2>Afhandeling</h2>
    <p>Status: <span class="admin-badge admin-badge--<?= adminFulfilmentBadgeModifier($fulfilmentStatus) ?>"><?= htmlspecialchars($fulfilmentStatus, ENT_QUOTES, 'UTF-8') ?></span></p>

    <?php if ($isHandled): ?>
      <p>Afgehandeld op:
        <?php if (!empty($order['handled_at'])): ?>
          <strong><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $order['handled_at'])), ENT_QUOTES, 'UTF-8') ?></strong>
        <?php else: ?>
          <span class="admin-text-muted">onbekend (afgehandeld voordat dit werd vastgelegd)</span>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!$canManageOrders): ?>
      <p class="admin-text-muted">Je hebt alleen leesrechten voor bestellingen; de afhandelingsstatus wijzigen vereist het recht &ldquo;Bestellingen beheren&rdquo;.</p>
    <?php elseif ($isHandled || $order['status'] === 'paid'): ?>
      <form method="post" action="/api/admin/update-fulfilment-status.php" class="admin-fulfilment-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <input type="hidden" name="fulfilment_status" value="<?= htmlspecialchars($isHandled ? OrderRepository::FULFILMENT_OPEN : OrderRepository::FULFILMENT_HANDLED, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit"><?= $isHandled ? 'Markeer opnieuw als open' : 'Markeer als afgehandeld' ?></button>
      </form>
    <?php else: ?>
      <p class="admin-text-muted">Alleen betaalde bestellingen kunnen als afgehandeld worden gemarkeerd.</p>
    <?php endif; ?>
  </section>

  <section class="admin-card">
    <h2>Beveiliging &amp; audit</h2>
    <p class="admin-text-muted">
      Algemene voorwaarden geaccepteerd:
      <?= !empty($order['terms_accepted']) ? 'Ja' : 'Nee' ?><br>
      <?php if (!empty($order['terms_accepted_at'])): ?>
        Geaccepteerd op: <?= htmlspecialchars((string) $order['terms_accepted_at'], ENT_QUOTES, 'UTF-8') ?><br>
      <?php endif; ?>
      <?php if (!empty($order['terms_content_hash'])): ?>
        <?php $termsHash = (string) $order['terms_content_hash']; ?>
        Voorwaarden-hash: <span title="<?= htmlspecialchars($termsHash, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(substr($termsHash, 0, 8) . '…' . substr($termsHash, -4), ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
    </p>
  </section>
</main>
</body>
</html>

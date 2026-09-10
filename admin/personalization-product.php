<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repository\ProductPersonalizationRepository;
use App\Repository\ProductRepository;
use App\Service\AdminAuth;
use App\Service\Csrf;

AdminAuth::requireLogin();
AdminAuth::requirePermission('personalization.manage');

/**
 * THE editor for one product's personalization configuration — the only one.
 *
 * Everything about how a product may be personalized is configured here: the
 * on/off switch, whether personalization is required to buy it, the general
 * instructions, its dedicated preview images, and the zones on each of them.
 * admin/product-form.php no longer contains any of it and only links here, so
 * there is exactly one place a configuration can be changed and no way for
 * two screens to disagree about it.
 *
 * The product itself is NOT editable here. Name, description, price,
 * variants, product photos and public visibility stay in Producten; this
 * screen reads the product only to name it and to link back to it.
 *
 * Flash handling mirrors admin/product-form.php's: each action below posts to
 * its own endpoint, and a rejected save comes back with its errors and its
 * own submitted values, scoped to the one form that was rejected.
 */
$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT);

if ($productId === false || $productId === null || $productId < 1) {
    header('Location: /admin/personalization.php');
    exit;
}

try {
    $product = (new ProductRepository())->findByIdForAdmin($productId);
    $personalization = (new ProductPersonalizationRepository())->findForProduct($productId);
} catch (\Throwable $e) {
    error_log('[admin/personalization-product.php] ' . $e->getMessage());
    http_response_code(500);
    exit('Personalisatie kon niet worden geladen.');
}

if ($product === null) {
    http_response_code(404);
    exit('Product niet gevonden.');
}

/**
 * A product reached here without a configuration row is not an error state —
 * it just means it was never enrolled. Sending the administrator back to the
 * overview (where enrolling happens) beats rendering a builder for something
 * that does not exist yet.
 */
if ($personalization === null) {
    $_SESSION['admin_personalization_list_errors'] = [
        'Dat product staat nog niet in Personalisatie. Voeg het hieronder toe om het te configureren.',
    ];
    header('Location: /admin/personalization.php');
    exit;
}

$personalizationFlash = [
    'errors' => $_SESSION['admin_personalization_errors'] ?? [],
    'old' => $_SESSION['admin_personalization_old'] ?? null,
    'updated' => isset($_GET['personalization_updated']),
];
unset($_SESSION['admin_personalization_errors'], $_SESSION['admin_personalization_old']);

$csrfToken = Csrf::token();
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

require __DIR__ . '/_personalization_builder.php';
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Personalisatie: <?= $h((string) $product['name']) ?> — Admin</title>
<link rel="stylesheet" href="<?= \App\Service\AssetVersion::url('/admin/assets/admin.css') ?>">
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/admin.js') ?>" defer></script>
<?php /* The visual zone editor — only ever used on this screen. */ ?>
<script src="<?= \App\Service\AssetVersion::url('/admin/assets/personalization-admin.js') ?>" defer></script>
</head>
<body<?= \App\Service\AdminTheme::bodyAttribute() ?>>
<?php require __DIR__ . '/_header.php'; ?>
<main class="admin-main">
  <p><a href="/admin/personalization.php">&larr; Terug naar Personalisatie</a></p>

  <div class="admin-main__heading">
    <h1>Personalisatie: <?= $h((string) $product['name']) ?></h1>
    <a href="/admin/product-form.php?id=<?= $productId ?>" class="admin-btn-link">Product bewerken</a>
  </div>

  <p class="admin-text-muted">
    Naam, omschrijving, prijs, varianten, productfoto's en zichtbaarheid van dit product beheer je in
    <a href="/admin/product-form.php?id=<?= $productId ?>">Producten</a>. Op deze pagina staat alleen de
    personalisatie: de eigen voorbeeldafbeeldingen en de zones daarop.
  </p>

  <?php renderPersonalizationBuilder($product, $personalization, $csrfToken, $personalizationFlash); ?>
</main>
</body>
</html>

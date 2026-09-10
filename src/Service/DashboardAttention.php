<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\OrderRepository;
use App\Service\Personalization\ProductPersonalizationContent;

/**
 * The dashboard's "Aandacht nodig" list: which of the things the CMS already
 * knows are actually WRONG, phrased as one sentence each and pointed at the
 * screen where they get fixed.
 *
 * Pure and static — it is handed rows and returns rows, and never queries,
 * never checks a session and never renders. Everything it decides is
 * therefore testable directly, which matters more here than anywhere else on
 * this screen: a false alarm on a dashboard is worse than no dashboard,
 * because the owner learns to ignore the section.
 *
 * ## What counts as "needs attention"
 *
 * Only things that are (a) already true in the data model, (b) unambiguously
 * a mistake rather than a choice, and (c) fixable on a CMS page this project
 * has. So: an order that is paid and still on the working list; an ACTIVE
 * product a visitor could reach that has no picture, no price, or no place to
 * be sold; and a personalization configuration that cannot produce anything.
 *
 * What is deliberately NOT flagged: inactive products (a draft is work in
 * progress — the repository does not even return them), a product with
 * personalization switched off on purpose, unpaid orders (an abandoned
 * checkout is not a task), and anything that would need a rule this shop has
 * not made — stock levels, missing translations, SEO fields.
 *
 * ## Permissions
 *
 * There is no permission check in here, on purpose. The caller queries only
 * the data the signed-in user may see and passes the rest as null/[], so a
 * user without `orders.view` cannot get an order item out of this class at
 * all — rather than this class deciding, and a later caller forgetting to.
 * `$canEditProducts` is not an access check either: it only picks between two
 * links the user may already open (the product editor, or the overview).
 */
class DashboardAttention
{
    /** How many items the dashboard renders before it says "+N meer". */
    public const MAX_VISIBLE = 8;

    public const TYPE_ORDERS_AWAITING = 'orders_awaiting';
    public const TYPE_PERSONALIZATION = 'personalization_incomplete';
    public const TYPE_PRODUCT_NO_IMAGE = 'product_no_image';
    public const TYPE_PRODUCT_NO_PRICE = 'product_no_price';
    public const TYPE_PRODUCT_NO_CHANNEL = 'product_no_channel';

    /**
     * Builds the complete list, most urgent first: orders (a customer is
     * waiting), then personalization (a product is publicly broken), then the
     * product problems in the order a visitor would notice them.
     *
     * Truncation is the template's job — see MAX_VISIBLE — so the count it
     * shows is the real total, not the length of a list this method already
     * cut down.
     *
     * @param int|null                         $ordersAwaitingHandling null when the user may not see orders
     * @param array<int, array<string, mixed>> $activeProducts         DashboardRepository::findActiveProductsForAttention() rows, or [] when the user may not see products
     * @param array<int, array<string, mixed>> $personalizationRows    ProductPersonalizationRepository::findAllConfigured() rows, or [] when the user may not manage personalization
     * @param bool                             $canEditProducts        whether product items may link into the product editor
     *
     * @return list<array{type: string, title: string, detail: string, href: string}>
     */
    public static function build(
        ?int $ordersAwaitingHandling,
        array $activeProducts,
        array $personalizationRows,
        bool $canEditProducts
    ): array {
        $items = [];

        if ($ordersAwaitingHandling !== null && $ordersAwaitingHandling > 0) {
            $items[] = [
                'type' => self::TYPE_ORDERS_AWAITING,
                'title' => $ordersAwaitingHandling === 1
                    ? '1 betaalde bestelling wacht op afhandeling'
                    : $ordersAwaitingHandling . ' betaalde bestellingen wachten op afhandeling',
                'detail' => 'Betaald en nog niet afgehandeld.',
                'href' => '/admin/orders.php?handling=' . rawurlencode(OrderRepository::FULFILMENT_OPEN),
            ];
        }

        foreach (self::personalizationItems($activeProducts, $personalizationRows) as $item) {
            $items[] = $item;
        }

        foreach (self::productItems($activeProducts, $canEditProducts) as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Product problems, grouped by kind rather than by product, so the
     * section reads as a to-do list ("these three have no photo") instead of
     * repeating one product's name under three separate headings.
     *
     * @param array<int, array<string, mixed>> $activeProducts
     * @return list<array{type: string, title: string, detail: string, href: string}>
     */
    private static function productItems(array $activeProducts, bool $canEditProducts): array
    {
        $noImage = [];
        $noPrice = [];
        $noChannel = [];

        foreach ($activeProducts as $product) {
            if (!self::hasImage($product)) {
                $noImage[] = $product;
            }
            if (!self::hasPrice($product)) {
                $noPrice[] = $product;
            }
            if (!self::hasSalesChannel($product)) {
                $noChannel[] = $product;
            }
        }

        $items = [];

        foreach ($noImage as $product) {
            $items[] = [
                'type' => self::TYPE_PRODUCT_NO_IMAGE,
                'title' => self::productName($product) . ' heeft geen afbeelding',
                'detail' => 'Het product staat actief in de catalogus zonder foto.',
                'href' => self::productHref($product, $canEditProducts),
            ];
        }

        foreach ($noPrice as $product) {
            $items[] = [
                'type' => self::TYPE_PRODUCT_NO_PRICE,
                'title' => self::productName($product) . ' heeft geen prijs',
                'detail' => 'Een actief product zonder prijs is niet te verkopen.',
                'href' => self::productHref($product, $canEditProducts),
            ];
        }

        foreach ($noChannel as $product) {
            $items[] = [
                'type' => self::TYPE_PRODUCT_NO_CHANNEL,
                'title' => self::productName($product) . ' staat nergens te koop',
                'detail' => 'Niet in de shop en niet op de Personaliseren-pagina, dus nergens zichtbaar.',
                'href' => self::productHref($product, $canEditProducts),
            ];
        }

        return $items;
    }

    /**
     * Personalization problems, for the two ways a product can be publicly
     * broken: a configuration that is switched on but renders nothing, and a
     * product the owner put on the Personaliseren-pagina that cannot be
     * personalized at all.
     *
     * The completeness rule itself is not decided here — it comes from
     * ProductPersonalizationContent::summaryIsIncomplete(), the same call the
     * Personalisatie overview makes for its "Onvolledig" badge. Only the
     * WORDING of what exactly is missing is chosen here, and that is
     * presentation.
     *
     * Only ACTIVE products are considered, matching the product checks: a
     * half-configured product that is still inactive is work in progress. The
     * personalization rows carry `product_active` themselves, so these items
     * still appear for a user who may manage personalization but may not view
     * the product catalogue.
     *
     * @param array<int, array<string, mixed>> $activeProducts
     * @param array<int, array<string, mixed>> $personalizationRows
     * @return list<array{type: string, title: string, detail: string, href: string}>
     */
    private static function personalizationItems(array $activeProducts, array $personalizationRows): array
    {
        $items = [];
        $configuredProductIds = [];

        foreach ($personalizationRows as $row) {
            $productId = (int) ($row['product_id'] ?? 0);
            if ($productId < 1) {
                continue;
            }

            $configuredProductIds[$productId] = true;

            if ((int) ($row['product_active'] ?? 0) !== 1) {
                continue;
            }

            $reason = self::personalizationReason($row);

            if ($reason === null) {
                continue;
            }

            $items[] = [
                'type' => self::TYPE_PERSONALIZATION,
                'title' => 'Personalisatie van ' . self::quoted((string) ($row['product_name'] ?? '')) . ' is niet af',
                'detail' => $reason,
                'href' => '/admin/personalization-product.php?product_id=' . $productId,
            ];
        }

        // A product flagged for the public Personaliseren-pagina that was
        // never enrolled at all. It simply does not appear on that page (see
        // App\Service\Personalization\PersonalizationCatalog), which is
        // invisible from the product editor — so it is worth saying out loud.
        // Only detectable by comparing the two lists, which is why it lives
        // here rather than in personalizationReason() below. Skipped entirely
        // when the caller passed no personalization rows: it cannot then tell
        // "never enrolled" apart from "may not see personalization".
        if ($personalizationRows !== []) {
            foreach ($activeProducts as $product) {
                $productId = (int) ($product['id'] ?? 0);

                if ($productId < 1
                    || (int) ($product['in_personalization_catalog'] ?? 0) !== 1
                    || isset($configuredProductIds[$productId])) {
                    continue;
                }

                $items[] = [
                    'type' => self::TYPE_PERSONALIZATION,
                    'title' => self::productName($product) . ' is niet personaliseerbaar',
                    'detail' => 'Het product staat op de Personaliseren-pagina, maar heeft nog geen personalisatie-instellingen.',
                    'href' => '/admin/personalization.php',
                ];
            }
        }

        return $items;
    }

    /**
     * Why this configuration is not usable, or null when it is fine.
     *
     * A configuration that is switched OFF is only a problem when the owner
     * has ALSO put the product on the Personaliseren-pagina: off plus not
     * listed is a deliberate pause, off plus listed is a contradiction the
     * visitor sees as a missing product.
     *
     * @param array<string, mixed> $row a ProductPersonalizationRepository::findAllConfigured() row
     */
    public static function personalizationReason(array $row): ?string
    {
        $isEnabled = (int) ($row['is_enabled'] ?? 0) === 1;
        $viewCount = (int) ($row['view_count'] ?? 0);
        $viewsWithImage = (int) ($row['view_with_image_count'] ?? 0);
        $zoneCount = (int) ($row['zone_count'] ?? 0);
        $inCatalog = (int) ($row['in_personalization_catalog'] ?? 0) === 1;

        if (!$isEnabled) {
            return $inCatalog
                ? 'Het product staat op de Personaliseren-pagina, maar personalisatie staat uit.'
                : null;
        }

        if (!ProductPersonalizationContent::summaryIsIncomplete($viewsWithImage, $zoneCount)) {
            return null;
        }

        if ($viewCount === 0) {
            return 'Er is nog geen weergave toegevoegd, dus er valt niets te personaliseren.';
        }

        if ($viewsWithImage === 0) {
            return 'Geen enkele weergave heeft een voorbeeldafbeelding.';
        }

        return 'Er is nog geen zone ingesteld om op te graveren.';
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function hasImage(array $product): bool
    {
        return (int) ($product['has_image'] ?? 0) === 1;
    }

    /**
     * A price of exactly 0,00 is treated as "no price", not as a free
     * product: this shop sells nothing for nothing, and a product that
     * reached the catalogue at 0,00 is a form that was never finished.
     *
     * @param array<string, mixed> $product
     */
    private static function hasPrice(array $product): bool
    {
        $price = $product['price'] ?? null;

        return $price !== null && (float) $price > 0.0;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function hasSalesChannel(array $product): bool
    {
        return (int) ($product['in_shop'] ?? 0) === 1
            || (int) ($product['in_personalization_catalog'] ?? 0) === 1;
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function productHref(array $product, bool $canEditProducts): string
    {
        $productId = (int) ($product['id'] ?? 0);

        return $canEditProducts && $productId > 0
            ? '/admin/product-form.php?id=' . $productId
            : '/admin/products.php';
    }

    /**
     * @param array<string, mixed> $product
     */
    private static function productName(array $product): string
    {
        return self::quoted((string) ($product['name'] ?? ''));
    }

    /**
     * A product with no name at all is possible in the schema, and would
     * otherwise produce a sentence starting with an empty pair of quotes.
     */
    private static function quoted(string $name): string
    {
        $name = trim($name);

        // Typographic quotes (U+201C/U+201D) written as escapes rather than
        // as literal bytes, so the meaning of this line does not depend on
        // the file's encoding surviving every editor it passes through.
        return $name === '' ? 'Naamloos product' : "\u{201C}" . $name . "\u{201D}";
    }
}

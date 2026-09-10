<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\OrderRepository;
use App\Service\DashboardAttention;
use PHPUnit\Framework\TestCase;

/**
 * The "Aandacht nodig" filtering, item by item.
 *
 * A dashboard that cries wolf is worse than no dashboard, so the tests below
 * spend at least as much effort on what must NOT be flagged (a product that
 * is deliberately not in the shop, personalization switched off on purpose,
 * an unpaid order) as on what must.
 *
 * Everything here is arrays in, arrays out — the class never queries — so the
 * rows are written the way the two repositories really return them: integers
 * for the tinyint flags, decimal strings for money.
 */
final class DashboardAttentionTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> a DashboardRepository::findActiveProductsForAttention() row
     */
    private function product(array $overrides = []): array
    {
        return $overrides + [
            'id' => 7,
            'name' => 'Sleutelhanger',
            'price' => '12.50',
            'in_shop' => 1,
            'in_personalization_catalog' => 0,
            'has_image' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed> a ProductPersonalizationRepository::findAllConfigured() row
     */
    private function personalization(array $overrides = []): array
    {
        return $overrides + [
            'settings_id' => 1,
            'product_id' => 7,
            'is_enabled' => 1,
            'personalization_mode' => 'optional',
            'product_name' => 'Sleutelhanger',
            'product_active' => 1,
            'in_shop' => 1,
            'in_personalization_catalog' => 1,
            'view_count' => 1,
            'view_with_image_count' => 1,
            'zone_count' => 1,
        ];
    }

    /**
     * @param list<array{type: string, title: string, detail: string, href: string}> $items
     * @return list<string>
     */
    private function types(array $items): array
    {
        return array_map(static fn (array $item): string => $item['type'], $items);
    }

    /* ------------------------------------------------------------------ */
    /* Nothing to report                                                   */
    /* ------------------------------------------------------------------ */

    public function testAHealthyShopProducesNoItems(): void
    {
        $items = DashboardAttention::build(0, [$this->product()], [$this->personalization()], true);

        $this->assertSame([], $items);
    }

    public function testNoDataAtAllProducesNoItems(): void
    {
        $this->assertSame([], DashboardAttention::build(null, [], [], true));
    }

    /* ------------------------------------------------------------------ */
    /* Orders                                                              */
    /* ------------------------------------------------------------------ */

    public function testOrdersWaitingForHandlingAreFlaggedAndLinkToTheOpenFilter(): void
    {
        $items = DashboardAttention::build(3, [], [], true);

        $this->assertCount(1, $items);
        $this->assertSame(DashboardAttention::TYPE_ORDERS_AWAITING, $items[0]['type']);
        $this->assertStringContainsString('3 betaalde bestellingen', $items[0]['title']);
        $this->assertSame(
            '/admin/orders.php?handling=' . rawurlencode(OrderRepository::FULFILMENT_OPEN),
            $items[0]['href']
        );
    }

    public function testASingleWaitingOrderIsPhrasedInTheSingular(): void
    {
        $items = DashboardAttention::build(1, [], [], true);

        $this->assertStringContainsString('1 betaalde bestelling wacht', $items[0]['title']);
    }

    public function testZeroWaitingOrdersIsNotAnItem(): void
    {
        $this->assertSame([], DashboardAttention::build(0, [], [], true));
    }

    /**
     * null means "this user may not see orders", and must be indistinguishable
     * from a quiet day — never an item, and never a zero the user can reason
     * backwards from.
     */
    public function testOrdersAreNotReportedWhenTheCallerPassedNull(): void
    {
        $this->assertSame([], DashboardAttention::build(null, [], [], true));
    }

    /* ------------------------------------------------------------------ */
    /* Products                                                            */
    /* ------------------------------------------------------------------ */

    public function testAnActiveProductWithoutAnImageIsFlagged(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['has_image' => 0])], [], true);

        $this->assertSame([DashboardAttention::TYPE_PRODUCT_NO_IMAGE], $this->types($items));
        $this->assertStringContainsString('Sleutelhanger', $items[0]['title']);
        $this->assertStringContainsString('geen afbeelding', $items[0]['title']);
    }

    public function testAProductWithAnImageIsNotFlagged(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['has_image' => 1])], [], true);

        $this->assertSame([], $items);
    }

    public function testAProductWithoutAPriceIsFlagged(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['price' => null])], [], true);

        $this->assertSame([DashboardAttention::TYPE_PRODUCT_NO_PRICE], $this->types($items));
    }

    public function testAPriceOfZeroCountsAsNoPrice(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['price' => '0.00'])], [], true);

        $this->assertSame([DashboardAttention::TYPE_PRODUCT_NO_PRICE], $this->types($items));
    }

    public function testACentIsAPrice(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['price' => '0.01'])], [], true);

        $this->assertSame([], $items);
    }

    public function testAProductInNoSalesChannelIsFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [$this->product(['in_shop' => 0, 'in_personalization_catalog' => 0])],
            [],
            true
        );

        $this->assertSame([DashboardAttention::TYPE_PRODUCT_NO_CHANNEL], $this->types($items));
    }

    /**
     * A personalization-only product is a real, supported thing (see MAIN.MD)
     * — being out of the shop is a choice, not a mistake.
     */
    public function testAProductOnlyInThePersonalizationCatalogIsNotFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [$this->product(['in_shop' => 0, 'in_personalization_catalog' => 1])],
            [],
            true
        );

        $this->assertSame([], $items);
    }

    public function testOneProductCanRaiseSeveralItems(): void
    {
        $items = DashboardAttention::build(
            null,
            [$this->product(['has_image' => 0, 'price' => null, 'in_shop' => 0])],
            [],
            true
        );

        $this->assertSame(
            [
                DashboardAttention::TYPE_PRODUCT_NO_IMAGE,
                DashboardAttention::TYPE_PRODUCT_NO_PRICE,
                DashboardAttention::TYPE_PRODUCT_NO_CHANNEL,
            ],
            $this->types($items)
        );
    }

    public function testProductItemsLinkIntoTheEditorWhenTheUserMayEditProducts(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['id' => 42, 'has_image' => 0])], [], true);

        $this->assertSame('/admin/product-form.php?id=42', $items[0]['href']);
    }

    /**
     * A read-only user must land somewhere they may actually open, rather
     * than on the editor's 403 page.
     */
    public function testProductItemsFallBackToTheOverviewForAReadOnlyUser(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['id' => 42, 'has_image' => 0])], [], false);

        $this->assertSame('/admin/products.php', $items[0]['href']);
    }

    public function testAProductWithoutANameStillReadsAsASentence(): void
    {
        $items = DashboardAttention::build(null, [$this->product(['name' => '  ', 'has_image' => 0])], [], true);

        $this->assertStringStartsWith('Naamloos product', $items[0]['title']);
    }

    /* ------------------------------------------------------------------ */
    /* Personalization                                                     */
    /* ------------------------------------------------------------------ */

    public function testAnEnabledConfigurationWithoutAViewIsFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['view_count' => 0, 'view_with_image_count' => 0, 'zone_count' => 0])],
            true
        );

        $this->assertSame([DashboardAttention::TYPE_PERSONALIZATION], $this->types($items));
        $this->assertStringContainsString('geen weergave', $items[0]['detail']);
        $this->assertSame('/admin/personalization-product.php?product_id=7', $items[0]['href']);
    }

    public function testAnEnabledConfigurationWhoseViewsHaveNoPreviewImageIsFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['view_count' => 2, 'view_with_image_count' => 0, 'zone_count' => 3])],
            true
        );

        $this->assertStringContainsString('voorbeeldafbeelding', $items[0]['detail']);
    }

    public function testAnEnabledConfigurationWithoutZonesIsFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['view_count' => 1, 'view_with_image_count' => 1, 'zone_count' => 0])],
            true
        );

        $this->assertStringContainsString('zone', $items[0]['detail']);
    }

    public function testACompleteEnabledConfigurationIsNotFlagged(): void
    {
        $items = DashboardAttention::build(null, [], [$this->personalization()], true);

        $this->assertSame([], $items);
    }

    /**
     * Switching personalization off is a normal thing to do while working on
     * it. It only becomes a contradiction once the product is also listed on
     * the public Personaliseren-pagina.
     */
    public function testADisabledConfigurationIsOnlyFlaggedWhenTheProductIsInTheCatalogue(): void
    {
        $quietlyOff = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['is_enabled' => 0, 'in_personalization_catalog' => 0])],
            true
        );
        $this->assertSame([], $quietlyOff);

        $contradiction = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['is_enabled' => 0, 'in_personalization_catalog' => 1])],
            true
        );
        $this->assertSame([DashboardAttention::TYPE_PERSONALIZATION], $this->types($contradiction));
        $this->assertStringContainsString('staat uit', $contradiction[0]['detail']);
    }

    public function testAnIncompleteConfigurationOnAnInactiveProductIsNotFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [],
            [$this->personalization(['product_active' => 0, 'view_with_image_count' => 0, 'zone_count' => 0])],
            true
        );

        $this->assertSame([], $items);
    }

    public function testAProductOnThePersonalizationPageWithoutAnyConfigurationIsFlagged(): void
    {
        $items = DashboardAttention::build(
            null,
            [
                $this->product(['id' => 7, 'in_personalization_catalog' => 1]),
                $this->product(['id' => 9, 'name' => 'Naambord', 'in_personalization_catalog' => 1]),
            ],
            [$this->personalization(['product_id' => 7])],
            true
        );

        $this->assertSame([DashboardAttention::TYPE_PERSONALIZATION], $this->types($items));
        $this->assertStringContainsString('Naambord', $items[0]['title']);
        $this->assertSame('/admin/personalization.php', $items[0]['href']);
    }

    /**
     * With no personalization rows the caller either has nothing configured
     * or may not see personalization at all — and this class cannot tell
     * those apart, so it must stay quiet rather than accuse every catalogue
     * product of missing a configuration.
     */
    public function testNoPersonalizationItemsWithoutPersonalizationData(): void
    {
        $items = DashboardAttention::build(
            null,
            [$this->product(['in_personalization_catalog' => 1])],
            [],
            true
        );

        $this->assertSame([], $items);
    }

    /* ------------------------------------------------------------------ */
    /* Ordering and shape                                                  */
    /* ------------------------------------------------------------------ */

    public function testItemsComeBackMostUrgentFirst(): void
    {
        $items = DashboardAttention::build(
            2,
            [$this->product(['has_image' => 0])],
            [$this->personalization(['zone_count' => 0])],
            true
        );

        $this->assertSame(
            [
                DashboardAttention::TYPE_ORDERS_AWAITING,
                DashboardAttention::TYPE_PERSONALIZATION,
                DashboardAttention::TYPE_PRODUCT_NO_IMAGE,
            ],
            $this->types($items)
        );
    }

    public function testEveryItemCarriesATitleADetailAndALink(): void
    {
        $items = DashboardAttention::build(
            1,
            [$this->product(['has_image' => 0])],
            [$this->personalization(['zone_count' => 0])],
            true
        );

        $this->assertNotSame([], $items);

        foreach ($items as $item) {
            $this->assertNotSame('', $item['title']);
            $this->assertNotSame('', $item['detail']);
            $this->assertStringStartsWith('/admin/', $item['href']);
        }
    }

    /**
     * The full list is returned; truncating to MAX_VISIBLE is the template's
     * job, so the "+N meer" line it prints is based on the real total.
     */
    public function testTheFullListIsReturnedEvenBeyondTheVisibleMaximum(): void
    {
        $products = [];
        for ($i = 1; $i <= DashboardAttention::MAX_VISIBLE + 3; $i++) {
            $products[] = $this->product(['id' => $i, 'name' => 'Product ' . $i, 'has_image' => 0]);
        }

        $items = DashboardAttention::build(null, $products, [], true);

        $this->assertCount(DashboardAttention::MAX_VISIBLE + 3, $items);
    }

    /* ------------------------------------------------------------------ */
    /* The completeness rule itself                                        */
    /* ------------------------------------------------------------------ */

    public function testPersonalizationReasonIsNullForAWorkingConfiguration(): void
    {
        $this->assertNull(DashboardAttention::personalizationReason($this->personalization()));
    }

    public function testPersonalizationReasonToleratesAnIncompleteRow(): void
    {
        // Every field absent: an unknown row must not be reported as broken.
        $this->assertNull(DashboardAttention::personalizationReason([]));
    }
}

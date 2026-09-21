<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\OrderItemNameSnapshot;
use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * What a product was CALLED when somebody bought it (Multilingual 2.0 phase 5
 * wave C, docs/multilingual/ARCHITECTURE.md, MODULES.md "Shop"), without a
 * database.
 *
 * This is the file that states why App\Service\OrderItemNameSnapshot is a
 * class of its own rather than a third typed translation table behind
 * App\Service\ShopLocalization: a SNAPSHOT IS NOT A TRANSLATION. Its reading
 * rule is deliberately NOT App\Service\Language\LanguageFallback's, because a
 * placed order may not change when the website's languages change.
 *
 *   ShopLocalization   what a product is called NOW
 *   this class         what it was called THEN
 *
 * The real SQL, the cascade and the fact that the snapshot survives the
 * product being renamed or deleted are
 * Tests\Repository\OrderSnapshotIntegrationTest's and
 * Tests\Repository\ProductDeletionIntegrationTest's; the backfill is
 * Tests\Install\OrderItemNameSnapshotMigrationTest's.
 */
final class OrderItemNameSnapshotTest extends TestCase
{
    private const LINE = 91;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        OrderItemNameSnapshot::clearCache();
    }

    protected function tearDown(): void
    {
        OrderItemNameSnapshot::clearCache();
        SiteLanguageFixture::reset();
    }

    public function testTheStoreDeclaresItsOwnTableAndItsOneField(): void
    {
        $table = OrderItemNameSnapshot::names()->table();

        self::assertSame('order_item_translations', $table->name);
        self::assertSame('order_item_id', $table->ownerColumn);
        self::assertSame(['product_name'], $table->fieldNames());
        self::assertSame(255, OrderItemNameSnapshot::PRODUCT_NAME_MAX_LENGTH, 'the length order_items.product_name_en had');
    }

    /**
     * NOTHING ELSE OF AN ORDER LINE IS IN HERE. A price, a quantity and a
     * variant label are as frozen as the name, and they stay on the line
     * itself where the invoice reads them — there is no reason for a document
     * to join a second table to print a number.
     */
    public function testNoOtherPartOfAnOrderLineBecameAWord(): void
    {
        foreach (['unit_price', 'quantity', 'variant_label', 'sku', 'personalization_surcharge'] as $neutral) {
            self::assertNotContains($neutral, OrderItemNameSnapshot::names()->table()->fieldNames(), $neutral);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The reading rule                                                    */
    /* ------------------------------------------------------------------ */

    public function testALineWithItsOwnNameInALanguageGetsThatName(): void
    {
        $this->stored(['en' => 'Original Product Name']);

        self::assertSame(
            'Original Product Name',
            OrderItemNameSnapshot::name(self::LINE, 'en', 'Oorspronkelijke Productnaam')
        );
    }

    /**
     * THE RULE IS "THIS LANGUAGE, ELSE THE NEUTRAL SNAPSHOT", and never
     * "some other language of this line". The neutral name is the fallback by
     * construction rather than by being some language's, which is exactly
     * what keeps a document safe.
     */
    public function testALineWithoutItsOwnNameFallsBackToTheNeutralSnapshotOnTheLineItself(): void
    {
        $this->stored(['en' => 'Original Product Name']);

        self::assertSame(
            'Oorspronkelijke Productnaam',
            OrderItemNameSnapshot::name(self::LINE, 'de', 'Oorspronkelijke Productnaam'),
            'a language this line kept no name in reads the neutral one'
        );
    }

    public function testALineWithNoRowAtAllReadsTheNeutralSnapshotInEveryLanguage(): void
    {
        foreach (['nl', 'en', 'de'] as $code) {
            self::assertSame(
                'Oorspronkelijke Productnaam',
                OrderItemNameSnapshot::name(self::LINE, $code, 'Oorspronkelijke Productnaam')
            );
        }
    }

    /**
     * And that holds when the website's languages change under a placed
     * order: removing English, adding German or moving the default language
     * touches neither `order_items.product_name` nor this table, and this
     * class never consults the registry to decide what to fall back to.
     */
    public function testWhatAPlacedOrderSaysDoesNotDependOnTheWebsitesLanguages(): void
    {
        $this->stored(['en' => 'Original Product Name']);

        $before = OrderItemNameSnapshot::name(self::LINE, 'en', 'Oorspronkelijke Productnaam');

        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('de', isDefault: true),
            SiteLanguageFixture::language('fr', sortOrder: 1),
        ]);

        self::assertSame(
            $before,
            OrderItemNameSnapshot::name(self::LINE, 'en', 'Oorspronkelijke Productnaam'),
            'an English document stays English even after English leaves the website'
        );
        self::assertSame(
            'Oorspronkelijke Productnaam',
            OrderItemNameSnapshot::name(self::LINE, 'de', 'Oorspronkelijke Productnaam'),
            'and a brand new language reads the neutral snapshot, not a live product name'
        );
    }

    /* ------------------------------------------------------------------ */
    /* What checkout writes                                                */
    /* ------------------------------------------------------------------ */

    /**
     * One row per OTHER active website language: the default language's name
     * IS the neutral snapshot, so writing it again would be a second copy
     * that could only ever disagree with the first.
     */
    public function testTheExtraLanguagesAreEveryActiveOneExceptTheDefault(): void
    {
        self::assertSame(['en'], OrderItemNameSnapshot::extraLanguages());

        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);

        self::assertSame(['en', 'de'], OrderItemNameSnapshot::extraLanguages());
    }

    public function testASingleLanguageWebsiteKeepsNothingBesideTheNeutralSnapshot(): void
    {
        SiteLanguageFixture::useLanguages([SiteLanguageFixture::language('nl', isDefault: true)]);

        self::assertSame([], OrderItemNameSnapshot::extraLanguages());
    }

    /** An inactive website language is not written either: nobody can read it. */
    public function testAnInactiveLanguageIsNotKept(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', isActive: false, sortOrder: 1),
        ]);

        self::assertSame([], OrderItemNameSnapshot::extraLanguages());
    }

    /**
     * And this class is not the live catalogue: it declares its own table and
     * never touches the one App\Service\ShopLocalization owns.
     */
    public function testASnapshotIsNotTheLiveProductName(): void
    {
        self::assertNotSame(
            ShopLocalization::products()->table()->name,
            OrderItemNameSnapshot::names()->table()->name
        );
    }

    /** @param array<string, string> $byLanguage */
    private function stored(array $byLanguage): void
    {
        $words = [];
        foreach ($byLanguage as $code => $name) {
            $words[$code] = [OrderItemNameSnapshot::PRODUCT_NAME => $name];
        }

        OrderItemNameSnapshot::names()->overrideForTests(self::LINE, $words);
    }
}

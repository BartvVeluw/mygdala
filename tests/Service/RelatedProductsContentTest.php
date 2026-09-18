<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\CollectionRepository;
use App\Repository\ProductRepository;
use App\Repository\SiteSettingRepository;
use App\Service\Language\SiteText;
use App\Service\LocalizedSiteSettings;
use App\Service\RelatedProductsContent;
use App\Service\ShopLocalization;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The rules behind "Gerelateerde producten": which collection a product's
 * related products come from, which products that yields, in which order,
 * and every switch that can make the whole thing disappear.
 *
 * Integration-level against the real dev database, like
 * tests/Service/CollectionRoutingTest.php — the ordering, the visibility
 * filter and the multi-collection tie-break are all SQL plus the existing
 * collection repository, and a mock would only assert that the mock works.
 *
 * Everything created here uses obviously-fake 'zz-test-related-' slugs and
 * is removed in tearDown(), which also restores the four global
 * `site_settings` rows this feature reads, so a test that flips the global
 * switch can never leave the owner's site turned off.
 */
final class RelatedProductsContentTest extends TestCase
{
    private const SLUG_PREFIX = 'zz-test-related-';

    /**
     * The language-NEUTRAL global keys this feature owns; captured in setUp,
     * restored in tearDown. The heading is website text in a language and
     * lives in `site_setting_translations` since Multilingual 2.0 phase 5
     * wave C — see $originalHeadings below.
     */
    private const SETTING_KEYS = [
        'related_products_enabled',
        'related_products_max_items',
    ];

    private CollectionRepository $collections;
    private ProductRepository $products;

    /** @var list<int> */
    private array $collectionIds = [];
    /** @var list<int> */
    private array $productIds = [];
    /** @var array<string, string> */
    private array $originalSettings = [];
    /** @var array<string, string> */
    private array $originalHeadings = [];

    protected function setUp(): void
    {
        $this->collections = new CollectionRepository();
        $this->products = new ProductRepository();

        $stored = (new SiteSettingRepository())->findAll();
        foreach (self::SETTING_KEYS as $key) {
            $this->originalSettings[$key] = $stored[$key] ?? SiteSettings::defaults()[$key];
        }

        $this->originalHeadings = LocalizedSiteSettings::words(LocalizedSiteSettings::RELATED_PRODUCTS_HEADING);

        $this->clearCaches();
    }

    protected function tearDown(): void
    {
        (new SiteSettingRepository())->upsertMany($this->originalSettings);
        $this->setHeadings($this->originalHeadings);

        $db = Database::connection();
        foreach ($this->collectionIds as $id) {
            $db->prepare('DELETE FROM collections WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->productIds as $id) {
            $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
        }

        $this->collectionIds = [];
        $this->productIds = [];
        $this->originalSettings = [];
        $this->originalHeadings = [];

        $this->clearCaches();
    }

    private function clearCaches(): void
    {
        SiteSettings::clearCache();
        LocalizedSiteSettings::clearCache();
        ShopLocalization::clearCache();
        RelatedProductsContent::clearCache();
    }

    /**
     * @param array<string, string> $values
     */
    private function setSettings(array $values): void
    {
        (new SiteSettingRepository())->upsertMany($values);
        $this->clearCaches();
    }

    /**
     * The shop-wide heading per website language. '' removes that language's
     * row, which is what "not translated" means in this storage.
     *
     * @param array<string, string> $byLanguage
     */
    private function setHeadings(array $byLanguage): void
    {
        foreach (['nl', 'en'] as $code) {
            LocalizedSiteSettings::save($code, [
                LocalizedSiteSettings::RELATED_PRODUCTS_HEADING => $byLanguage[$code] ?? '',
            ]);
        }

        $this->clearCaches();
    }

    private function createCollection(string $name, bool $isActive = true, bool $showRelated = true, ?string $headingNl = null, ?string $headingEn = null): int
    {
        $id = $this->collections->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(5)),
            'image_path' => null,
            'is_active' => $isActive,
        ]);

        $this->collectionIds[] = $id;

        ShopLocalization::saveCollection($id, 'nl', [
            ShopLocalization::NAME => $name,
            ShopLocalization::RELATED_HEADING => (string) $headingNl,
        ]);
        ShopLocalization::saveCollection($id, 'en', [
            ShopLocalization::RELATED_HEADING => (string) $headingEn,
        ]);

        if (!$showRelated) {
            $this->collections->updateRelatedProductsSettings($id, $showRelated);
        }

        $this->clearCaches();

        return $id;
    }

    private function createProduct(string $name, bool $active = true): int
    {
        $id = $this->products->create([
            'slug' => self::SLUG_PREFIX . 'product-' . bin2hex(random_bytes(6)),
            'price' => 11.50,
            'image_path' => null,
            'active' => $active,
            'in_shop' => true,
            'in_personalization_catalog' => false,
            'shipping_profile' => 'letter',
            'shipping_weight_grams' => 25,
            'requires_parcel' => false,
        ]);

        ShopLocalization::saveProduct($id, 'nl', [ShopLocalization::NAME => $name]);
        ShopLocalization::clearCache();

        $this->productIds[] = $id;

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /* Defaults: everything is on unless someone turned it off             */
    /* ------------------------------------------------------------------ */

    public function testTheFeatureIsGloballyEnabledByDefault(): void
    {
        $this->assertSame('1', SiteSettings::defaults()['related_products_enabled']);
        $this->assertSame('4', SiteSettings::defaults()['related_products_max_items']);

        // The heading is not a `site_settings` default any more: it is website
        // text in a language, so the generic Dutch one is a seeded
        // `site_setting_translations` row (Multilingual 2.0 phase 5 wave C).
        $this->assertArrayNotHasKey('related_products_heading_nl', SiteSettings::defaults());
        $this->assertArrayNotHasKey('related_products_heading', SiteSettings::defaults());
    }

    public function testEveryExistingCollectionDefaultsToEnabled(): void
    {
        // The migration adds the column with a default, which backfills every
        // row that already existed — there must be no collection in the real
        // database that came out of it switched off.
        foreach ($this->collections->findAll() as $collection) {
            $this->assertArrayHasKey('show_related_products', $collection);
            $this->assertNotNull(
                $collection['show_related_products'],
                ShopLocalization::collectionName((int) $collection['id'])
            );
        }

        $stmt = Database::connection()->query(
            'SELECT COUNT(*) AS c FROM collections WHERE show_related_products IS NULL OR show_related_products = 0'
        );
        $this->assertSame(0, (int) $stmt->fetch()['c'], 'the backfill must leave every existing collection enabled');
    }

    public function testANewlyCreatedCollectionDefaultsToEnabled(): void
    {
        // Created through the ordinary repository create(), which never names
        // the column — so this asserts the DATABASE default, the thing that
        // will still apply to a collection made a year from now.
        $id = $this->collections->create([
            'slug' => self::SLUG_PREFIX . bin2hex(random_bytes(5)),
            'image_path' => null,
            'is_active' => true,
        ]);
        $this->collectionIds[] = $id;

        $stored = $this->collections->findById($id);

        $this->assertNotNull($stored);
        $this->assertSame(1, (int) $stored['show_related_products']);
        $this->assertSame(
            '',
            ShopLocalization::rawCollection($id, ShopLocalization::RELATED_HEADING, 'nl'),
            'no heading override until one is set'
        );
    }

    /* ------------------------------------------------------------------ */
    /* The core behaviour                                                  */
    /* ------------------------------------------------------------------ */

    public function testRelatedProductsAreTheOtherProductsOfTheSameCollectionInItsOrder(): void
    {
        $collection = $this->createCollection('ZZ Bron');

        $a = $this->createProduct('ZZ Product A');
        $b = $this->createProduct('ZZ Product B');
        $c = $this->createProduct('ZZ Product C');
        $d = $this->createProduct('ZZ Product D');

        $this->collections->setCollectionProducts($collection, [$a, $b, $c, $d]);
        RelatedProductsContent::clearCache();

        $onA = RelatedProductsContent::forProduct($a);
        $this->assertNotNull($onA);
        $this->assertSame([$b, $c, $d], $onA['product_ids']);
        $this->assertSame($collection, (int) $onA['collection']['id']);

        RelatedProductsContent::clearCache();
        $onB = RelatedProductsContent::forProduct($b);
        $this->assertNotNull($onB);
        $this->assertSame([$a, $c, $d], $onB['product_ids'], 'the viewed product is the only one removed');
    }

    public function testTheCollectionsOwnOrderIsPreservedRatherThanReSorted(): void
    {
        $collection = $this->createCollection('ZZ Volgorde');

        $a = $this->createProduct('ZZ Volgorde A');
        $b = $this->createProduct('ZZ Volgorde B');
        $c = $this->createProduct('ZZ Volgorde C');

        // Stored in an order that is deliberately not the id order.
        $this->collections->setCollectionProducts($collection, [$c, $a, $b]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($a);

        $this->assertNotNull($result);
        $this->assertSame([$c, $b], $result['product_ids'], 'removing the current product must not reorder the rest');
    }

    public function testTheMaximumIsAppliedAfterFilteringAndKeepsTheCollectionOrder(): void
    {
        $collection = $this->createCollection('ZZ Maximum');

        $a = $this->createProduct('ZZ Max A');
        $b = $this->createProduct('ZZ Max B');
        $c = $this->createProduct('ZZ Max C');
        $d = $this->createProduct('ZZ Max D');
        $e = $this->createProduct('ZZ Max E');

        $this->collections->setCollectionProducts($collection, [$a, $b, $c, $d, $e]);
        $this->setSettings(['related_products_max_items' => '4']);

        // Viewing C: A, B, D, E remain — exactly the example from the spec.
        $result = RelatedProductsContent::forProduct($c);

        $this->assertNotNull($result);
        $this->assertSame([$a, $b, $d, $e], $result['product_ids']);

        $this->setSettings(['related_products_max_items' => '2']);
        $result = RelatedProductsContent::forProduct($c);

        $this->assertNotNull($result);
        $this->assertSame([$a, $b], $result['product_ids'], 'the cap takes the first N of the collection order');
    }

    public function testFewerEligibleProductsThanTheMaximumSimplyShowsFewer(): void
    {
        $collection = $this->createCollection('ZZ Weinig');

        $a = $this->createProduct('ZZ Weinig A');
        $b = $this->createProduct('ZZ Weinig B');

        $this->collections->setCollectionProducts($collection, [$a, $b]);
        $this->setSettings(['related_products_max_items' => '4']);

        $result = RelatedProductsContent::forProduct($a);

        $this->assertNotNull($result);
        $this->assertSame([$b], $result['product_ids'], 'nothing is duplicated or borrowed to fill the limit');
    }

    /* ------------------------------------------------------------------ */
    /* Visibility: the shop's rules, not a second set                      */
    /* ------------------------------------------------------------------ */

    public function testInactiveProductsAreNotShownAsRelatedProducts(): void
    {
        $collection = $this->createCollection('ZZ Zichtbaarheid');

        $visible = $this->createProduct('ZZ Zichtbaar');
        $hidden = $this->createProduct('ZZ Verborgen', false);
        $alsoVisible = $this->createProduct('ZZ Ook zichtbaar');
        $current = $this->createProduct('ZZ Huidig');

        $this->collections->setCollectionProducts($collection, [$current, $visible, $hidden, $alsoVisible]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($current);

        $this->assertNotNull($result);
        $this->assertSame([$visible, $alsoVisible], $result['product_ids']);
    }

    public function testADeletedProductCannotBreakTheSection(): void
    {
        $collection = $this->createCollection('ZZ Verwijderd');

        $current = $this->createProduct('ZZ Blijft');
        $survivor = $this->createProduct('ZZ Overlevende');
        $doomed = $this->createProduct('ZZ Wordt verwijderd');

        $this->collections->setCollectionProducts($collection, [$current, $doomed, $survivor]);
        Database::connection()->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $doomed]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($current);

        $this->assertNotNull($result);
        $this->assertSame([$survivor], $result['product_ids']);
    }

    public function testAnInactiveProductGetsNoRelatedProductsOnItsOwnPage(): void
    {
        $collection = $this->createCollection('ZZ Inactief huidig');

        $current = $this->createProduct('ZZ Inactief huidig product', false);
        $other = $this->createProduct('ZZ Andere');

        $this->collections->setCollectionProducts($collection, [$current, $other]);
        RelatedProductsContent::clearCache();

        $this->assertNull(
            RelatedProductsContent::forProduct($current),
            "an unavailable product's page is an error state, not a place to advertise"
        );
    }

    public function testAnUnknownProductIdYieldsNothing(): void
    {
        $this->assertNull(RelatedProductsContent::forProduct(999999999));
        $this->assertNull(RelatedProductsContent::forProduct(0));
        $this->assertNull(RelatedProductsContent::forProduct(-1));
    }

    /* ------------------------------------------------------------------ */
    /* Nothing to show → no section at all                                 */
    /* ------------------------------------------------------------------ */

    public function testAProductInNoCollectionHasNoRelatedProducts(): void
    {
        $lonely = $this->createProduct('ZZ Zonder collectie');

        $this->assertNull(RelatedProductsContent::forProduct($lonely));
    }

    public function testACollectionHoldingOnlyThisProductYieldsNothing(): void
    {
        $collection = $this->createCollection('ZZ Enig product');
        $only = $this->createProduct('ZZ Enige');

        $this->collections->setCollectionProducts($collection, [$only]);
        RelatedProductsContent::clearCache();

        $this->assertNull(
            RelatedProductsContent::forProduct($only),
            'zero related products means no section, not an empty one'
        );
    }

    public function testACollectionWhoseOtherProductsAreAllHiddenYieldsNothing(): void
    {
        $collection = $this->createCollection('ZZ Alles verborgen');

        $current = $this->createProduct('ZZ Huidig zichtbaar');
        $hidden = $this->createProduct('ZZ Enige andere, verborgen', false);

        $this->collections->setCollectionProducts($collection, [$current, $hidden]);
        RelatedProductsContent::clearCache();

        $this->assertNull(RelatedProductsContent::forProduct($current));
    }

    /* ------------------------------------------------------------------ */
    /* The switches                                                        */
    /* ------------------------------------------------------------------ */

    public function testTheGlobalSwitchHidesRelatedProductsEverywhere(): void
    {
        $collection = $this->createCollection('ZZ Globale schakelaar');

        $a = $this->createProduct('ZZ Globaal A');
        $b = $this->createProduct('ZZ Globaal B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);

        $this->setSettings(['related_products_enabled' => '1']);
        $this->assertNotNull(RelatedProductsContent::forProduct($a), 'on by default');

        $this->setSettings(['related_products_enabled' => '0']);
        $this->assertFalse(RelatedProductsContent::isEnabled());
        $this->assertNull(RelatedProductsContent::forProduct($a), 'the global switch wins over everything else');
        $this->assertNull(RelatedProductsContent::forProduct($b));
    }

    public function testACollectionSwitchedOffIsNeverUsedAsASource(): void
    {
        $collection = $this->createCollection('ZZ Uitgezette collectie', true, false);

        $a = $this->createProduct('ZZ Uit A');
        $b = $this->createProduct('ZZ Uit B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);
        RelatedProductsContent::clearCache();

        $this->assertNull(RelatedProductsContent::forProduct($a));
        $this->assertNull(RelatedProductsContent::sourceCollection($a));
    }

    public function testAnUnpublishedCollectionIsNeverUsedAsASource(): void
    {
        $collection = $this->createCollection('ZZ Inactieve collectie', false, true);

        $a = $this->createProduct('ZZ Inactief A');
        $b = $this->createProduct('ZZ Inactief B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);
        RelatedProductsContent::clearCache();

        $this->assertNull(
            RelatedProductsContent::forProduct($a),
            'a collection with no public page of its own must not drive public content'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Products in more than one collection                                */
    /* ------------------------------------------------------------------ */

    public function testTheFirstEligibleCollectionInTheCmsOrderWins(): void
    {
        $first = $this->createCollection('ZZ Eerste');
        $second = $this->createCollection('ZZ Tweede');

        $shared = $this->createProduct('ZZ Gedeeld product');
        $inFirst = $this->createProduct('ZZ Alleen in eerste');
        $inSecond = $this->createProduct('ZZ Alleen in tweede');

        $this->collections->setCollectionProducts($first, [$shared, $inFirst]);
        $this->collections->setCollectionProducts($second, [$shared, $inSecond]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($shared);

        $this->assertNotNull($result);
        $this->assertSame($first, (int) $result['collection']['id']);
        $this->assertSame([$inFirst], $result['product_ids'], 'exactly one collection is the source — never a merge');
    }

    public function testADisabledFirstCollectionIsSkippedForTheNextEligibleOne(): void
    {
        $disabled = $this->createCollection('ZZ Overgeslagen', true, false);
        $enabled = $this->createCollection('ZZ Gebruikt', true, true);

        $shared = $this->createProduct('ZZ Gedeeld tweede');
        $inDisabled = $this->createProduct('ZZ In uitgezette');
        $inEnabled = $this->createProduct('ZZ In aangezette');

        $this->collections->setCollectionProducts($disabled, [$shared, $inDisabled]);
        $this->collections->setCollectionProducts($enabled, [$shared, $inEnabled]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($shared);

        $this->assertNotNull($result);
        $this->assertSame($enabled, (int) $result['collection']['id']);
        $this->assertSame([$inEnabled], $result['product_ids']);
    }

    public function testTheChoiceOfCollectionIsDeterministicAcrossRepeatedCalls(): void
    {
        $first = $this->createCollection('ZZ Deterministisch een');
        $second = $this->createCollection('ZZ Deterministisch twee');

        $shared = $this->createProduct('ZZ Deterministisch gedeeld');
        $this->collections->setCollectionProducts($first, [$shared, $this->createProduct('ZZ D1')]);
        $this->collections->setCollectionProducts($second, [$shared, $this->createProduct('ZZ D2')]);

        $seen = [];
        for ($i = 0; $i < 5; $i++) {
            RelatedProductsContent::clearCache();
            $seen[] = (int) RelatedProductsContent::forProduct($shared)['collection']['id'];
        }

        $this->assertSame([$first, $first, $first, $first, $first], $seen);
    }

    public function testAnEligibleFirstCollectionWithNoOtherVisibleProductsDoesNotFallThrough(): void
    {
        // Documented, deliberate behaviour: "eligible" is a configuration
        // property (published + switched on), not a content one — so what an
        // owner sees on the Gerelateerde producten screen is what decides the
        // source, even when that source turns out to be empty.
        $first = $this->createCollection('ZZ Leeg maar geschikt');
        $second = $this->createCollection('ZZ Vol maar tweede');

        $shared = $this->createProduct('ZZ Gedeeld leeg');
        $other = $this->createProduct('ZZ Andere in tweede');

        $this->collections->setCollectionProducts($first, [$shared]);
        $this->collections->setCollectionProducts($second, [$shared, $other]);
        RelatedProductsContent::clearCache();

        $this->assertNull(RelatedProductsContent::forProduct($shared));
    }

    /* ------------------------------------------------------------------ */
    /* Heading                                                             */
    /* ------------------------------------------------------------------ */

    public function testTheGlobalHeadingIsUsedWhenACollectionHasNoOverride(): void
    {
        $collection = $this->createCollection('ZZ Kop globaal');
        $a = $this->createProduct('ZZ Kop A');
        $b = $this->createProduct('ZZ Kop B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);

        $this->setHeadings(['nl' => 'Gerelateerde producten']);

        $result = RelatedProductsContent::forProduct($a);

        $this->assertNotNull($result);
        $this->assertSame('Gerelateerde producten', $result['heading']->in('nl'));
        $this->assertSame(
            'Gerelateerde producten',
            $result['heading']->in('en'),
            'an untranslated heading falls back to the default language'
        );
    }

    public function testACollectionCanOverrideTheHeading(): void
    {
        $collection = $this->createCollection('ZZ Kop override', true, true, 'Meer onderzetters bekijken', 'More coasters');
        $a = $this->createProduct('ZZ Override A');
        $b = $this->createProduct('ZZ Override B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);
        RelatedProductsContent::clearCache();

        $result = RelatedProductsContent::forProduct($a);

        $this->assertNotNull($result);
        $this->assertSame('Meer onderzetters bekijken', $result['heading']->in('nl'));
        $this->assertSame('More coasters', $result['heading']->in('en'));
    }

    public function testACollectionOverrideWithoutAnEnglishValueFallsBackToItsOwnDutchOverride(): void
    {
        $collection = $this->createCollection('ZZ Kop half', true, true, 'Meer onderzetters bekijken', null);
        $a = $this->createProduct('ZZ Half A');
        $b = $this->createProduct('ZZ Half B');
        $this->collections->setCollectionProducts($collection, [$a, $b]);

        $this->setHeadings(['nl' => 'Gerelateerde producten', 'en' => 'Related products']);

        $result = RelatedProductsContent::forProduct($a);

        $this->assertNotNull($result);
        $this->assertSame(
            'Meer onderzetters bekijken',
            $result['heading']->in('en'),
            "a collection's own override must win over the global EN heading, not mix with it"
        );
    }

    /**
     * The whole precedence chain in one place, because it is the one rule of
     * this feature that reads differently after Multilingual 2.0 phase 5
     * wave C: the collection's own words win as a UNIT, and within each
     * source the ordinary fallback applies (App\Service\Language\LanguageFallback,
     * applied twice in source order — there is no second fallback of this
     * feature's own).
     *
     * On a Dutch-default site these five steps produce exactly the two fixed
     * chains the Shop had before the wave, value for value, which is what
     * makes the change safe to ship.
     */
    public function testTheHeadingPrecedenceIsTheCollectionThenTheGlobalSetting(): void
    {
        $a = $this->createProduct('ZZ Precedentie A');
        $b = $this->createProduct('ZZ Precedentie B');

        // 1 + 2: the collection speaks for itself, in the language it has.
        $own = $this->createCollection('ZZ Precedentie eigen', true, true, 'Eigen kop', null);
        $this->collections->setCollectionProducts($own, [$a, $b]);
        $this->setHeadings(['nl' => 'Globale kop', 'en' => 'Global heading']);

        $result = RelatedProductsContent::forProduct($a);
        $this->assertNotNull($result);
        $this->assertSame('Eigen kop', $result['heading']->in('nl'), '1: the collection in this language');
        $this->assertSame('Eigen kop', $result['heading']->in('en'), '2: the collection in the default language');

        // 3 + 4: no heading of its own, so the global setting, with the same
        // fallback inside it.
        $this->collections->setCollectionProducts($own, []);
        $bare = $this->createCollection('ZZ Precedentie kaal');
        $this->collections->setCollectionProducts($bare, [$a, $b]);
        $this->setHeadings(['nl' => 'Globale kop', 'en' => 'Global heading']);

        $result = RelatedProductsContent::forProduct($a);
        $this->assertNotNull($result);
        $this->assertSame('Globale kop', $result['heading']->in('nl'), '3: the setting in this language');

        $this->setHeadings(['nl' => 'Globale kop']);
        $result = RelatedProductsContent::forProduct($a);
        $this->assertNotNull($result);
        $this->assertSame('Globale kop', $result['heading']->in('en'), '4: the setting in the default language');

        // 5: nothing anywhere is nothing, and then the block renders no
        // heading rather than an empty one.
        $this->setHeadings([]);
        $result = RelatedProductsContent::forProduct($a);
        $this->assertNotNull($result);
        $this->assertSame('', SiteText::visibleOf($result['heading']), '5: no heading at all');
    }

    /* ------------------------------------------------------------------ */
    /* The maximum setting itself                                          */
    /* ------------------------------------------------------------------ */

    public function testTheMaximumIsValidatedAndClamped(): void
    {
        $this->assertNull(RelatedProductsContent::validateMaxItems('4'));
        $this->assertNull(RelatedProductsContent::validateMaxItems((string) RelatedProductsContent::MIN_MAX_ITEMS));
        $this->assertNull(RelatedProductsContent::validateMaxItems((string) RelatedProductsContent::MAX_MAX_ITEMS));

        $this->assertNotNull(RelatedProductsContent::validateMaxItems(''));
        $this->assertNotNull(RelatedProductsContent::validateMaxItems('0'));
        $this->assertNotNull(RelatedProductsContent::validateMaxItems('-3'));
        $this->assertNotNull(RelatedProductsContent::validateMaxItems('abc'));
        $this->assertNotNull(RelatedProductsContent::validateMaxItems('4.5'));
        $this->assertNotNull(RelatedProductsContent::validateMaxItems((string) (RelatedProductsContent::MAX_MAX_ITEMS + 1)));

        // A value written straight into the database still cannot make a
        // product page render nothing, or the whole catalogue.
        $this->setSettings(['related_products_max_items' => '0']);
        $this->assertSame(RelatedProductsContent::MIN_MAX_ITEMS, RelatedProductsContent::maxItems());

        $this->setSettings(['related_products_max_items' => '9999']);
        $this->assertSame(RelatedProductsContent::MAX_MAX_ITEMS, RelatedProductsContent::maxItems());
    }
}

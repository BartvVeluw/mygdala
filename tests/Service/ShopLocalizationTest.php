<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\ShopLocalization;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The Shop's words per website language (Multilingual 2.0 phase 5 wave C,
 * docs/multilingual/ARCHITECTURE.md, MODULES.md "Shop"), without a database:
 * the two stores are App\Service\Language\EntityTranslations, so this file
 * asks what App\Service\ShopLocalization adds on top of it — which field
 * belongs to which store, the lengths the old columns had, the fallback a
 * visitor gets, the one sanitizer on `description`, and above all the line
 * that WORDS ARE NOT IDENTITY.
 *
 * The real SQL is Tests\Service\CollectionContentTest's and
 * Tests\Repository\CollectionRepositoryIntegrationTest's, the endpoints are
 * Tests\Service\ShopEditingHttpTest's, and the backfill is
 * Tests\Install\ShopWordsMigrationTest's.
 */
final class ShopLocalizationTest extends TestCase
{
    private const PRODUCT = 81;
    private const COLLECTION = 82;

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
        ShopLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        ShopLocalization::clearCache();
        SiteLanguageFixture::reset();
    }

    /* ------------------------------------------------------------------ */
    /* Which field lives where                                             */
    /* ------------------------------------------------------------------ */

    public function testEachStoreDeclaresItsOwnTableAndFields(): void
    {
        $products = ShopLocalization::products()->table();
        $collections = ShopLocalization::collections()->table();

        self::assertSame('product_translations', $products->name);
        self::assertSame('product_id', $products->ownerColumn);
        self::assertSame(['name', 'description', 'meta_title', 'meta_description'], $products->fieldNames());

        self::assertSame('collection_translations', $collections->name);
        self::assertSame('collection_id', $collections->ownerColumn);
        self::assertSame(
            ['slug', 'name', 'description', 'meta_title', 'meta_description', 'related_heading'],
            $collections->fieldNames(),
            'a collection has two fields more than a product: its own address per language, and its own heading above the related products'
        );
    }

    /**
     * THE LINE THIS WHOLE WAVE HANGS ON: a store of the Shop's words knows
     * nothing a shop DECIDES with. No slug, no price, no stock, no sku, no
     * channel switch, no image path, no sort order. A visitor switching
     * language therefore cannot change which product they are looking at or
     * what it costs (MODULES.md "Shop").
     */
    /**
     * A COLLECTION's address is stored per language since Multilingual 2.0
     * phase 6 (docs/multilingual/ROUTING.md) — and a PRODUCT's is not, because
     * a product has no slug URL at all: it is one page at /product.php?id=…
     * however many collections it appears in (App\Service\ProductSeo).
     */
    public function testOnlyTheThingWithAUrlHasAnAddressPerLanguage(): void
    {
        self::assertTrue(ShopLocalization::collections()->table()->hasSlug());
        self::assertFalse(
            ShopLocalization::products()->table()->hasSlug(),
            'a product is reached by id, so a slug here would be a column nothing reads'
        );
    }

    public function testNoStoreOfShopWordsKnowsAnythingAShopDecidesWith(): void
    {
        foreach ([ShopLocalization::products(), ShopLocalization::collections()] as $store) {
            foreach ([
                'id', 'price', 'stock', 'sku', 'image_path', 'og_image_path',
                'active', 'in_shop', 'in_personalization_catalog', 'is_active',
                'show_related_products', 'sort_order',
                'shipping_profile', 'shipping_weight_grams', 'requires_parcel',
            ] as $neutral) {
                self::assertNotContains($neutral, $store->table()->fieldNames(), $store->table()->name . '.' . $neutral);
            }
        }
    }

    public function testTheLengthsAreTheOnesTheOldColumnsHad(): void
    {
        self::assertSame(150, ShopLocalization::NAME_MAX_LENGTH);
        self::assertSame(255, ShopLocalization::META_TITLE_MAX_LENGTH);
        self::assertSame(500, ShopLocalization::META_DESCRIPTION_MAX_LENGTH);
        self::assertSame(255, ShopLocalization::RELATED_HEADING_MAX_LENGTH);

        // And a word past its length is refused, not cut down.
        self::assertSame(
            [ShopLocalization::NAME => 'too_long'],
            ShopLocalization::products()->problems('nl', [ShopLocalization::NAME => str_repeat('a', 151)])
        );
        self::assertSame([], ShopLocalization::products()->problems('nl', [ShopLocalization::NAME => str_repeat('a', 150)]));
    }

    public function testAFieldOfAnotherDomainIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShopLocalization::product(self::PRODUCT, 'title', 'nl');
    }

    /** `related_heading` belongs to a collection, and to nothing else. */
    public function testAProductHasNoRelatedHeading(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ShopLocalization::product(self::PRODUCT, ShopLocalization::RELATED_HEADING, 'nl');
    }

    /* ------------------------------------------------------------------ */
    /* The fallback                                                        */
    /* ------------------------------------------------------------------ */

    public function testAVisitorGetsTheAskedForLanguageThenTheDefaultThenNothing(): void
    {
        $this->productWords([
            'nl' => [ShopLocalization::NAME => 'Houten onderzetter', ShopLocalization::META_TITLE => 'Onderzetters'],
            'en' => [ShopLocalization::NAME => 'Wooden coaster'],
        ]);

        self::assertSame('Houten onderzetter', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'nl'));
        self::assertSame('Wooden coaster', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'en'));
        self::assertSame(
            'Onderzetters',
            ShopLocalization::product(self::PRODUCT, ShopLocalization::META_TITLE, 'en'),
            'an untranslated SEO title falls back to the default language'
        );
        self::assertSame(
            '',
            ShopLocalization::product(self::PRODUCT, ShopLocalization::META_DESCRIPTION, 'en'),
            'nothing in either language is nothing'
        );
    }

    /** raw() is for an editor: what is stored in THIS language, no fallback. */
    public function testAnEditorSeesOnlyWhatIsStoredInTheLanguageOnScreen(): void
    {
        $this->productWords(['nl' => [ShopLocalization::NAME => 'Houten onderzetter']]);

        self::assertSame('Houten onderzetter', ShopLocalization::rawProduct(self::PRODUCT, ShopLocalization::NAME, 'nl'));
        self::assertSame('', ShopLocalization::rawProduct(self::PRODUCT, ShopLocalization::NAME, 'en'));
    }

    /**
     * THE DEFAULT LANGUAGE DECIDES whether a product has a name at all. A
     * product named only in a translation has no name in the CMS either: the
     * chain ends at the default language, and there is no "some other
     * language will do" step. Same rule as
     * Tests\Service\PortfolioLocalizationTest pins for a card.
     */
    public function testOnlyTheDefaultLanguageDecidesWhetherAProductIsNamedForAVisitor(): void
    {
        $this->productWords(['en' => [ShopLocalization::NAME => 'Wooden coaster']]);

        self::assertSame('', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'nl'));
        self::assertSame('Wooden coaster', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'en'));

        // The CMS is the one exception, and deliberately so: an editor must be
        // able to FIND a product whose default language is still empty.
        self::assertSame('Wooden coaster', ShopLocalization::productName(self::PRODUCT));
    }

    public function testACollectionFallsBackTheSameWay(): void
    {
        ShopLocalization::collections()->overrideForTests(self::COLLECTION, [
            'nl' => [ShopLocalization::NAME => 'Onderzetters', ShopLocalization::RELATED_HEADING => 'Meer hiervan'],
            'en' => [ShopLocalization::NAME => 'Coasters'],
        ]);

        self::assertSame('Coasters', ShopLocalization::collection(self::COLLECTION, ShopLocalization::NAME, 'en'));
        self::assertSame(
            'Meer hiervan',
            ShopLocalization::collection(self::COLLECTION, ShopLocalization::RELATED_HEADING, 'en'),
            'an untranslated heading falls back'
        );
        self::assertSame('Onderzetters', ShopLocalization::collection(self::COLLECTION, ShopLocalization::NAME, 'nl'));
    }

    /* ------------------------------------------------------------------ */
    /* Rich text                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * `description` is the one rich field, and it goes through the Shop's own
     * sanitizer on the way OUT as well as on the way in. Nothing a careless
     * import or a direct database write put there can reach a page.
     */
    public function testTheDescriptionIsSanitizedOnRead(): void
    {
        $this->productWords([
            'nl' => [ShopLocalization::DESCRIPTION => '<p>Veilig</p><script>alert(1)</script>'],
        ]);

        $html = ShopLocalization::productDescription(self::PRODUCT, 'nl');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('Veilig', $html);
    }

    /**
     * Each half is sanitized BEFORE the fallback runs, so a language whose
     * markup sanitizes away to nothing simply has no description and the
     * fallback takes over — rather than a visitor getting an empty block.
     */
    public function testALanguageWhoseMarkupSanitizesAwayFallsBack(): void
    {
        $this->productWords([
            'nl' => [ShopLocalization::DESCRIPTION => '<p>Van berkenhout.</p>'],
            'en' => [ShopLocalization::DESCRIPTION => '<script>alert(1)</script>'],
        ]);

        self::assertStringContainsString('Van berkenhout.', ShopLocalization::productDescription(self::PRODUCT, 'nl'));
        self::assertStringContainsString(
            'Van berkenhout.',
            ShopLocalization::productDescription(self::PRODUCT, 'en'),
            'markup that sanitizes away is no translation'
        );
    }

    /* ------------------------------------------------------------------ */
    /* A third language                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * German is a row in `site_languages`, not a line of PHP: no schema change
     * and no code change here.
     */
    public function testAThirdLanguageNeedsNoSchemaOrCodeChange(): void
    {
        SiteLanguageFixture::useLanguages([
            SiteLanguageFixture::language('nl', isDefault: true),
            SiteLanguageFixture::language('en', sortOrder: 1),
            SiteLanguageFixture::language('de', sortOrder: 2),
        ]);
        ShopLocalization::clearCache();

        $this->productWords([
            'nl' => [ShopLocalization::NAME => 'Houten onderzetter'],
            'de' => [ShopLocalization::NAME => 'Holzuntersetzer'],
        ]);

        self::assertSame('Holzuntersetzer', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'de'));
        self::assertSame(
            'Houten onderzetter',
            ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'en'),
            'and the one that has no German falls back like any other'
        );
    }

    /** A website with one language says the same thing, with no fallback to make. */
    public function testASingleLanguageWebsiteNeedsNoFallback(): void
    {
        SiteLanguageFixture::useLanguages([SiteLanguageFixture::language('nl', isDefault: true)]);
        ShopLocalization::clearCache();

        $this->productWords(['nl' => [ShopLocalization::NAME => 'Houten onderzetter']]);

        self::assertSame('Houten onderzetter', ShopLocalization::product(self::PRODUCT, ShopLocalization::NAME, 'nl'));
        self::assertSame('nl', ShopLocalization::defaultLanguage());
    }

    /** @param array<string, array<string, string>> $words */
    private function productWords(array $words): void
    {
        ShopLocalization::products()->overrideForTests(self::PRODUCT, $words);
    }
}

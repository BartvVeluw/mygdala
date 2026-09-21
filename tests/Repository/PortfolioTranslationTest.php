<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;

/**
 * The Portfolio's words, stored per website language (Multilingual 2.0
 * phase 5 wave A, docs/multilingual/ARCHITECTURE.md), against the real test
 * database. Same shape and same questions as
 * Tests\Repository\NavigationFooterTranslationTest, which this file follows.
 *
 * SAVING ONE LANGUAGE NEVER TOUCHES ANOTHER. That used to be a property of
 * a form that posted a Dutch and an English column at once; it is now a
 * property of the storage itself, one row per owner per language — including
 * for a third language that is nothing but a row in site_languages.
 *
 * AND THE FIELDS A FORM DOES NOT SHOW ARE NOT EMPTIED. The item editor shows
 * title, subtitle and alt text, never the old project page's intro and
 * description; saving from that form must leave those two exactly as they
 * are, in the language being saved.
 *
 * Plus the schema rules no code can walk past: words go with their owner
 * (CASCADE, including a photo's alt text when its item goes), a language that
 * still has words cannot be deleted (RESTRICT), and a language with nothing
 * in it has no row.
 *
 * Every row is this test's own, marked zz-, and removed by id in tearDown();
 * the German language row too.
 */
final class PortfolioTranslationTest extends TestCase
{
    private PortfolioGalleryRepository $gallery;
    private PortfolioCategoryRepository $categories;

    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    private bool $addedGerman = false;

    protected function setUp(): void
    {
        $this->gallery = new PortfolioGalleryRepository();
        $this->categories = new PortfolioCategoryRepository();

        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');
    }

    protected function tearDown(): void
    {
        foreach ($this->itemIds as $id) {
            $this->gallery->deleteItem($id);
        }
        foreach ($this->categoryIds as $id) {
            $this->categories->delete($id);
        }

        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        $this->itemIds = [];
        $this->categoryIds = [];

        PortfolioGalleryContent::clearCache();
        SiteLanguages::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* One language at a time                                              */
    /* ------------------------------------------------------------------ */

    public function testSavingOneLanguageOfAnItemLeavesEveryOtherLanguageAlone(): void
    {
        $id = $this->item();
        $this->saveWords($id, 'nl', ['title' => 'zz Houten bord', 'subtitle' => 'zz Eiken']);
        $this->saveWords($id, 'en', ['title' => 'zz Wooden sign', 'subtitle' => 'zz Oak']);

        $this->saveWords($id, 'nl', ['title' => 'zz Eiken bord', 'subtitle' => 'zz Eiken']);

        self::assertSame(['zz Eiken bord', 'zz Wooden sign'], $this->storedTitles($id));

        $this->saveWords($id, 'en', ['title' => 'zz Oak sign', 'subtitle' => 'zz Oak']);

        self::assertSame(['zz Eiken bord', 'zz Oak sign'], $this->storedTitles($id));
    }

    public function testSavingOneLanguageOfACategoryLeavesTheOtherAlone(): void
    {
        $id = $this->category();
        PortfolioLocalization::saveCategory($id, 'nl', 'zz Hout');
        PortfolioLocalization::saveCategory($id, 'en', 'zz Wood');

        PortfolioLocalization::saveCategory($id, 'en', 'zz Timber');
        PortfolioLocalization::clearCache();

        self::assertSame('zz Hout', PortfolioLocalization::rawCategoryName($id, 'nl'));
        self::assertSame('zz Timber', PortfolioLocalization::rawCategoryName($id, 'en'));
    }

    /**
     * The three fields the item editor shows are the only ones it sends, so
     * the old project page's rich text keeps exactly what it holds — in the
     * language being saved, and in every other.
     */
    public function testAFormThatDoesNotShowAFieldCannotEmptyIt(): void
    {
        $id = $this->item();
        PortfolioLocalization::saveItem($id, 'nl', [
            'title' => 'zz Oud project',
            'intro' => '<p>zz De inleiding</p>',
            'description' => '<p>zz De beschrijving</p>',
        ]);

        // What api/admin/update-portfolio-item.php sends: three fields.
        $this->saveWords($id, 'nl', ['title' => 'zz Oud project, hernoemd', 'subtitle' => '', 'alt' => '']);
        PortfolioLocalization::clearCache();

        self::assertSame('zz Oud project, hernoemd', PortfolioLocalization::rawItemValue($id, 'title', 'nl'));
        self::assertSame('<p>zz De inleiding</p>', PortfolioLocalization::rawItemValue($id, 'intro', 'nl'));
        self::assertSame('<p>zz De beschrijving</p>', PortfolioLocalization::rawItemValue($id, 'description', 'nl'));
    }

    public function testALanguageLeftWithNoWordsAtAllHasNoRow(): void
    {
        $id = $this->item();
        $this->saveWords($id, 'nl', ['title' => 'zz Blijft']);
        $this->saveWords($id, 'en', ['title' => 'zz Stays']);

        $this->saveWords($id, 'en', ['title' => '   ', 'subtitle' => '', 'alt' => '']);

        self::assertSame(['nl'], $this->languagesWithARow('portfolio_item_translations', 'portfolio_item_id', $id));
        self::assertSame(['zz Blijft', ''], $this->storedTitles($id));
    }

    public function testALanguageTheRegistryDoesNotHaveIsRefused(): void
    {
        $id = $this->item();

        $this->expectException(\InvalidArgumentException::class);
        PortfolioLocalization::saveItem($id, 'xx', ['title' => 'zz Nope']);
    }

    public function testATitleLongerThanItsColumnIsRefusedRatherThanCut(): void
    {
        $id = $this->item();

        $this->expectException(\InvalidArgumentException::class);
        PortfolioLocalization::saveItem($id, 'nl', [
            'title' => str_repeat('a', PortfolioLocalization::TITLE_MAX_LENGTH + 1),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* A third language                                                    */
    /* ------------------------------------------------------------------ */

    public function testAThirdLanguageIsOnlyARowInTheRegistry(): void
    {
        $this->addGerman();
        $id = $this->item();
        $this->saveWords($id, 'nl', ['title' => 'zz Houten bord', 'subtitle' => 'zz Eiken']);
        $this->saveWords($id, 'en', ['title' => 'zz Wooden sign']);

        $this->saveWords($id, 'de', ['title' => 'zz Holzschild']);
        PortfolioLocalization::clearCache();

        self::assertSame('zz Holzschild', PortfolioLocalization::rawItemValue($id, 'title', 'de'));
        self::assertSame(['zz Houten bord', 'zz Wooden sign'], $this->storedTitles($id), 'German left Dutch and English alone');
        self::assertSame(
            'zz Eiken',
            PortfolioLocalization::items()->value($id, 'subtitle', 'de'),
            'a field without German words falls back to the default language'
        );
    }

    /* ------------------------------------------------------------------ */
    /* What the schema guarantees                                          */
    /* ------------------------------------------------------------------ */

    /**
     * No PHP step before the DELETE, unlike a block's words in the polymorphic
     * block_translations: these three tables have a real foreign key with ON
     * DELETE CASCADE, and a photo's alt text hangs off the photo, which hangs
     * off the item.
     */
    public function testDeletingAnItemTakesItsWordsAndItsPhotosAltTextsInEveryLanguage(): void
    {
        $id = $this->item();
        $this->saveWords($id, 'nl', ['title' => 'zz Weg']);
        $this->saveWords($id, 'en', ['title' => 'zz Gone']);

        $imageId = $this->photo($id);
        PortfolioLocalization::images()->save($imageId, 'nl', ['alt' => 'zz Detailfoto']);
        PortfolioLocalization::images()->save($imageId, 'en', ['alt' => 'zz Detail photo']);

        self::assertSame(['nl', 'en'], $this->languagesWithARow('portfolio_item_image_translations', 'portfolio_item_image_id', $imageId));

        $this->gallery->deleteItem($id);
        $this->itemIds = array_values(array_filter($this->itemIds, static fn (int $kept): bool => $kept !== $id));

        self::assertSame([], $this->languagesWithARow('portfolio_item_translations', 'portfolio_item_id', $id));
        self::assertSame([], $this->languagesWithARow('portfolio_item_image_translations', 'portfolio_item_image_id', $imageId));
    }

    public function testDeletingACategoryTakesItsNameInEveryLanguage(): void
    {
        $id = $this->category();
        PortfolioLocalization::saveCategory($id, 'nl', 'zz Weg');
        PortfolioLocalization::saveCategory($id, 'en', 'zz Gone');

        $this->categories->delete($id);
        $this->categoryIds = array_values(array_filter($this->categoryIds, static fn (int $kept): bool => $kept !== $id));

        self::assertSame([], $this->languagesWithARow('portfolio_category_translations', 'portfolio_category_id', $id));
    }

    public function testALanguageThatStillHasPortfolioWordsCannotBeDeleted(): void
    {
        $this->addGerman();
        $id = $this->item();
        $this->saveWords($id, 'de', ['title' => 'zz Holzschild']);

        try {
            Database::connection()->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            self::fail('the foreign key should have refused deleting a language with words');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        $this->gallery->deleteItem($id);
        $this->itemIds = array_values(array_filter($this->itemIds, static fn (int $kept): bool => $kept !== $id));
    }

    /* ------------------------------------------------------------------ */
    /* What a card prints                                                  */
    /* ------------------------------------------------------------------ */

    public function testACardReadsTheLanguageOfTheRequestWithTheFallback(): void
    {
        $id = $this->item();
        $this->saveWords($id, 'nl', ['title' => 'zz Houten bord', 'alt' => 'zz Een houten bord']);
        $this->saveWords($id, 'en', ['title' => 'zz Wooden sign']);
        PortfolioGalleryContent::clearCache();

        $card = static function () use ($id): ?array {
            foreach (PortfolioGalleryContent::catalogueItems(false) as $card) {
                if (($card['id'] ?? null) === $id || $card['title'] === 'zz Houten bord' || $card['title'] === 'zz Wooden sign') {
                    return $card;
                }
            }

            return null;
        };

        $dutch = $this->in('nl', $card);
        $english = $this->in('en', $card);

        self::assertNotNull($dutch);
        self::assertNotNull($english);
        self::assertSame('zz Houten bord', $dutch['title']);
        self::assertSame('zz Wooden sign', $english['title']);
        self::assertSame('zz Een houten bord', $english['alt'], 'an untranslated alt text is the default language');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function item(): int
    {
        $id = $this->gallery->createItem((int) $this->gallery->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-translation-' . bin2hex(random_bytes(4)) . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        return $id;
    }

    private function category(): int
    {
        $id = $this->categories->create('zz-translation-' . bin2hex(random_bytes(4)));
        $this->categoryIds[] = $id;

        return $id;
    }

    /**
     * One extra photo of the old project page. No screen adds these any more
     * (App\Repository\PortfolioItemImageRepository is read-only), so the
     * fixture inserts it the way the old editor did.
     */
    private function photo(int $itemId): int
    {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO portfolio_item_images (portfolio_item_id, image_path, sort_order, created_at, updated_at)
             VALUES (:item, :path, 0, NOW(), NOW())'
        )->execute(['item' => $itemId, 'path' => 'assets/images/sections/zz-extra-' . bin2hex(random_bytes(4)) . '.jpg']);

        return (int) $db->lastInsertId();
    }

    /** @param array<string, string> $words */
    private function saveWords(int $itemId, string $language, array $words): void
    {
        PortfolioLocalization::saveItem($itemId, $language, $words);
    }

    /** @return array{0: string, 1: string} the stored Dutch and English title, no fallback */
    private function storedTitles(int $id): array
    {
        PortfolioLocalization::clearCache();

        return [
            PortfolioLocalization::rawItemValue($id, 'title', 'nl'),
            PortfolioLocalization::rawItemValue($id, 'title', 'en'),
        ];
    }

    /** @return list<string> */
    private function languagesWithARow(string $table, string $ownerColumn, int $id): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT language_code FROM {$table} WHERE {$ownerColumn} = :id ORDER BY language_code DESC"
        );
        $stmt->execute(['id' => $id]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function addGerman(): void
    {
        if (!SiteLanguages::exists('de')) {
            (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
            $this->addedGerman = true;
            SiteLanguages::clearCache();
        }
    }

    /**
     * Run $work while the request is answered in $language.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function in(string $language, \Closure $work): mixed
    {
        RequestLanguage::set($language, true);

        try {
            return $work();
        } finally {
            RequestLanguage::reset();
        }
    }
}

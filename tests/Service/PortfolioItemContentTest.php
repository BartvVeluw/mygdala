<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\Language\LocalizedValue;
use App\Service\Language\SiteText;
use App\Service\PortfolioGalleryContent;
use App\Service\PortfolioLocalization;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/partials/section-item-gallery.php';

/**
 * A portfolio item with nothing but an image, and one without a category,
 * from storage to the page: stored with empty words, read back without
 * surprises, listed in the gallery, left alone by the filter bar, and drawn
 * without an empty caption over the picture.
 *
 * Against the test database, through the repositories and the read model the
 * site itself uses. The endpoints that accept such an item are
 * Tests\Service\PortfolioItemEditingHttpTest. Everything made here is its own
 * and removed again in tearDown(); the image paths point nowhere, because
 * nothing here reads a file.
 */
final class PortfolioItemContentTest extends TestCase
{
    /** @var list<int> */
    private array $itemIds = [];

    /** @var list<int> */
    private array $categoryIds = [];

    protected function tearDown(): void
    {
        $gallery = new PortfolioGalleryRepository();
        foreach ($this->itemIds as $id) {
            $gallery->deleteItem($id);
        }

        // After the items: a category still linked to one cannot be deleted
        // (portfolio_item_categories' RESTRICT), and these are only ours.
        $categories = new PortfolioCategoryRepository();
        foreach ($this->categoryIds as $id) {
            $categories->delete($id);
        }

        $this->itemIds = [];
        $this->categoryIds = [];

        PortfolioGalleryContent::clearCache();
    }

    /**
     * A picture with no words at all: since Multilingual 2.0 phase 5 wave A
     * that is not three empty columns but NO ROW in
     * portfolio_item_translations — "not written" and "written as nothing" are
     * one state, and the card still renders (alt="", no overlay).
     */
    public function testAnItemWithOnlyAnImageHasNoWordsInAnyLanguageAndNoCategory(): void
    {
        $repository = new PortfolioGalleryRepository();
        $id = $this->item([]);

        $this->assertSame([], PortfolioLocalization::items()->words($id));

        foreach ([PortfolioLocalization::TITLE, PortfolioLocalization::ALT, PortfolioLocalization::SUBTITLE] as $field) {
            $this->assertSame('', PortfolioLocalization::rawItemValue($id, $field, 'nl'), $field . ' has no words');
            $this->assertSame('', PortfolioLocalization::rawItemValue($id, $field, 'en'));
        }

        $this->assertSame([], $repository->categoryIdsForItem($id));
        $this->assertSame([], $repository->categorySlugsByItemIds([$id]));
        $this->assertSame([], $repository->categoriesForItemId($id));
    }

    public function testFilledInWordsAndCategoriesComeBackExactlyAsStored(): void
    {
        $repository = new PortfolioGalleryRepository();
        $first = $this->category();
        $second = $this->category();

        $id = $this->item([
            'nl' => ['title' => 'ZZ Skyline', 'alt' => 'Houten skyline van een stad', 'subtitle' => 'Wanddecoratie, hout'],
            'en' => ['title' => 'ZZ Skyline EN', 'alt' => 'Wooden city skyline', 'subtitle' => 'Wall decor, wood'],
        ], [(int) $first['id'], (int) $second['id']]);

        PortfolioLocalization::clearCache();

        $this->assertSame('ZZ Skyline', PortfolioLocalization::rawItemValue($id, 'title', 'nl'));
        $this->assertSame('ZZ Skyline EN', PortfolioLocalization::rawItemValue($id, 'title', 'en'));
        $this->assertSame('Houten skyline van een stad', PortfolioLocalization::rawItemValue($id, 'alt', 'nl'));
        $this->assertSame('Wooden city skyline', PortfolioLocalization::rawItemValue($id, 'alt', 'en'));
        $this->assertSame('Wanddecoratie, hout', PortfolioLocalization::rawItemValue($id, 'subtitle', 'nl'));
        $this->assertSame('Wall decor, wood', PortfolioLocalization::rawItemValue($id, 'subtitle', 'en'));

        $this->assertEqualsCanonicalizing([(int) $first['id'], (int) $second['id']], $repository->categoryIdsForItem($id));
    }

    /**
     * Taking the last category off an item is an ordinary save: no link row
     * is left, the category itself stays for every other item, and it can be
     * deleted afterwards because nothing uses it any more.
     */
    public function testTheLastCategoryCanBeTakenOffAnItem(): void
    {
        $repository = new PortfolioGalleryRepository();
        $category = $this->category();
        $id = $this->item(['nl' => ['title' => 'ZZ Met categorie']], [(int) $category['id']]);

        $repository->setItemCategories($id, []);

        $this->assertSame([], $repository->categoryIdsForItem($id));
        $this->assertNotNull((new PortfolioCategoryRepository())->findById((int) $category['id']));

        foreach ((new PortfolioCategoryRepository())->findAllWithUsageCounts() as $row) {
            if ((int) $row['id'] === (int) $category['id']) {
                $this->assertSame(0, (int) $row['item_count'], 'an unused category may be deleted again');
            }
        }
    }

    /**
     * An item without a category is an ordinary gallery item. The filter bar
     * is built from the categories visible items actually use, so it neither
     * gains an empty button nor loses a real one; an uncategorised card has no
     * data-category at all, so "Alles" shows it and every category hides it
     * (assets/js/blocks/item-gallery.js matches on those tokens).
     */
    public function testTheGalleryListsAnUncategorisedItemAndItsFilterBarStaysClean(): void
    {
        $category = $this->category();
        $with = $this->item(['nl' => ['title' => 'ZZ Met categorie']], [(int) $category['id']]);
        $without = $this->item([]);

        $byImage = [];
        foreach (PortfolioGalleryContent::catalogueItems(false) as $item) {
            $byImage[$item['image_path']] = $item;
        }

        $withItem = $byImage[$this->imagePath($with)] ?? null;
        $withoutItem = $byImage[$this->imagePath($without)] ?? null;

        $this->assertNotNull($withItem, 'a categorised item is listed');
        $this->assertNotNull($withoutItem, 'an uncategorised item is listed just the same');
        $this->assertSame((string) $category['slug'], $withItem['categories']);
        $this->assertSame('', $withoutItem['categories']);
        $this->assertSame('', SiteText::visibleOf($withoutItem['alt']), 'no alt text is invented');
        $this->assertSame(
            ' data-nl-alt="" data-en-alt=""',
            SiteText::attrsForOf('alt', $withoutItem['alt']),
            'not from the other language either'
        );

        $slugs = array_column(PortfolioGalleryContent::filterCategories(), 'slug');
        $this->assertContains((string) $category['slug'], $slugs);
        $this->assertNotContains('', $slugs);
    }

    /**
     * A card draws only the words it has. No title and no caption: no overlay
     * at all, not a darkened strip over the photo. A title alone: no empty
     * caption line. And an empty alt text stays alt="" — a decorative image —
     * never the file name.
     */
    public function testAGalleryCardDrawsOnlyTheWordsItHas(): void
    {
        $html = $this->render([
            $this->card('assets/images/sections/zz-bare.jpg', '', ''),
            $this->card('assets/images/sections/zz-title.jpg', 'ZZ Alleen titel', ''),
            $this->card('assets/images/sections/zz-both.jpg', 'ZZ Titel', 'ZZ Onderschrift'),
        ]);

        $xpath = $this->xpath($html);
        $cards = $xpath->query('//*[contains(concat(" ", @class, " "), " gallery-item ")]');
        $this->assertNotFalse($cards);
        $this->assertSame(3, $cards->length);

        [$bare, $titleOnly, $both] = [$cards->item(0), $cards->item(1), $cards->item(2)];

        $this->assertSame(0, $xpath->query('.//*[contains(@class, "gallery-item__overlay")]', $bare)->length);
        $bareImage = $xpath->query('.//img', $bare)->item(0);
        $this->assertInstanceOf(\DOMElement::class, $bareImage);
        $this->assertTrue($bareImage->hasAttribute('alt'));
        $this->assertSame('', $bareImage->getAttribute('alt'));
        $this->assertStringNotContainsString('zz-bare', $bareImage->getAttribute('alt'));
        $this->assertFalse($bare->hasAttribute('data-category'), 'no empty category token');

        $this->assertSame(1, $xpath->query('.//*[contains(@class, "gallery-item__overlay")]/p', $titleOnly)->length);
        $this->assertSame(0, $xpath->query('.//*[contains(@class, "gallery-item__overlay")]/span', $titleOnly)->length);

        $this->assertSame('ZZ Titel', trim((string) $xpath->query('.//*[contains(@class, "gallery-item__overlay")]/p', $both)->item(0)?->textContent));
        $this->assertSame('ZZ Onderschrift', trim((string) $xpath->query('.//*[contains(@class, "gallery-item__overlay")]/span', $both)->item(0)?->textContent));
    }

    /* ------------------------------------------------------------------ */

    /**
     * @param array<string, array<string, string>> $words language code => field => words
     * @param list<int> $categoryIds
     */
    private function item(array $words, array $categoryIds = []): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], [
            'image_path' => 'assets/images/sections/zz-portfolio-content-' . $marker . '.jpg',
            'thumbnail_path' => null,
        ]);
        $this->itemIds[] = $id;

        foreach ($words as $language => $fields) {
            PortfolioLocalization::saveItem($id, (string) $language, $fields);
        }

        $repository->setItemCategories($id, $categoryIds);

        PortfolioGalleryContent::clearCache();

        return $id;
    }

    private function imagePath(int $id): string
    {
        return (string) ((new PortfolioGalleryRepository())->findItemById($id)['image_path'] ?? '');
    }

    /** @return array<string, mixed> */
    private function category(): array
    {
        $repository = new PortfolioCategoryRepository();
        $marker = bin2hex(random_bytes(3));
        $id = $repository->create('zz-categorie-' . $marker);
        $this->categoryIds[] = $id;
        PortfolioLocalization::saveCategory($id, PortfolioLocalization::defaultLanguage(), 'ZZ Categorie ' . $marker);

        return (array) $repository->findById($id);
    }

    /** @return array<string, mixed> one item in the shape every gallery source hands the partial */
    private function card(string $imagePath, string $title, string $subtitle): array
    {
        return [
            'image_path' => $imagePath,
            // One LocalizedValue per field since Multilingual 2.0 phase 5
            // wave A; an empty one is "no words in any language".
            'alt' => LocalizedValue::of([]),
            'title' => LocalizedValue::ofDutchEnglish($title, $title),
            'subtitle' => LocalizedValue::ofDutchEnglish($subtitle, $subtitle),
            'categories' => '',
            'url' => '',
            'is_detail_link' => false,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function render(array $items): string
    {
        ItemGalleryContent::clearCache();

        ob_start();
        render_section_item_gallery([
            'items' => $items,
            'enable_lightbox' => false,
            'filter_categories' => [],
            'fallback_link_url' => '',
            // The block's own words, all empty (per website language since
            // Multilingual 2.0 phase 3B).
            ...\App\Service\Blocks\BlockLocalization::words('item_galleries', 0),
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
        ], 'zz-test');

        return (string) ob_get_clean();
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }
}

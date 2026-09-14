<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\PortfolioCategoryRepository;
use App\Repository\PortfolioGalleryRepository;
use App\Service\ItemGalleryContent;
use App\Service\PortfolioGalleryContent;
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

    public function testAnItemWithOnlyAnImageIsStoredWithEmptyWordsAndNoCategory(): void
    {
        $repository = new PortfolioGalleryRepository();
        $id = $this->item(['title_nl' => '', 'alt_nl' => '', 'subtitle_nl' => '']);
        $row = (array) $repository->findItemById($id);

        foreach (['title_nl', 'alt_nl', 'subtitle_nl'] as $column) {
            $this->assertSame('', $row[$column], $column . ' is stored empty, not refused and not NULL');
        }

        foreach (['title_en', 'alt_en', 'subtitle_en'] as $column) {
            $this->assertNull($row[$column]);
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
            'title_nl' => 'ZZ Skyline',
            'title_en' => 'ZZ Skyline EN',
            'alt_nl' => 'Houten skyline van een stad',
            'alt_en' => 'Wooden city skyline',
            'subtitle_nl' => 'Wanddecoratie, hout',
            'subtitle_en' => 'Wall decor, wood',
        ], [(int) $first['id'], (int) $second['id']]);

        $row = (array) $repository->findItemById($id);
        $this->assertSame('ZZ Skyline', $row['title_nl']);
        $this->assertSame('ZZ Skyline EN', $row['title_en']);
        $this->assertSame('Houten skyline van een stad', $row['alt_nl']);
        $this->assertSame('Wooden city skyline', $row['alt_en']);
        $this->assertSame('Wanddecoratie, hout', $row['subtitle_nl']);
        $this->assertSame('Wall decor, wood', $row['subtitle_en']);

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
        $id = $this->item(['title_nl' => 'ZZ Met categorie'], [(int) $category['id']]);

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
        $with = $this->item(['title_nl' => 'ZZ Met categorie'], [(int) $category['id']]);
        $without = $this->item(['title_nl' => '', 'alt_nl' => '', 'subtitle_nl' => '']);

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
        $this->assertSame('', $withoutItem['alt_nl'], 'no alt text is invented');
        $this->assertSame('', $withoutItem['alt_en'], 'not from the other language either');

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
     * @param array<string, string> $words
     * @param list<int> $categoryIds
     */
    private function item(array $words, array $categoryIds = []): int
    {
        $repository = new PortfolioGalleryRepository();
        $marker = bin2hex(random_bytes(4));

        $id = $repository->createItem((int) $repository->ensureCatalogue()['id'], $words + [
            'image_path' => 'assets/images/sections/zz-portfolio-content-' . $marker . '.jpg',
            'thumbnail_path' => null,
            'alt_nl' => '',
            'alt_en' => null,
            'title_nl' => '',
            'title_en' => null,
            'subtitle_nl' => '',
            'subtitle_en' => null,
        ]);
        $this->itemIds[] = $id;
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
        $id = $repository->create('ZZ Categorie ' . $marker, null, 'zz-categorie-' . $marker);
        $this->categoryIds[] = $id;

        return (array) $repository->findById($id);
    }

    /** @return array<string, mixed> one item in the shape every gallery source hands the partial */
    private function card(string $imagePath, string $title, string $subtitle): array
    {
        return [
            'image_path' => $imagePath,
            'alt_nl' => '',
            'alt_en' => '',
            'title_nl' => $title,
            'title_en' => $title,
            'subtitle_nl' => $subtitle,
            'subtitle_en' => $subtitle,
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
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'footer_note_nl' => '', 'footer_note_en' => '',
            'button_label_nl' => '', 'button_label_en' => '',
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

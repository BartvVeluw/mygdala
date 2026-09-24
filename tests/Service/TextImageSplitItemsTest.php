<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\TextImageSplitRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Routing\RequestLanguage;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

require_once dirname(__DIR__, 2) . '/partials/section-text-image-split.php';

/**
 * Tekst met afbeelding 2.0: a block is a list of items, each a text beside at
 * most one picture with its own side, share of the row, height and focus
 * point (App\Service\TextImageSplitContent, partials/section-text-image-split.php).
 *
 *  - the markup: text first in the document whatever the side, a class per
 *    choice and never a size, the focus point as ImageFocus's object-position,
 *    no heading without a title, the body as it is, the picture lazy with
 *    its size, an item with only one half marked so, nothing for no items;
 *  - the read model, against the test database inside a transaction that is
 *    rolled back: the order, what makes an item show, the default language
 *    deciding presence while the words follow the request's language, the
 *    layout the same in every language, and the button rule;
 *  - the Media Library: every item's picture is a usage, and a picture an
 *    item shows cannot be deleted.
 *
 * The one-form editor over HTTP is Tests\Service\BlockRowEditorsHttpTest's,
 * the migration Tests\Install\TextImageItemsMigrationTest's.
 */
final class TextImageSplitItemsTest extends TestCase
{
    private const SLUG = '__test_text_image_items__';

    private bool $inTransaction = false;

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        TextImageSplitContent::clearCache();
        MediaService::clearCache();
        SiteLanguageFixture::reset();
        RequestLanguage::reset();
    }

    // ------------------------------------------------------------ markup

    public function testEveryCombinationOfSideColumnAndHeightIsAClassAndTheTextComesFirst(): void
    {
        foreach (TextImageSplitContent::SIDES as $side) {
            foreach (TextImageSplitContent::COLUMNS as $column) {
                foreach (TextImageSplitContent::HEIGHTS as $height) {
                    $html = self::render([self::item(['image_side' => $side, 'image_column' => $column, 'image_height' => $height])]);

                    self::assertStringContainsString(
                        'class="text-image__item text-image__item--image-' . $side . ' text-image__item--column-' . $column . ' text-image__item--height-' . $height . '"',
                        $html
                    );
                    self::assertLessThan(strpos($html, 'text-image__media'), strpos($html, 'text-image__text'), 'the text comes first in the document');
                    self::assertStringNotContainsString('px', $html, 'no size is printed, only keys');
                }
            }
        }
    }

    public function testEveryFocusPointIsTheObjectPositionImageFocusGives(): void
    {
        foreach (ImageFocus::keys() as $focus) {
            self::assertStringContainsString(
                'style="object-position: ' . ImageFocus::objectPosition($focus) . ';"',
                self::render([self::item(['image_focus' => $focus])])
            );
        }

        self::assertStringContainsString('object-position: 50% 50%;', self::render([self::item(['image_focus' => 'nowhere'])]), 'an unknown key is the centre');
    }

    public function testOnlyTextOnlyAPictureBothAndSeveralItems(): void
    {
        $textOnly = self::render([self::item(['image' => null])]);
        self::assertStringContainsString('text-image__item--text-only', $textOnly);
        self::assertStringNotContainsString('<img', $textOnly);

        $imageOnly = self::render([self::item(['eyebrow' => '', 'title' => '', 'body' => '', 'button_label' => ''])]);
        self::assertStringContainsString('text-image__item--image-only', $imageOnly);
        self::assertStringNotContainsString('text-image__text', $imageOnly);
        self::assertStringContainsString('loading="lazy"', $imageOnly);
        self::assertStringContainsString('width="1600" height="1000"', $imageOnly, 'the size the library knows, so nothing shifts');

        $both = self::render([self::item()]);
        self::assertStringNotContainsString('--text-only', $both);
        self::assertStringNotContainsString('--image-only', $both);

        $several = self::render([self::item(['title' => 'Eerste']), self::item(['title' => 'Tweede']), self::item(['title' => 'Derde'])]);
        self::assertSame(1, substr_count($several, '<section'), 'one block, one section');
        self::assertSame(3, substr_count($several, 'class="text-image__item '));
        self::assertMatchesRegularExpression('~Eerste.*Tweede.*Derde~s', $several);
        self::assertStringContainsString('data-reveal-group="tis-test-0"', $several, 'each item staggers its own halves');
        self::assertStringContainsString('data-reveal-group="tis-test-2"', $several);

        self::assertSame('', trim(self::render([])), 'no item, no section and no gap');
    }

    public function testNoHeadingWithoutATitleAndTheBodyIsPrintedAsItIs(): void
    {
        $withTitle = self::render([self::item()]);
        self::assertStringContainsString('<h2>Het verhaal</h2>', $withTitle);
        self::assertStringContainsString('<div class="rich-content text-image__body"><p>Een <strong>alinea</strong></p></div>', $withTitle);

        $withoutTitle = self::render([self::item(['title' => ''])]);
        self::assertStringNotContainsString('<h2', $withoutTitle, 'no heading is made up');
        self::assertStringContainsString('text-image__body--lead', $withoutTitle, 'a title-less item opens with the lead paragraph');
    }

    /**
     * The layout contract, held against the stylesheet itself: the columns
     * an item gets on a wide screen, worked out the way the browser's cascade
     * would from the classes the partial prints (a small resolver for the
     * class-only selectors this file uses; see resolvedColumns()).
     *
     *   text + image   the picture's share: 25/75, 50/50 or 75/25, read from
     *                  the picture's side
     *   text only      one column, the whole row, whatever share is stored
     *   image only     one column, the whole row, whatever share is stored
     *
     * The browser check of the same (desktop and 375px) is in the phase's
     * acceptance; this pins it for every later change to the file.
     */
    public function testATextAndImageItemSplitsByItsShareAndASingleHalfTakesTheWholeRow(): void
    {
        $shares = ['25' => ['1fr', '3fr'], '50' => ['1fr', '1fr'], '75' => ['3fr', '1fr']];

        foreach (TextImageSplitContent::SIDES as $side) {
            foreach ($shares as $column => [$image, $text]) {
                $classes = self::classesOf(self::render([self::item(['image_side' => $side, 'image_column' => $column])]));
                $expected = $side === 'left'
                    ? "minmax(0, {$image}) minmax(0, {$text})"
                    : "minmax(0, {$text}) minmax(0, {$image})";
                self::assertSame($expected, self::resolvedColumns($classes), "text + image, {$side}, {$column}");

                foreach ([
                    'text only' => ['image' => null],
                    'image only' => ['eyebrow' => '', 'title' => '', 'body' => '', 'button_label' => ''],
                ] as $what => $overrides) {
                    $classes = self::classesOf(self::render([self::item(['image_side' => $side, 'image_column' => $column] + $overrides)]));
                    self::assertSame('minmax(0, 1fr)', self::resolvedColumns($classes), "{$what}, {$side}, {$column}: the whole row");
                }
            }
        }

        $css = self::stylesheet();
        self::assertStringNotContainsString('grid-column', $css, 'no half is ever pinned to a column of its own');
        self::assertMatchesRegularExpression('/@media \(max-width: 860px\)\{.*\.text-image \.text-image__item\{ grid-template-columns: minmax\(0, 1fr\);/s', $css, 'one column on a narrow screen');
    }

    // ------------------------------------------------------------ read model

    public function testTheItemsComeInOrderAndOnlyTheOnesWithSomethingToShow(): void
    {
        $this->begin();
        SiteLanguageFixture::useBilingual('nl');
        $blockId = $this->block();
        $repository = new TextImageSplitRepository();

        $layoutOnly = $repository->createItem($blockId, ['image_side' => 'left', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top']);
        $second = $repository->createItem($blockId, TextImageSplitContent::DEFAULTS + ['button_url' => '/contact']);
        BlockLocalization::save('text_image_split_items', $second, 'nl', ['button_label' => 'Alleen een knop']);
        $first = $repository->createItem($blockId, ['image_side' => 'left', 'image_column' => '25', 'image_height' => 'small', 'image_focus' => 'bottom']);
        BlockLocalization::save('text_image_split_items', $first, 'nl', ['title' => 'Eerste', 'body' => '<p>Tekst</p>']);
        $halfButton = $repository->createItem($blockId, TextImageSplitContent::DEFAULTS);
        BlockLocalization::save('text_image_split_items', $halfButton, 'nl', ['button_label' => 'Knop zonder adres']);
        $repository->reorderItems($blockId, [$first, $layoutOnly, $second, $halfButton]);

        $items = $this->content()['items'];

        self::assertCount(2, $items, 'an item with only a layout, or only half a button, shows nothing');
        self::assertSame('Eerste', $items[0]['title']);
        self::assertSame(['left', '25', 'small', 'bottom'], [$items[0]['image_side'], $items[0]['image_column'], $items[0]['image_height'], $items[0]['image_focus']]);
        self::assertSame(['Alleen een knop', '/contact'], [$items[1]['button_label'], $items[1]['button_url']]);
    }

    public function testTheDefaultLanguageDecidesPresenceTheWordsFollowTheRequestAndTheLayoutIsShared(): void
    {
        $this->begin();
        SiteLanguageFixture::useBilingual('nl');
        $blockId = $this->block();
        $repository = new TextImageSplitRepository();

        $both = $repository->createItem($blockId, ['image_side' => 'left', 'image_column' => '75', 'image_height' => 'large', 'image_focus' => 'top-right']);
        BlockLocalization::save('text_image_split_items', $both, 'nl', ['title' => 'Het verhaal', 'body' => '<p>Nederlands</p>']);
        BlockLocalization::save('text_image_split_items', $both, 'en', ['body' => '<p>English</p>']);
        $englishOnly = $repository->createItem($blockId, TextImageSplitContent::DEFAULTS);
        BlockLocalization::save('text_image_split_items', $englishOnly, 'en', ['title' => 'Only English']);

        RequestLanguage::set('nl', true);
        $dutch = $this->content()['items'];
        RequestLanguage::set('en', true);
        $english = $this->content()['items'];

        self::assertCount(1, $dutch);
        self::assertCount(1, $english, 'an item with words only in a translation is not there');
        self::assertSame(['Het verhaal', '<p>Nederlands</p>'], [$dutch[0]['title'], $dutch[0]['body']]);
        self::assertSame(['Het verhaal', '<p>English</p>'], [$english[0]['title'], $english[0]['body']], 'the English body, the title falling back');
        foreach (['image_side', 'image_column', 'image_height', 'image_focus'] as $key) {
            self::assertSame($dutch[0][$key], $english[0][$key], $key . ' is the same in every language');
        }
    }

    // ------------------------------------------------------------ media

    public function testEveryItemsPictureIsAUsageAndAPictureInUseCannotBeDeleted(): void
    {
        $this->begin();
        $blockId = $this->block();
        $repository = new TextImageSplitRepository();
        $media = new MediaRepository();
        $bench = $media->create(['path' => 'assets/media/__tis_items_bench__.webp', 'original_filename' => 'bench.webp', 'mime_type' => 'image/webp', 'width' => 1600, 'height' => 1000, 'file_size' => 100, 'alt_text' => 'Een werkbank', 'checksum' => null]);
        $plank = $media->create(['path' => 'assets/media/__tis_items_plank__.webp', 'original_filename' => 'plank.webp', 'mime_type' => 'image/webp', 'width' => 1600, 'height' => 1000, 'file_size' => 100, 'alt_text' => '', 'checksum' => null]);
        MediaService::clearCache();

        $withOwnAlt = $repository->createItem($blockId, TextImageSplitContent::DEFAULTS + ['media_id' => $bench]);
        BlockLocalization::save('text_image_split_items', $withOwnAlt, 'nl', ['alt' => 'Eigen alt']);
        $repository->createItem($blockId, TextImageSplitContent::DEFAULTS + ['media_id' => $bench]);
        $repository->createItem($blockId, TextImageSplitContent::DEFAULTS + ['media_id' => $plank]);

        $service = new MediaService();
        self::assertCount(2, $service->usagesOf($bench), 'two items of one block, two usages');
        self::assertCount(1, $service->usagesOf($plank));
        self::assertSame('in_use', $service->delete($plank)['reason']);
        self::assertNotNull(MediaService::find($plank));

        $items = $this->content()['items'];
        self::assertSame('Eigen alt', $items[0]['image']['alt'], 'an own alt text wins');
        self::assertSame('Een werkbank', $items[1]['image']['alt'], 'no own alt text: the library\'s');
        self::assertSame('', $items[2]['image']['alt']);
    }

    // ------------------------------------------------------------ helpers

    private function begin(): void
    {
        Database::connection()->beginTransaction();
        $this->inTransaction = true;
    }

    private function block(): int
    {
        $repository = new TextImageSplitRepository();
        $repository->upsertSection(self::SLUG, 'items', ['is_active' => true]);

        return (int) $repository->findBySlugAndKey(self::SLUG, 'items')['id'];
    }

    /** @return array<string, mixed> */
    private function content(): array
    {
        TextImageSplitContent::clearCache();
        $content = TextImageSplitContent::forSection(self::SLUG, 'items');
        self::assertSame(TextImageSplitContent::STATE_ACTIVE, $content['state']);

        return $content;
    }

    /**
     * One item as TextImageSplitContent hands it to the partial.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function item(array $overrides = []): array
    {
        return $overrides + TextImageSplitContent::DEFAULTS + [
            'eyebrow' => 'Over ons',
            'title' => 'Het verhaal',
            'body' => '<p>Een <strong>alinea</strong></p>',
            'button_label' => 'Neem contact op',
            'button_url' => '/contact',
            'image' => ['image_path' => '/assets/media/z.webp', 'alt' => 'Een werkplaats', 'width' => 1600, 'height' => 1000, 'media_id' => null],
        ];
    }

    private static function stylesheet(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/text-image-split.css');
    }

    /** @return list<string> the classes of the one item in $html */
    private static function classesOf(string $html): array
    {
        self::assertSame(1, preg_match('/class="(text-image__item [^"]*)"/', $html, $match));

        return explode(' ', $match[1]);
    }

    /**
     * The grid-template-columns an item with these classes gets on a wide
     * screen: every top-level rule whose selector is a compound of these
     * classes applies, the more specific (more classes) after the less, and
     * a later rule after an earlier one; custom properties are then
     * substituted. Only the class-only selectors this stylesheet uses count.
     *
     * @param list<string> $classes
     */
    private static function resolvedColumns(array $classes): string
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', self::stylesheet());
        $css = (string) preg_replace('/@media[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/', '', $css);

        $matching = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as $order => [, $selectors, $body]) {
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if (preg_match('/^(\.[a-z0-9_-]+)+$/i', $selector) !== 1) {
                    continue;
                }
                $needed = explode('.', ltrim($selector, '.'));
                if (array_diff($needed, $classes) === []) {
                    $matching[] = [count($needed), $order, $body];
                }
            }
        }
        usort($matching, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        $declared = [];
        foreach ($matching as [, , $body]) {
            foreach (explode(';', $body) as $declaration) {
                if (str_contains($declaration, ':')) {
                    [$property, $value] = array_map('trim', explode(':', $declaration, 2));
                    $declared[$property] = $value;
                }
            }
        }

        return (string) preg_replace_callback(
            '/var\((--[a-z0-9-]+)\)/',
            static fn (array $m): string => $declared[$m[1]] ?? $m[0],
            $declared['grid-template-columns'] ?? ''
        );
    }

    /** @param list<array<string, mixed>> $items */
    private static function render(array $items): string
    {
        ob_start();
        try {
            render_section_text_image_split(['state' => TextImageSplitContent::STATE_ACTIVE, 'items' => $items], false, 'tis-test');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}

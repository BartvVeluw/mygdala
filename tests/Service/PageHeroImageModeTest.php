<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Breadcrumbs\BreadcrumbItem;
use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Media\Usage\ContentBlockMediaUsage;
use App\Service\PageContent;
use App\Service\PageHeroContent;
use App\Service\SectionRegistry;
use App\Service\SiteSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Paginakop 2.0 as a visitor gets it: where the picture goes, how tall a
 * header with a picture behind it is, which part of a cropped picture stays
 * in view, and where the page's breadcrumb goes with each.
 *
 * - No picture: exactly the markup a header always had, whatever place,
 *   height or focus is stored, and the trail before it.
 * - Background: one band with the picture as a layer, `alt=""`, a height
 *   class for small and large, the trail inside the band and nowhere else.
 * - Left/right: text first, the picture in a figure with its layered alt
 *   text, the trail at the top of the text, without a container of its own.
 * - The focus point is object-position, and nothing for the middle.
 * - The stylesheet: every class is styled, the text is not pinned to the
 *   bottom, no negative margin, and a narrow screen stacks the picture above
 *   the text on both sides.
 *
 * The migration is Tests\Install\PageHeaderImageModeMigrationTest's, the
 * editor and the endpoint Tests\Service\PageHeroEditorHttpTest's, the choices
 * that existed before Tests\Service\PageHeroHeaderTest's.
 */
final class PageHeroImageModeTest extends TestCase
{
    /** Where this test's page and header live; never a real page. */
    private const TEST_PAGE = '__test_page_hero_image_mode__';

    /** @var list<int> */
    private array $mediaIds = [];

    protected function setUp(): void
    {
        SiteSettings::all();
        $this->removeTestPage();

        \Tests\Support\PageFixture::create([
            'content_key' => self::TEST_PAGE,
            'slug' => self::TEST_PAGE,
            'status' => 'draft',
        ], 'Paginakop-weergave-testpagina');

        self::clearCaches();
    }

    protected function tearDown(): void
    {
        // The header goes first: a media row cannot while a header still
        // points at it (ON DELETE RESTRICT).
        $this->removeTestPage();

        $media = new MediaRepository();
        foreach ($this->mediaIds as $id) {
            $media->delete($id);
        }
        $this->mediaIds = [];

        self::clearCaches();
    }

    /* ------------------------------------------------------------------ */
    /* Without a picture nothing changes                                   */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string, bool}> */
    public static function placesWithoutAPicture(): array
    {
        return [
            'no picture chosen, place none' => [PageHeroContent::IMAGE_NONE, false],
            'no picture chosen, place background' => [PageHeroContent::IMAGE_BACKGROUND, false],
            'no picture chosen, place left' => [PageHeroContent::IMAGE_LEFT, false],
            'no picture chosen, place right' => [PageHeroContent::IMAGE_RIGHT, false],
            'a picture, but place none' => [PageHeroContent::IMAGE_NONE, true],
        ];
    }

    #[DataProvider('placesWithoutAPicture')]
    public function testAHeaderWithoutAPictureToShowPrintsNoneOfItsMarkup(string $mode, bool $withMedia): void
    {
        $this->storeHeader([
            'media_id' => $withMedia ? $this->mediaItem('Een werkbank') : null,
            'image_mode' => $mode,
            'hero_height' => PageHeroContent::HEIGHT_LARGE,
            'image_focus' => 'bottom-right',
        ]);

        $html = $this->renderHeader();

        $this->assertSame(['page-hero'], $this->sectionClasses($html), 'no picture, no height and no focus class');
        foreach (['<img', 'page-hero__media', 'page-hero__split', 'page-hero__figure', 'object-position', 'breadcrumb'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
        $this->assertMatchesRegularExpression('#<section class="page-hero">\s*<div class="container">\s*<p class="eyebrow">#', $html, 'the markup a header always had');
    }

    /* ------------------------------------------------------------------ */
    /* A picture behind the text                                           */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string, list<string>}> */
    public static function heights(): array
    {
        return [
            'small' => [PageHeroContent::HEIGHT_SMALL, ['page-hero--height-small']],
            'medium, the height it always had' => [PageHeroContent::HEIGHT_MEDIUM, []],
            'large' => [PageHeroContent::HEIGHT_LARGE, ['page-hero--height-large']],
        ];
    }

    /** @param list<string> $heightClass */
    #[DataProvider('heights')]
    public function testABackgroundPictureIsOneBandWithItsHeightStep(string $height, array $heightClass): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Een werkbank'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND, 'hero_height' => $height]);

        $html = $this->renderHeader();

        $this->assertSame(['page-hero', 'page-hero--background', ...$heightClass], $this->sectionClasses($html));
        $this->assertMatchesRegularExpression(
            '#<div class="page-hero__media">\s*<img src="/assets/media/__page_hero_mode_\d+__\.webp" alt="" width="1600" height="900" loading="eager"#',
            $html,
            'a layer before the text, decorative, with its size reserved'
        );
        $this->assertStringContainsString('<div class="container page-hero__body">', $html);
        $this->assertStringNotContainsString('page-hero__figure', $html);
    }

    public function testTheCasesCoverEveryHeightAndEveryPlace(): void
    {
        $this->assertSame(PageHeroContent::HEIGHTS, array_column(self::heights(), 0));
        $this->assertSame(
            [PageHeroContent::IMAGE_NONE, PageHeroContent::IMAGE_BACKGROUND, PageHeroContent::IMAGE_LEFT, PageHeroContent::IMAGE_RIGHT],
            PageHeroContent::IMAGE_MODES
        );
    }

    public function testABackgroundPictureIsDecorationWhateverAltTextItHas(): void
    {
        $media = $this->mediaItem('Werkbank met houten plankjes');
        $this->storeHeader(['media_id' => $media, 'image_mode' => PageHeroContent::IMAGE_BACKGROUND], ['image_alt' => 'Een eigen beschrijving']);

        $html = $this->renderHeader();

        $this->assertStringContainsString(' alt=""', $html);
        $this->assertStringNotContainsString('Werkbank met houten plankjes', $html, 'the library text is not read out');
        $this->assertStringNotContainsString('Een eigen beschrijving', $html, 'nor the header\'s own');
    }

    /* ------------------------------------------------------------------ */
    /* A picture beside the text                                           */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string, string}> */
    public static function sides(): array
    {
        return [
            'left' => [PageHeroContent::IMAGE_LEFT, 'page-hero--image-left'],
            'right' => [PageHeroContent::IMAGE_RIGHT, 'page-hero--image-right'],
        ];
    }

    #[DataProvider('sides')]
    public function testAPictureBesideTheTextComesAfterTheTextInTheMarkup(string $mode, string $sideClass): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Werkbank met houten plankjes'), 'image_mode' => $mode, 'hero_height' => PageHeroContent::HEIGHT_LARGE]);

        $html = $this->renderHeader();

        $this->assertSame(['page-hero', 'page-hero--split', $sideClass], $this->sectionClasses($html), 'no height class: it has the height of its text');
        $this->assertMatchesRegularExpression(
            '#<div class="container page-hero__split">\s*<div class="page-hero__text">.*<h1>Een paginakop</h1>.*</div>\s*<figure class="page-hero__figure">\s*<img src="[^"]+" alt="Werkbank met houten plankjes" width="1600" height="900" loading="eager"#s',
            $html,
            'the same order on both sides: the stylesheet places the picture'
        );
        $this->assertStringNotContainsString('page-hero__media', $html, 'no veil layer beside the text');
    }

    public function testAPictureBesideTheTextTakesTheHeadersOwnAltTextOverTheLibrarys(): void
    {
        $media = $this->mediaItem('Werkbank met houten plankjes');
        $this->storeHeader(['media_id' => $media, 'image_mode' => PageHeroContent::IMAGE_RIGHT], ['image_alt' => 'Plankjes op de werkbank van de werkplaats']);

        $this->assertStringContainsString('alt="Plankjes op de werkbank van de werkplaats"', $this->renderHeader());
    }

    public function testAPictureBesideTheTextWithoutAnyAltTextIsMarkedDecorative(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem(''), 'image_mode' => PageHeroContent::IMAGE_LEFT]);

        $this->assertMatchesRegularExpression('#<figure class="page-hero__figure">\s*<img src="[^"]+" alt=""#', $this->renderHeader());
    }

    public function testAnEmptyEyebrowLeavesNothingInAnyShape(): void
    {
        foreach ([PageHeroContent::IMAGE_BACKGROUND, PageHeroContent::IMAGE_LEFT] as $mode) {
            $this->removeHeader();
            $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => $mode], ['eyebrow' => '']);

            $html = $this->renderHeader();

            $this->assertStringContainsString('<h1', $html, $mode);
            $this->assertStringNotContainsString('class="eyebrow"', $html, $mode);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The focus point                                                     */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string}> */
    public static function focusPoints(): array
    {
        $points = [];
        foreach (ImageFocus::keys() as $key) {
            $points[$key] = [$key];
        }

        return $points;
    }

    #[DataProvider('focusPoints')]
    public function testTheFocusPointIsTheObjectPositionOfThePictureInEveryShape(string $focus): void
    {
        foreach ([PageHeroContent::IMAGE_BACKGROUND, PageHeroContent::IMAGE_RIGHT] as $mode) {
            $this->removeHeader();
            $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => $mode, 'image_focus' => $focus]);

            $html = $this->renderHeader();

            if ($focus === ImageFocus::DEFAULT) {
                $this->assertStringNotContainsString('object-position', $html, 'the middle is what the browser does by itself');
            } else {
                $this->assertMatchesRegularExpression(
                    '#<img [^>]*style="object-position: ' . preg_quote(ImageFocus::objectPosition($focus), '#') . ';"#',
                    $html,
                    $mode
                );
            }
        }
    }

    public function testAStoredPlaceHeightOrFocusOutsideItsListReadsAsTheDefault(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        // Only a hand-edited row can hold these: the endpoint refuses them.
        Database::connection()->prepare(
            'UPDATE page_heroes SET image_mode = :mode, hero_height = :height, image_focus = :focus WHERE page_slug = :slug'
        )->execute(['mode' => 'diagonal', 'height' => '900px', 'focus' => '10% 20%', 'slug' => self::TEST_PAGE]);
        self::clearCaches();

        $content = PageHeroContent::forSlug(self::TEST_PAGE);
        $this->assertSame(
            [PageHeroContent::IMAGE_NONE, PageHeroContent::HEIGHT_MEDIUM, ImageFocus::DEFAULT],
            [$content['image_mode'], $content['hero_height'], $content['image_focus']]
        );
        $this->assertSame(['page-hero'], $this->sectionClasses($this->renderHeader()), 'an unknown place is no place: no picture markup at all');
    }

    /* ------------------------------------------------------------------ */
    /* The breadcrumb goes with the header                                 */
    /* ------------------------------------------------------------------ */

    public function testOverABackgroundPictureTheTrailIsInsideTheBandAndOnlyThere(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'), 'one trail on the page');
        $this->assertMatchesRegularExpression(
            '#^\s*<section class="page-hero page-hero--background">\s*<div class="page-hero__media">.*?</div>\s*<nav class="breadcrumb-bar" aria-label="Kruimelpad">\s*<div class="container">\s*<ol class="breadcrumb">.*?</nav>\s*<div class="container page-hero__body">#s',
            $html,
            'nothing before the band: the trail is its first line, over the picture'
        );
    }

    #[DataProvider('sides')]
    public function testBesideAPictureTheTrailIsAtTheTopOfTheText(string $mode): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => $mode]);

        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'));
        $this->assertMatchesRegularExpression(
            '#^\s*<section class="page-hero page-hero--split[^"]*">\s*<div class="container page-hero__split">\s*<div class="page-hero__text">\s*<nav class="breadcrumb-bar" aria-label="Kruimelpad">\s*<ol class="breadcrumb">#s',
            $html,
            'inside the text column, which already lines it up: no container of its own'
        );
    }

    public function testWithoutAPictureTheTrailStaysBeforeTheHeader(): void
    {
        $this->storeHeader(['media_id' => null, 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'));
        $this->assertMatchesRegularExpression('#^\s*<nav class="breadcrumb-bar"[^>]*>\s*<div class="container">.*?</nav>\s*<section class="page-hero">#s', $html, 'exactly where it always was');
    }

    public function testAHiddenHeaderWithAPictureLeavesTheTrailStanding(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND, 'is_active' => false]);

        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'));
        $this->assertStringNotContainsString('page-hero', $html);
    }

    public function testAHeaderThatIsNotTheFirstBlockLeavesTheTrailAtTheTop(): void
    {
        $this->attach('rich_text');
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'));
        $this->assertLessThan(strpos($html, 'page-hero--background'), strpos($html, '<nav class="breadcrumb-bar"'));
        $this->assertDoesNotMatchRegularExpression('#page-hero__media.*breadcrumb-bar#s', $html, 'not inside the header further down');
    }

    public function testAPageWithoutBlocksStillShowsItsTrail(): void
    {
        $html = $this->renderPage($this->trail());

        $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'));
    }

    public function testAPageWithoutATrailPrintsNone(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->renderPage(null);

        $this->assertStringNotContainsString('breadcrumb', $html);
        $this->assertStringContainsString('page-hero--background', $html);
    }

    /**
     * The contract assumes nothing about the depth of a trail: a page with
     * parents (Pages 2.0) hands over a longer one, and every place prints
     * every level in order — the parents as links, the page itself as text.
     */
    public function testATrailOfAnyLengthIsPrintedWholeInEveryPlace(): void
    {
        $trail = BreadcrumbTrail::home()
            ->to(BreadcrumbItem::link('Diensten', '/diensten'))
            ->to(BreadcrumbItem::link('Metaal graveren', '/diensten/metaal-graveren'))
            ->to(BreadcrumbItem::current('Aluminium visitekaartjes'));

        foreach ([PageHeroContent::IMAGE_NONE, PageHeroContent::IMAGE_BACKGROUND, PageHeroContent::IMAGE_LEFT] as $mode) {
            $this->removeHeader();
            $this->storeHeader(['media_id' => $mode === PageHeroContent::IMAGE_NONE ? null : $this->mediaItem('Foto'), 'image_mode' => $mode]);

            $html = $this->renderPage($trail);

            $this->assertSame(1, substr_count($html, '<nav class="breadcrumb-bar"'), $mode);
            $this->assertSame(4, substr_count($html, '<li class="breadcrumb__item">'), $mode . ': four levels');
            $this->assertMatchesRegularExpression(
                '#<a href="/">Home</a>.*<a href="/diensten">Diensten</a>.*<a href="/diensten/metaal-graveren">Metaal graveren</a>.*<span class="breadcrumb__current" aria-current="page">Aluminium visitekaartjes</span>#s',
                $html,
                $mode . ': in order, every parent a link, the page itself not'
            );
            $this->assertSame(3, substr_count($html, 'class="breadcrumb__separator" aria-hidden="true"'), $mode . ': a separator before every level but the first');
        }
    }

    public function testTheTrailInsideTheHeaderIsTheSameTrail(): void
    {
        $this->storeHeader(['media_id' => $this->mediaItem('Foto'), 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);
        $trail = BreadcrumbTrail::home()
            ->to(BreadcrumbItem::link('Diensten & <meer>', '/en/services?x=1&y=2'))
            ->to(BreadcrumbItem::current('Houten plankjes'));

        ob_start();
        render_breadcrumb($trail);
        $alone = (string) ob_get_clean();

        $html = $this->renderPage($trail);

        $this->assertStringContainsString(trim($alone), $html, 'every level, link and escape exactly as the trail prints on its own');
        $this->assertStringContainsString('href="/en/services?x=1&amp;y=2"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The library knows the picture is used                               */
    /* ------------------------------------------------------------------ */

    public function testAPictureInEveryPlaceCountsAsUsed(): void
    {
        foreach ([PageHeroContent::IMAGE_BACKGROUND, PageHeroContent::IMAGE_LEFT, PageHeroContent::IMAGE_RIGHT] as $mode) {
            $this->removeHeader();
            $media = $this->mediaItem('Foto');
            $this->storeHeader(['media_id' => $media, 'image_mode' => $mode]);

            $usages = (new ContentBlockMediaUsage())->usagesFor([$media]);

            $this->assertNotEmpty($usages[$media] ?? [], $mode);
        }
    }

    /* ------------------------------------------------------------------ */
    /* The stylesheet                                                      */
    /* ------------------------------------------------------------------ */

    public function testTheStylesheetPlacesThePictureWithoutPushingTheText(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/page-hero.css');
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        // page-hero--split and page-hero--image-right are hooks without a
        // rule of their own: the two columns are page-hero__split's, and the
        // picture on the right is their order in the markup.
        foreach (['page-hero--background', 'page-hero--height-small', 'page-hero--height-large', 'page-hero__media', 'page-hero__body',
                     'page-hero--image-left', 'page-hero__split', 'page-hero__text', 'page-hero__figure'] as $class) {
            $this->assertStringContainsString('.' . $class, $rules, "{$class} is printed but not styled");
        }
        $this->assertStringNotContainsString('page-hero--image-right', $rules, 'right is the default column order and needs no rule');
        $this->assertStringNotContainsString('page-hero--height-medium', $rules, 'medium is the default and needs no rule');

        $this->assertStringNotContainsString('flex-end;', preg_replace('#\.page-hero--content-right \.breadcrumb\{[^}]*\}#', '', $rules), 'the text is not pinned to the bottom of the band');
        $this->assertDoesNotMatchRegularExpression('/margin[a-z-]*:\s*-/', $rules, 'no negative margin as a workaround');
        $this->assertMatchesRegularExpression('/\.page-hero__media\{[^}]*position:\s*absolute;[^}]*inset:\s*0;/', $rules, 'the picture is a layer, out of the flow');
        $this->assertMatchesRegularExpression('/\.page-hero--background \.page-hero__body\{\s*margin-block:\s*auto;\s*\}/', $rules, 'the text is centred in the room the band leaves');

        // Every height is a clamp of rem and vh, never a number from the database.
        preg_match_all('/--page-hero-height:\s*([^;]+);/', $rules, $heights);
        $this->assertCount(6, $heights[1], 'three steps, on a wide and on a narrow screen');
        foreach ($heights[1] as $height) {
            $this->assertMatchesRegularExpression('/^clamp\(\d+(\.\d+)?rem, \d+vh, \d+(\.\d+)?rem\)$/', trim($height));
        }
    }

    public function testANarrowScreenStacksThePictureAboveTheTextOnBothSides(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/page-hero.css'));

        $this->assertSame(1, preg_match('/@media \(max-width: 900px\)\{(.*?)\n\}/s', $css, $narrow));
        $this->assertMatchesRegularExpression('/\.page-hero__split,\s*\.page-hero--image-left \.page-hero__split\{\s*grid-template-columns:\s*minmax\(0, 1fr\);/', $narrow[1], 'one column');
        $this->assertMatchesRegularExpression('/\.page-hero__figure,\s*\.page-hero--image-left \.page-hero__figure\{[^}]*order:\s*-1;/', $narrow[1], 'the picture first, left and right alike');
        $this->assertStringContainsString('.page-hero--background.page-hero--height-large', $narrow[1], 'a large band is lower on a phone');
        $this->assertMatchesRegularExpression('/\.page-hero__figure\{[^}]*aspect-ratio:\s*4 \/ 3;[^}]*object-fit|\.page-hero__figure img\{[^}]*object-fit:\s*cover;/s', $css, 'a fixed shape, cropped, never stretched');
    }

    /* ------------------------------------------------------------------ */

    /**
     * A header on this test's page, attached the way the block picker
     * attaches one, then given these values; Dutch words for an eyebrow, a
     * title and an intro text, overridden where a test says so.
     *
     * @param array<string, mixed>  $values
     * @param array<string, string> $words
     */
    private function storeHeader(array $values, array $words = []): void
    {
        if ((new PageHeroRepository())->findBySlug(self::TEST_PAGE) === null) {
            $this->attach('page_hero');
        }

        $repository = new PageHeroRepository();
        $repository->upsert(self::TEST_PAGE, array_merge(PageHeroContent::startingValues(), ['is_active' => true], $values));

        $id = (int) $repository->findBySlug(self::TEST_PAGE)['id'];
        BlockLocalization::save('page_heroes', $id, 'nl', array_merge(
            ['eyebrow' => 'Bovenschrift', 'title' => 'Een paginakop', 'lead' => 'Een inleiding.', 'image_alt' => ''],
            $words
        ));

        self::clearCaches();
    }

    /** A block at the end of this test's page, attached exactly as the block picker attaches one. */
    private function attach(string $type): void
    {
        [$sectionId, $sectionKey] = SectionRegistry::create($type, self::TEST_PAGE);
        (new PageSectionRepository())->create($this->pageId(), self::TEST_PAGE, $type, $sectionKey, $sectionId);
        self::clearCaches();
    }

    private function removeHeader(): void
    {
        $sections = new PageSectionRepository();
        foreach ($sections->findForPage($this->pageId()) as $section) {
            if ($section['section_type'] === 'page_hero') {
                SectionRegistry::delete($section, $sections);
            }
        }
        self::clearCaches();
    }

    /** A media row for a file that does not exist: rendering never reads the disk. */
    private function mediaItem(string $altText): int
    {
        $id = (new MediaRepository())->create([
            'path' => 'assets/media/__page_hero_mode_' . count($this->mediaIds) . '__.webp',
            'original_filename' => 'page-hero-mode.webp',
            'mime_type' => 'image/webp',
            'width' => 1600,
            'height' => 900,
            'file_size' => 100,
            'alt_text' => $altText,
            'checksum' => null,
        ]);
        $this->mediaIds[] = $id;
        MediaService::clearCache();

        return $id;
    }

    private function trail(): BreadcrumbTrail
    {
        return BreadcrumbTrail::home()->to(BreadcrumbItem::current('Paginakop-weergave-testpagina'));
    }

    /** The header exactly as SectionRegistry::renderPage() has its definition render it, without a trail. */
    private function renderHeader(): string
    {
        $definition = BlockDefinitions::get('page_hero');
        $this->assertNotNull($definition);
        self::clearCaches();

        ob_start();
        try {
            $definition->render(['id' => 0, 'section_type' => 'page_hero', 'page_slug' => self::TEST_PAGE, 'section_key' => null], false, 'page_hero-0');
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** The whole page body the way a route prints it. */
    private function renderPage(?BreadcrumbTrail $trail): string
    {
        self::clearCaches();

        ob_start();
        try {
            SectionRegistry::renderPage(self::TEST_PAGE, $trail);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return list<string> the classes on the rendered <section>, in order */
    private function sectionClasses(string $html): array
    {
        $this->assertSame(1, preg_match('#<section class="([^"]*)"#', $html, $match), 'the header renders one section');

        return preg_split('/\s+/', trim($match[1])) ?: [];
    }

    private function pageId(): int
    {
        $page = PageContent::forContentKey(self::TEST_PAGE);
        $this->assertNotNull($page);

        return (int) $page['id'];
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::TEST_PAGE]);
        $row = $stmt->fetch();

        if ($row !== false) {
            $sections = new PageSectionRepository();
            foreach ($sections->findForPage((int) $row['id']) as $section) {
                SectionRegistry::delete($section, $sections);
            }
            $db->prepare('DELETE FROM pages WHERE id = :id')->execute(['id' => (int) $row['id']]);
        }

        // A header row that outlived its section (a test that stopped half way).
        (new PageHeroRepository())->deleteBySlug(self::TEST_PAGE);
        self::clearCaches();
    }

    private static function clearCaches(): void
    {
        PageHeroContent::clearCache();
        PageContent::clearCache();
        MediaService::clearCache();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\MediaRepository;
use App\Repository\PageHeroRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\Language\SiteLanguages;
use App\Service\Media\MediaService;
use App\Service\PageHeroContent;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * What an editor can choose for a Paginakop, as a visitor gets it: the markup
 * App\Service\Blocks\PageHeroBlock::render() prints.
 *
 * - A header nobody gave a choice prints the markup it always printed, so an
 *   existing installation looks the same after the migration.
 * - An empty eyebrow or intro text leaves no element behind.
 * - The image comes from the Media Library with its size. Behind the text it
 *   is decoration (`alt=""`); beside the text an item without alt text is
 *   marked decorative. Where the picture goes, its height and its focus are
 *   Tests\Service\PageHeroImageModeTest's.
 * - Each text position and each size is exactly one modifier class and a
 *   default is none; every class the partial can print is styled by the
 *   block's own stylesheet, in steps of the type scale.
 * - A stored value outside the closed lists reads as the default.
 *
 * Hidden, missing and title-less rows are Tests\Service\NoEmptyActiveBlockTest's,
 * which holds every type's render contract in one place; saving through the
 * editor is Tests\Service\PageHeroEditorHttpTest's.
 *
 * Everything runs inside a transaction that is rolled back afterwards, on a
 * page slug no real page has, the media rows included.
 */
final class PageHeroHeaderTest extends TestCase
{
    /** Where this test's header lives; never a real page. */
    private const TEST_SLUG = '__test_page_hero_header__';

    private bool $inTransaction = false;

    protected function setUp(): void
    {
        // The partial resolves the primary language through SiteSettings.
        SiteSettings::all();
        self::clearCaches();

        Database::connection()->beginTransaction();
        $this->inTransaction = true;
    }

    protected function tearDown(): void
    {
        if ($this->inTransaction) {
            Database::connection()->rollBack();
            $this->inTransaction = false;
        }

        self::clearCaches();
    }

    /* ------------------------------------------------------------------ */
    /* What was there keeps looking the same                               */
    /* ------------------------------------------------------------------ */

    public function testAHeaderNobodyGaveAChoiceKeepsTheMarkupItAlwaysHad(): void
    {
        // Only the columns a page_heroes row has had since before the choices
        // existed, the way 20260908100300 inserts one; MySQL fills in the
        // rest. Its words are where 20260917180000 moved them: Dutch, in
        // block_translations.
        Database::connection()->prepare(
            'INSERT INTO page_heroes (page_slug, is_active, created_at, updated_at) VALUES (:slug, 1, NOW(), NOW())'
        )->execute(['slug' => self::TEST_SLUG]);

        $row = (new PageHeroRepository())->findBySlug(self::TEST_SLUG);
        $this->assertNotNull($row);
        BlockLocalization::save('page_heroes', (int) $row['id'], 'nl', [
            'eyebrow' => 'Over ons',
            'title' => 'Een bestaande kop',
            'lead' => 'Een bestaande inleiding.',
        ]);
        $this->assertNull($row['media_id'], 'an existing header has no image');
        $this->assertSame(
            [PageHeroContent::POSITION_LEFT, PageHeroContent::SIZE_NORMAL, PageHeroContent::SIZE_NORMAL],
            [$row['content_position'], $row['title_size'], $row['text_size']],
            'and the defaults are the look it had'
        );

        $html = $this->render();

        $this->assertSame(['page-hero'], $this->sectionClasses($html), 'no modifier class, so page-hero.css has nothing to say');
        $this->assertStringNotContainsString('page-hero__media', $html);
        $this->assertMatchesRegularExpression(
            '#<p class="eyebrow"[^>]*>Over ons</p>\s*<h1[^>]*>Een bestaande kop</h1>\s*<p class="lead" style="margin-top:1rem;"[^>]*>Een bestaande inleiding\.</p>#s',
            $html,
            'eyebrow, title and intro text, in the order and the markup they always had'
        );
        $this->assertStringNotContainsString(
            'breadcrumb',
            $html,
            'the trail belongs to the page and is printed before this block, never inside it'
        );
    }

    public function testANewHeaderStartsWithoutAnEyebrowAndWithTodaysLook(): void
    {
        // create() exactly as the block picker and the page templates call it.
        $definition = BlockDefinitions::get('page_hero');
        $this->assertNotNull($definition);
        $definition->create(self::TEST_SLUG);

        $row = (new PageHeroRepository())->findBySlug(self::TEST_SLUG);
        $this->assertNotNull($row);
        $this->assertSame('', BlockLocalization::raw('page_heroes', (int) $row['id'], 'eyebrow', BlockLocalization::defaultLanguage()), 'an optional eyebrow nobody chose is not written for them');
        $this->assertNull($row['media_id']);
        $this->assertSame(
            [PageHeroContent::POSITION_LEFT, PageHeroContent::SIZE_NORMAL, PageHeroContent::SIZE_NORMAL],
            [$row['content_position'], $row['title_size'], $row['text_size']]
        );

        $html = $this->render();

        $this->assertSame(['page-hero'], $this->sectionClasses($html));
        $this->assertStringNotContainsString('class="eyebrow"', $html);
        $this->assertStringContainsString('pas deze titel aan', $html);
    }

    /* ------------------------------------------------------------------ */
    /* An empty field leaves nothing behind                                */
    /* ------------------------------------------------------------------ */

    public function testAnEmptyEyebrowPrintsNoEyebrowElement(): void
    {
        $this->store([], ['nl' => ['eyebrow' => '']]);

        $html = $this->render();

        $this->assertStringContainsString('<h1', $html, 'the header itself still renders');
        $this->assertStringNotContainsString('class="eyebrow"', $html);
    }

    public function testAnEyebrowOnlyInTheTranslationPrintsNoElement(): void
    {
        // What decides is the default language's own words
        // (BlockLocalization::hasDefaultWords()). A translation without them
        // would be an empty decoration for everyone reading the default
        // language.
        $this->assertSame('nl', SiteLanguages::defaultCode(), 'written for the Dutch-default site the test database is');
        $this->store([], ['nl' => ['eyebrow' => ''], 'en' => ['eyebrow' => 'About us']]);

        $this->assertStringNotContainsString('class="eyebrow"', $this->render());
    }

    public function testAnEmptyIntroTextPrintsNoParagraph(): void
    {
        $this->store([], ['nl' => ['lead' => '']]);

        $html = $this->render();

        $this->assertStringContainsString('<h1', $html);
        $this->assertStringNotContainsString('class="lead"', $html);
    }

    /* ------------------------------------------------------------------ */
    /* The image comes from the library                                    */
    /* ------------------------------------------------------------------ */

    public function testALibraryImageSitsBehindTheTextAsDecorationWithItsSize(): void
    {
        $mediaId = $this->mediaItem('assets/media/__page_hero_header__.webp', 'Werkbank met houten plankjes', 1600, 900);
        $this->store(['media_id' => $mediaId, 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->render();

        $this->assertContains('page-hero--background', $this->sectionClasses($html));
        $this->assertMatchesRegularExpression(
            '#<div class="page-hero__media">\s*<img src="/assets/media/__page_hero_header__\.webp" alt="" width="1600" height="900" loading="eager"#',
            $html,
            'behind the text the picture sets the mood: the header\'s words say what the page is'
        );
        $this->assertLessThan(
            strpos($html, '<div class="container page-hero__body">'),
            strpos($html, 'page-hero__media'),
            'the image comes before the text, so the text is drawn over it'
        );
    }

    public function testAnImageWithoutAltTextIsMarkedDecorative(): void
    {
        $mediaId = $this->mediaItem('assets/media/__page_hero_mood__.webp', '', 1600, 900);
        $this->store(['media_id' => $mediaId, 'image_mode' => PageHeroContent::IMAGE_LEFT]);

        $this->assertMatchesRegularExpression(
            '#<img src="/assets/media/__page_hero_mood__\.webp" alt=""#',
            $this->render(),
            'beside the text, where a picture with alt text would carry it'
        );
    }

    public function testAnImageOfUnknownSizePrintsNoSizeAttributes(): void
    {
        $mediaId = $this->mediaItem('assets/media/__page_hero_unknown_size__.webp', 'Foto', null, null);
        $this->store(['media_id' => $mediaId, 'image_mode' => PageHeroContent::IMAGE_BACKGROUND]);

        $html = $this->render();

        $this->assertStringContainsString('page-hero__media', $html);
        $this->assertStringNotContainsString(' width=', $html, 'unknown means leave it out, never guess');
    }

    public function testAHeaderWithoutAnImageHasNoMediaMarkupWhateverElseItChose(): void
    {
        $this->store([
            'content_position' => PageHeroContent::POSITION_CENTER,
            'title_size' => PageHeroContent::SIZE_LARGE,
            'text_size' => PageHeroContent::SIZE_LARGE,
        ]);

        $html = $this->render();

        $this->assertNotContains('page-hero--background', $this->sectionClasses($html));
        $this->assertStringNotContainsString('<img', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Each choice is one class; a default is none                         */
    /* ------------------------------------------------------------------ */

    /** @return array<string, array{string, string, list<string>}> */
    public static function choices(): array
    {
        return [
            'text on the left' => ['content_position', PageHeroContent::POSITION_LEFT, []],
            'text in the middle' => ['content_position', PageHeroContent::POSITION_CENTER, ['page-hero--content-center']],
            'text on the right' => ['content_position', PageHeroContent::POSITION_RIGHT, ['page-hero--content-right']],
            'a small title' => ['title_size', PageHeroContent::SIZE_SMALL, ['page-hero--title-small']],
            'a normal title' => ['title_size', PageHeroContent::SIZE_NORMAL, []],
            'a large title' => ['title_size', PageHeroContent::SIZE_LARGE, ['page-hero--title-large']],
            'a small intro text' => ['text_size', PageHeroContent::SIZE_SMALL, ['page-hero--text-small']],
            'a normal intro text' => ['text_size', PageHeroContent::SIZE_NORMAL, []],
            'a large intro text' => ['text_size', PageHeroContent::SIZE_LARGE, ['page-hero--text-large']],
        ];
    }

    /**
     * @dataProvider choices
     *
     * @param list<string> $modifiers
     */
    public function testEachChoiceAddsExactlyItsOwnClass(string $column, string $value, array $modifiers): void
    {
        $this->store([$column => $value]);

        $this->assertSame(['page-hero', ...$modifiers], $this->sectionClasses($this->render()));
    }

    /** The cases above cover each closed list completely, and nothing beyond it. */
    public function testTheCasesCoverEveryPositionAndEverySize(): void
    {
        $covered = [];
        foreach (self::choices() as [$column, $value]) {
            $covered[$column][] = $value;
        }

        $this->assertSame(PageHeroContent::POSITIONS, $covered['content_position']);
        $this->assertSame(PageHeroContent::SIZES, $covered['title_size']);
        $this->assertSame(PageHeroContent::SIZES, $covered['text_size']);
    }

    public function testAStoredValueOutsideTheClosedListsRendersAsTheDefault(): void
    {
        $this->store([]);

        // Only a hand-edited row can hold these: the endpoint refuses them.
        Database::connection()->prepare(
            'UPDATE page_heroes SET content_position = :position, title_size = :title, text_size = :text WHERE page_slug = :slug'
        )->execute(['position' => 'diagonal', 'title' => '96px', 'text' => 'huge', 'slug' => self::TEST_SLUG]);

        self::clearCaches();
        $content = PageHeroContent::forSlug(self::TEST_SLUG);

        $this->assertSame(
            [PageHeroContent::POSITION_LEFT, PageHeroContent::SIZE_NORMAL, PageHeroContent::SIZE_NORMAL],
            [$content['content_position'], $content['title_size'], $content['text_size']]
        );
        $this->assertSame(['page-hero'], $this->sectionClasses($this->render()));
    }

    /**
     * Every class the partial can print has a rule in the block's own
     * stylesheet, no default has one, and a size is a step of the type scale
     * rather than a number of its own.
     */
    public function testEveryClassThePartialCanPrintIsStyledInStepsOfTheTypeScale(): void
    {
        $definition = BlockDefinitions::get('page_hero');
        $this->assertNotNull($definition);
        $this->assertSame(['assets/css/blocks/page-hero.css'], $definition->styles());

        $printed = ['page-hero--background', 'page-hero__media'];
        foreach (self::choices() as [, , $modifiers]) {
            array_push($printed, ...$modifiers);
        }

        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/blocks/page-hero.css');

        foreach ($printed as $class) {
            $this->assertStringContainsString('.' . $class, $css, "{$class} is printed but not styled");
        }

        foreach (['content-left', 'title-normal', 'text-normal'] as $default) {
            $this->assertStringNotContainsString('page-hero--' . $default, $css, 'a default must look exactly as before');
        }

        preg_match_all('/font-size:\s*([^;}]+)/', $css, $sizes);
        $this->assertNotSame([], $sizes[1]);

        foreach ($sizes[1] as $size) {
            $this->assertMatchesRegularExpression('/^var\(--fs-[a-z0-9]+\)$/', trim($size), 'a size is a step of the type scale in core.css');
        }

        $this->assertMatchesRegularExpression(
            '/--fs-display:\s*clamp\(/',
            (string) file_get_contents(dirname(__DIR__, 2) . '/assets/css/core.css'),
            'the step above h1 is defined with the rest of the scale'
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Every column PageHeroRepository::upsert() writes, no image and today's
     * look, and Dutch words for a title, an eyebrow and an intro text, each
     * overridden where a test says so. $words holds the words per language;
     * the Dutch ones are merged over the defaults.
     *
     * @param array<string, mixed>                 $overrides
     * @param array<string, array<string, string>> $words language => field => words
     */
    private function store(array $overrides, array $words = []): void
    {
        $repository = new PageHeroRepository();
        $repository->upsert(self::TEST_SLUG, array_merge([
            'media_id' => null,
            'content_position' => PageHeroContent::POSITION_LEFT,
            'title_size' => PageHeroContent::SIZE_NORMAL,
            'text_size' => PageHeroContent::SIZE_NORMAL,
            'is_active' => true,
        ], $overrides));

        $id = (int) $repository->findBySlug(self::TEST_SLUG)['id'];
        $words['nl'] = array_merge(['eyebrow' => 'Bovenschrift', 'title' => 'Een paginakop', 'lead' => 'Een inleiding.'], $words['nl'] ?? []);

        foreach ($words as $language => $fields) {
            BlockLocalization::save('page_heroes', $id, $language, $fields);
        }
    }

    /** A media row for a file that does not exist: rendering never reads the disk. */
    private function mediaItem(string $path, string $altText, ?int $width, ?int $height): int
    {
        $id = (new MediaRepository())->create([
            'path' => $path,
            'original_filename' => basename($path),
            'mime_type' => 'image/webp',
            'width' => $width,
            'height' => $height,
            'file_size' => 100,
            'alt_text' => $altText,
            'checksum' => null,
        ]);

        MediaService::clearCache();

        return $id;
    }

    /** The header exactly as SectionRegistry::renderPage() has its definition render it. */
    private function render(): string
    {
        $definition = BlockDefinitions::get('page_hero');
        $this->assertNotNull($definition);

        self::clearCaches();

        ob_start();
        try {
            $definition->render(
                ['id' => 0, 'section_type' => 'page_hero', 'page_slug' => self::TEST_SLUG, 'section_key' => null, 'section_id' => 0],
                false,
                'page_hero-0'
            );
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

    private static function clearCaches(): void
    {
        PageHeroContent::clearCache();
        MediaService::clearCache();
    }
}

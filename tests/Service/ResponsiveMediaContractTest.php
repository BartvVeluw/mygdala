<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Blocks\BlockDefinitions;
use App\Service\CardCarouselContent;
use App\Service\CtaBandContent;
use App\Service\HomepageHeroContent;
use App\Service\HoverCardGridContent;
use App\Service\Media\ResponsiveImage;
use App\Service\Media\ResponsiveImageSlot;
use App\Service\MediaBannerContent;
use App\Service\PageHeroContent;
use App\Service\TextImageSplitContent;
use PHPUnit\Framework\TestCase;

/**
 * The promises of Responsive Media 2.0 that live across files, read from the
 * source (MEDIA.md, "Responsive Media"):
 *
 *   - ONE BREAKPOINT: every rule for a phone's picture, point, fit or height
 *     sits under (max-width: ResponsiveImage::MOBILE_MAX_WIDTH px), the number
 *     the <source> of every phone picture carries;
 *   - ONE MARKUP: only partials/responsive-image.php prints a <source> or an
 *     object-position; every block that prints a picture through it asks for
 *     the shared stylesheet, first;
 *   - ONE LIST: the seven places, their slots, the repository's tables and
 *     the media usage all name the same columns, so a phone picture in use
 *     can never be deleted;
 *   - NO SECOND TRUTH: nothing reads the nine-key focus columns the
 *     migration dropped.
 */
final class ResponsiveMediaContractTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** table => [its slot, the block type that owns it, the partial that prints it] */
    private static function places(): array
    {
        return [
            'carousel_cards' => [CardCarouselContent::imageSlot(), 'card_carousel', 'partials/section-card-carousel.php'],
            'text_image_split_items' => [TextImageSplitContent::imageSlot(), 'text_image_split', 'partials/section-text-image-split.php'],
            'page_heroes' => [PageHeroContent::imageSlot(), 'page_hero', 'partials/section-page-hero.php'],
            'cta_bands' => [CtaBandContent::backgroundSlot(), 'cta_band', 'partials/section-cta-band.php'],
            'media_banners' => [MediaBannerContent::imageSlot(), 'media_banner', 'partials/section-media-banner.php'],
            'hover_card_grid_items' => [HoverCardGridContent::imageSlot(), 'hover_card_grid', 'partials/section-hover-card-grid.php'],
            'homepage_hero' => [HomepageHeroContent::imageSlot(), 'homepage_hero', 'partials/section-homepage-hero.php'],
        ];
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::ROOT . '/' . $relative);
        self::assertIsString($contents, $relative);

        return str_replace("\r\n", "\n", $contents);
    }

    /** @return list<string> repository-relative paths of the PHP files under $dir */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $dir . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen(self::ROOT . '/' . $dir) + 1));
            }
        }
        sort($files);

        return $files;
    }

    public function testEveryPhoneRuleSitsUnderTheOneBreakpoint(): void
    {
        $breakpoint = '@media (max-width: ' . ResponsiveImage::MOBILE_MAX_WIDTH . 'px)';

        // The shared rules: one media query, and the phone's point and fit in it.
        $shared = self::read('assets/css/responsive-media.css');
        self::assertSame(1, preg_match_all('/@media[^{]*\{/', $shared, $queries));
        self::assertStringContainsString($breakpoint . '{', str_replace(' {', '{', $shared));
        self::assertMatchesRegularExpression('/@media \(max-width: ' . ResponsiveImage::MOBILE_MAX_WIDTH . 'px\)\s*\{[^@]*--rm-mobile-position[^@]*--rm-mobile-fit/s', $shared);
        self::assertStringContainsString('.rm-picture{ display: contents; }', $shared, 'the wrapper takes no room of its own');

        // A phone's own height, in the three blocks that have one.
        foreach ([
            'assets/css/blocks/page-hero.css' => 'page-hero--mobile-',
            'assets/css/blocks/media-banner.css' => 'media-banner--mobile-',
            'assets/css/blocks/text-image-split.css' => 'text-image__item--mobile-',
        ] as $file => $class) {
            $css = self::read($file);
            preg_match_all('/@media ([^{]+)\{((?:[^{}]*\{[^{}]*\})*)\s*\}/', $css, $blocks, PREG_SET_ORDER);
            $holding = array_values(array_filter($blocks, static fn (array $block): bool => str_contains($block[2], $class)));

            self::assertCount(1, $holding, $file . ': one media query holds the phone heights');
            self::assertSame('(max-width: ' . ResponsiveImage::MOBILE_MAX_WIDTH . 'px)', trim($holding[0][1]), $file);
            foreach (ResponsiveImage::MOBILE_HEIGHTS as $height) {
                self::assertStringContainsString($class . $height, $holding[0][2], $file);
            }
            self::assertSame(count(ResponsiveImage::MOBILE_HEIGHTS), substr_count($css, $class), $file . ': no phone height outside it');
        }
    }

    public function testOnlyTheSharedPartialPrintsASourceOrAnObjectPosition(): void
    {
        $printers = [];
        foreach ([...self::phpFiles('partials'), ...array_map(static fn (string $f): string => basename($f), glob(self::ROOT . '/*.php') ?: [])] as $file) {
            $source = self::read($file);
            if (preg_match('/<source\b|object-position:/i', $source) === 1) {
                $printers[] = $file;
            }
        }

        self::assertSame(['partials/responsive-image.php'], $printers);
    }

    public function testEveryBlockThatPrintsAPictureThroughItAsksForTheSharedStylesheetFirst(): void
    {
        $callers = [];
        foreach (self::phpFiles('partials') as $file) {
            if ($file !== 'partials/responsive-image.php' && preg_match('/\b(render_responsive_image|responsive_image_html)\(/', self::read($file)) === 1) {
                $callers[] = $file;
            }
        }

        $expected = array_merge(array_column(self::places(), 2), ['partials/media-sequence.php']);
        sort($expected);
        self::assertSame($expected, $callers, 'a new caller is a new place: add it to this list, the slots and MEDIA.md');

        foreach (self::places() as [, $type]) {
            $definition = BlockDefinitions::get($type);
            self::assertNotNull($definition, $type);
            self::assertSame('assets/css/responsive-media.css', $definition->styles()[0] ?? null, $type);
        }
    }

    public function testThePlacesTheRepositoryAndTheMediaUsageNameTheSameColumns(): void
    {
        $repository = self::read('src/Repository/ResponsiveImageRepository.php');
        preg_match('/private const TABLES = \[(.*?)\];/s', $repository, $list);
        preg_match_all("/'([a-z_]+)'/", $list[1] ?? '', $tables);
        self::assertSame(array_keys(self::places()), $tables[1], 'the repository writes exactly the seven places');

        $usage = self::read('src/Service/Media/Usage/ContentBlockMediaUsage.php');
        foreach (self::places() as $table => [$slot]) {
            self::assertInstanceOf(ResponsiveImageSlot::class, $slot);
            $mobile = $slot->column('mobile_media_id');
            // One branch of the UNION: SELECT <alias>.<column> … FROM <table> <alias>.
            self::assertMatchesRegularExpression(
                '/SELECT\s+(\w+)\.' . preg_quote($mobile, '/') . '\b(?:(?!UNION).)*?\bFROM\s+' . preg_quote($table, '/') . '\s+\1\b/s',
                $usage,
                $table . '.' . $mobile . ' is a use of its picture'
            );
        }

        // Both migrations list the same seven places.
        foreach (['db/migrations/20260928220000_give_block_images_a_free_focus_point.php', 'db/migrations/20260928230000_give_block_images_a_mobile_presentation.php'] as $migration) {
            $source = self::read($migration);
            foreach (array_keys(self::places()) as $table) {
                self::assertStringContainsString("'" . $table . "' => [", $source, $migration);
            }
        }
    }

    public function testNothingReadsTheNineKeyColumnsAnyMore(): void
    {
        // Code, not history: a quoted name, a key of an array or a column
        // in SQL. A docblock may still tell where the value came from.
        $readers = [];
        foreach (['src', 'partials', 'admin', 'api'] as $dir) {
            foreach (self::phpFiles($dir) as $file) {
                $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', self::read($file));
                if (preg_match('/\b(image_focus|background_focus)\b(?!_)/', (string) $code) === 1) {
                    $readers[] = $file;
                }
            }
        }

        self::assertSame([], $readers);
    }

    public function testTheEditorScriptHoldsNoWordsOfItsOwn(): void
    {
        $script = self::read('admin/assets/responsive-image.js');

        // Only comments may be prose (ADMIN-UI.md): strip them and look for Dutch words in strings.
        $code = preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $script);
        self::assertDoesNotMatchRegularExpression('/"[^"]*\b(afbeelding|telefoon|focuspunt|horizontaal|verticaal)\b[^"]*"/i', (string) $code);
        self::assertStringContainsString('data-rm-value-template', $script, 'the words come from the field');
    }
}

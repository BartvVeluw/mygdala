<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * Detailsectie 2.0 and the navigation polish, pinned in the source: what a
 * rendered page cannot show a test without a browser.
 *
 *   - the phone strip: a native scroll-snap strip below the grid's own
 *     breakpoint (so a swipe is the browser's scroll and a tap on a linked
 *     item stays a tap), arrows that stop at either end, the arrow keys, and
 *     no glide for a visitor who asked for less motion;
 *   - the gallery sources: Core's LinkedImages names no module, and every
 *     module that has a destination with a picture contributes one;
 *   - the mobile menu: an item with a submenu is centred on its label — the
 *     toggle hangs beside it, keeps its 44px touch target and its
 *     aria-expanded/aria-controls — and only inside the phone menu, so the
 *     desktop bar is untouched.
 */
final class DetailSectionTwoContractTest extends TestCase
{
    public function testThePhoneStripIsANativeSnapStripWithArrowsThatStop(): void
    {
        $css = self::source('assets/css/blocks/detail-section.css');
        $this->assertMatchesRegularExpression('/@media \(max-width: 640px\)\{[\s\S]*scroll-snap-type: x mandatory;[\s\S]*flex: 0 0 100%; scroll-snap-align: start;/', $css, 'one item at a time, below the grid breakpoint');
        $this->assertStringContainsString('overflow-x: auto;', $css, 'the strip scrolls inside itself');
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('width: 46px; height: 46px;', $css, 'the carousel\'s own button size');

        $core = self::source('assets/css/core.css');
        $this->assertStringContainsString('@media (max-width: 640px){ .service-detail__gallery{ grid-template-columns: 1fr 1fr; } }', $core, 'the grid rule stays; the strip overrides it in the block\'s own stylesheet');

        $js = self::source('assets/js/blocks/detail-section.js');
        $this->assertStringContainsString('prev.disabled = index === 0;', $js);
        $this->assertStringContainsString('next.disabled = index === items.length - 1;', $js, 'no wrap-around, as the carousel\'s flat strip');
        $this->assertStringContainsString('"ArrowRight"', $js);
        $this->assertStringContainsString('"ArrowLeft"', $js);
        $this->assertStringContainsString('behavior: reducedMotion ? "auto" : "smooth"', $js);
        $this->assertStringNotContainsString('touchstart', $js, 'no gesture code: the swipe is the browser\'s');
        $this->assertStringNotContainsString('preventDefault(); // touch', $js);
    }

    public function testCoreLinkedImagesNamesNoModuleAndTheModulesContributeTheirPicture(): void
    {
        $core = self::source('src/Service/Media/LinkedImages.php');
        foreach (['ProductRepository', 'PortfolioGalleryRepository', 'BlogPostRepository', "'product'", "'blog_post'", "'portfolio_project'"] as $name) {
            $this->assertStringNotContainsString($name, $core, $name);
        }
        $this->assertStringContainsString("collectMap('linkedImages')", $core);

        $this->assertStringContainsString("'product' => static function (int \$id): ?array", self::source('src/Module/ShopModule.php'));
        $this->assertStringContainsString("'portfolio_project' => static function (int \$id): ?array", self::source('src/Module/PortfolioModule.php'));
        $this->assertStringContainsString("'blog_post' => static function (int \$id): ?array", self::source('src/Module/BlogModule.php'));

        $detail = self::source('src/Service/DetailSectionContent.php');
        $this->assertStringContainsString('LinkedImages::resolve(', $detail);
        foreach (["'product'", "'blog_post'", "'portfolio_project'", 'Shop', 'Blog'] as $name) {
            $this->assertStringNotContainsString($name, $detail, 'Detailsectie names no provider: ' . $name);
        }
    }

    public function testTheAnchorNavigationIsTheOneSnelnavigatie(): void
    {
        $registry = self::source('src/Service/SectionRegistry.php');
        $this->assertStringContainsString('AnchorNavigation::render($anchors)', $registry);
        $this->assertStringContainsString('instanceof RendersAnchorNavigation', $registry);
        $this->assertStringContainsString('instanceof ContributesAnchor', $registry);

        $this->assertStringContainsString('render_section_quicknav($items)', self::source('src/Service/Blocks/AnchorNavigation.php'), 'the same partial, never a second navigation');
        $this->assertStringContainsString('implements RendersAnchorNavigation', self::source('src/Service/Blocks/QuicknavBlock.php'));
        $this->assertStringContainsString('implements ContributesAnchor', self::source('src/Service/Blocks/DetailSectionBlock.php'));
    }

    public function testTheMobileMenuCentresOnTheLabelAndKeepsTheToggle(): void
    {
        $css = self::source('assets/css/core.css');
        $mobile = substr($css, (int) strpos($css, '/* THE LABEL IS THE AXIS.'));
        $mobile = substr($mobile, 0, (int) strpos($mobile, '.main-nav__submenu,'));

        $this->assertStringContainsString('.main-nav__row{ position: relative; max-width: none; margin-inline: calc(44px + 0.25rem); }', $mobile, 'equal room on both sides: the label is the axis');
        $this->assertStringContainsString('.main-nav__row > .main-nav__link + .main-nav__toggle{ position: absolute; left: 100%;', $mobile);
        $this->assertStringContainsString('height: 44px;', $mobile, 'the touch target stays 44px');
        $this->assertStringContainsString('.main-nav__toggle--heading > .main-nav__chevron{ position: absolute; left: 100%;', $mobile);
        $this->assertStringNotContainsString('transform:', $mobile, 'the chevron\'s rotate stays its only transform');

        $before = substr($css, 0, (int) strpos($css, '/* THE LABEL IS THE AXIS.'));
        $this->assertGreaterThan((int) strrpos($before, '@media (max-width: 900px)'), (int) strrpos($before, '.nav-toggle{ display: inline-flex; }'), 'inside the phone menu\'s media query');

        $markup = self::source('partials/main-nav-list.php');
        $this->assertStringContainsString('<button type="button" class="main-nav__toggle" aria-expanded="false" aria-controls=', $markup);
        $this->assertStringContainsString('<button type="button" class="main-nav__toggle main-nav__toggle--heading" aria-expanded="false" aria-controls=', $markup);
    }

    private static function source(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}

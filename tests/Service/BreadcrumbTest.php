<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageHeroRepository;
use App\Repository\PageRepository;
use App\Repository\PageTranslationRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Breadcrumbs\BreadcrumbItem;
use App\Service\Breadcrumbs\BreadcrumbTrail;
use App\Service\Breadcrumbs\PageBreadcrumb;
use App\Service\Language\LanguageRegistry;
use App\Service\PageContent;
use App\Service\PageHeroContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The one breadcrumb of this site: which levels a page gets
 * (App\Service\Breadcrumbs) and what they look like
 * (partials/breadcrumb.php).
 *
 * WHAT THIS PHASE CHANGED, and every assertion below is one half of it:
 *
 * - the trail no longer lives inside the Paginakop, so hiding, deleting or
 *   never adding that block leaves it standing;
 * - its label is the page's own title, read per render, instead of a copy
 *   typed into `page_heroes.breadcrumb_label_nl` that fell behind a rename;
 * - the homepage link has one spelling, the site root's own address, instead
 *   of the three the templates had grown;
 * - the markup is a named `<nav>` around an `<ol>`, the current page is not a
 *   link, and the separator is decorative.
 *
 * Everything runs inside a transaction that is rolled back afterwards, on
 * content keys and slugs no real page has.
 */
final class BreadcrumbTest extends TestCase
{
    /** Never a real page. */
    private const TEST_KEY = '__test_breadcrumb__';
    private const TEST_SLUG = '__test-breadcrumb__';

    private bool $inTransaction = false;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/partials/breadcrumb.php';
    }

    protected function setUp(): void
    {
        // The renderer resolves the primary language through SiteSettings.
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

    private static function clearCaches(): void
    {
        PageContent::clearCache();
        PageHeroContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The markup                                                          */
    /* ------------------------------------------------------------------ */

    public function testATrailIsANamedLandmarkAroundAnOrderedList(): void
    {
        $html = $this->render($this->pageTrail());

        $this->assertStringContainsString('<nav class="breadcrumb-bar" aria-label="Kruimelpad"', $html);
        $this->assertStringContainsString(
            'data-nl-aria="Kruimelpad" data-en-aria="Breadcrumb"',
            $html,
            'the landmark is named in both languages, so the language switch reaches it too'
        );
        $this->assertStringContainsString('<ol class="breadcrumb">', $html);
        $this->assertSame(2, substr_count($html, '<li class="breadcrumb__item">'));
        $this->assertStringContainsString('</ol>', $html);
        $this->assertStringContainsString('</nav>', $html);
    }

    public function testTheCurrentPageIsNotALinkAndSaysSo(): void
    {
        $html = $this->render($this->pageTrail());

        $this->assertMatchesRegularExpression(
            '#<span class="breadcrumb__current" aria-current="page"[^>]*>Testpagina kruimelpad</span>#',
            $html,
            'the page a visitor is standing on is text, and carries aria-current'
        );
        $this->assertSame(1, substr_count($html, '<a href='), 'only the homepage is a link on an ordinary page');
    }

    public function testTheSeparatorIsDecorativeAndSitsInsideTheItem(): void
    {
        $html = $this->render($this->pageTrail());

        $this->assertSame(
            1,
            substr_count($html, '<span class="breadcrumb__separator" aria-hidden="true">/</span>'),
            'one separator between two levels, hidden from the accessibility tree'
        );
        $this->assertStringNotContainsString(
            '</li><span',
            $html,
            'an <ol> may hold nothing but <li>, so the separator lives inside the following item'
        );
    }

    public function testBothLanguagesTravelOnTheLeafElement(): void
    {
        // assets/js/core.js swaps data-nl/data-en by assigning innerHTML, so a
        // pair on the <li> would wipe out the link inside it on the first
        // toggle.
        $html = $this->render(
            BreadcrumbTrail::home()
                ->to(BreadcrumbItem::link('Blog', 'Journal', '/blog'))
                ->to(BreadcrumbItem::current('Een bericht', 'A post'))
        );

        $this->assertStringContainsString('<a href="/blog" data-nl="Blog" data-en="Journal">Blog</a>', $html);
        $this->assertStringContainsString('data-nl="Een bericht" data-en="A post"', $html);
        $this->assertStringNotContainsString('<li class="breadcrumb__item" data-nl', $html);
    }

    public function testEveryLabelAndAddressIsEscaped(): void
    {
        $html = $this->render(
            BreadcrumbTrail::home()
                ->to(BreadcrumbItem::link('Shop', 'Shop', '/zoek?a="b"&c'))
                ->to(BreadcrumbItem::current('<script>alert(1)</script>', 'A & B'))
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('href="/zoek?a=&quot;b&quot;&amp;c"', $html);
        $this->assertStringContainsString('data-en="A &amp; B"', $html);
    }

    public function testALevelWithNothingToReadIsLeftOut(): void
    {
        $html = $this->render(
            BreadcrumbTrail::home()
                ->to(BreadcrumbItem::link('', '', '/nergens'))
                ->to(BreadcrumbItem::current('Contact', 'Contact'))
        );

        $this->assertStringNotContainsString('/nergens', $html, 'an empty level is left out, never rendered blank');
        $this->assertSame(2, substr_count($html, '<li class="breadcrumb__item">'));
        $this->assertSame(1, substr_count($html, 'breadcrumb__separator'));
    }

    public function testNothingIsRenderedWithoutATrailOrWithOnlyAHomepage(): void
    {
        $this->assertSame('', trim($this->render(null)));
        $this->assertSame(
            '',
            trim($this->render(BreadcrumbTrail::home())),
            '"Home" on its own says nothing about where a visitor is'
        );
    }

    public function testTheReadingColumnIsAnOptionAndNotTheDefault(): void
    {
        $trail = $this->pageTrail();

        $this->assertStringContainsString('<div class="container">', $this->render($trail));
        $this->assertStringContainsString('<div class="container container--narrow">', $this->render($trail, true));
    }

    /* ------------------------------------------------------------------ */
    /* The homepage level                                                  */
    /* ------------------------------------------------------------------ */

    public function testTheHomepageLinkIsTheSiteRootsOwnAddress(): void
    {
        $home = PageContent::forContentKey('index');
        $this->assertNotNull($home, 'this database has a site root');

        $html = $this->render($this->pageTrail());

        $this->assertStringContainsString(
            '<a href="' . PageContent::publicUrl($home) . '" data-nl="Home" data-en="Home">Home</a>',
            $html,
            'one spelling, resolved the way every other page link on this site is'
        );
        $this->assertStringNotContainsString(
            'href="index.php"',
            $html,
            'the relative spelling fourteen templates used is gone'
        );
        $this->assertStringNotContainsString('href="/index.php"', $html, 'and so is the absolute one');
    }

    /* ------------------------------------------------------------------ */
    /* An ordinary CMS page                                                */
    /* ------------------------------------------------------------------ */

    public function testAnOrdinaryPageIsHomeAndItself(): void
    {
        $page = $this->storePage();

        $this->assertSame(
            ['Home', 'Testpagina kruimelpad'],
            $this->labels(PageBreadcrumb::forPage($page)),
            'two levels, and the second one is the page'
        );
    }

    public function testRenamingThePageRenamesItsTrail(): void
    {
        $page = $this->storePage();
        $this->assertSame('Testpagina kruimelpad', $this->labels(PageBreadcrumb::forPage($page))[1]);

        $this->renameTo('Een heel andere naam');

        $this->assertSame(
            ['Home', 'Een heel andere naam'],
            $this->labels(PageBreadcrumb::forPage($this->reload())),
            'the label is read from the page, never copied, so a rename follows through by itself'
        );
    }

    public function testAnUntranslatedTitleReadsTheSameInBothLanguages(): void
    {
        // An empty translation means "the same as the primary language"
        // (MULTILINGUAL.md), and that is what the trail prints rather than a
        // blank level.
        $html = $this->render(PageBreadcrumb::forPage($this->storePage()));

        $this->assertStringContainsString(
            'data-nl="Testpagina kruimelpad" data-en="Testpagina kruimelpad"',
            $html
        );
    }

    public function testATranslatedTitleIsWhatAnEnglishVisitorReads(): void
    {
        $this->storePage();
        $this->translateTo('Breadcrumb test page');

        $html = $this->render(PageBreadcrumb::forPage($this->reload()));

        $this->assertStringContainsString(
            'data-nl="Testpagina kruimelpad" data-en="Breadcrumb test page"',
            $html,
            'both halves travel, so the language switch reaches the trail'
        );
        $this->assertStringContainsString(
            '>Testpagina kruimelpad</span>',
            $html,
            'and a Dutch-primary site still prints the Dutch words first'
        );
    }

    public function testATranslationIsDroppedWhenItIsEmptiedAgain(): void
    {
        $this->storePage();
        $this->translateTo('Breadcrumb test page');
        $this->translateTo('');

        $this->assertStringContainsString(
            'data-nl="Testpagina kruimelpad" data-en="Testpagina kruimelpad"',
            $this->render(PageBreadcrumb::forPage($this->reload())),
            'an emptied translation falls back, it does not blank the level'
        );
    }

    public function testATranslatedTitleIsEscapedLikeEveryOtherOne(): void
    {
        $this->storePage();
        $this->translateTo('<script>alert(1)</script>');

        $html = $this->render(PageBreadcrumb::forPage($this->reload()));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('data-en="&lt;script&gt;alert(1)&lt;/script&gt;"', $html);
    }

    public function testATranslationIsStoredPerLanguageAndNeverAsAnEmptyString(): void
    {
        $page = $this->storePage();
        $translations = new PageTranslationRepository();

        PageLocalization::save((int) $page['id'], 'en', [PageTranslation::TITLE => '  Breadcrumb test page  ']);
        $this->assertSame('Breadcrumb test page', $translations->find((int) $page['id'], 'en')['title'], 'trimmed, and stored');

        PageLocalization::save((int) $page['id'], 'en', [PageTranslation::TITLE => '   ']);
        $this->assertNull(
            $translations->find((int) $page['id'], 'en'),
            'blank is "not translated" — no row, never an empty string an editor form would read as a translation'
        );
        $this->assertSame(
            'Testpagina kruimelpad',
            $translations->find((int) $page['id'], 'nl')['title'],
            'saving one language leaves the other alone'
        );
    }

    public function testTheLocalizedTitleIsReadInOnePlaceAndReachesTheAutomaticPageTitle(): void
    {
        $page = $this->storePage();
        $this->translateTo('Breadcrumb test page');
        $page = $this->reload();

        $value = PageLocalization::bilingual((int) $page['id'], PageTranslation::TITLE);
        $this->assertSame('Testpagina kruimelpad', $value->raw(LanguageRegistry::DUTCH));
        $this->assertSame('Breadcrumb test page', $value->raw(LanguageRegistry::ENGLISH));

        // The <title> a page without its own SEO title falls back to is built
        // from the same value, so the trail and the tab cannot disagree.
        $this->assertStringStartsWith('Breadcrumb test page', PageContent::seoTitle($page, 'en'));
        $this->assertStringStartsWith('Testpagina kruimelpad', PageContent::seoTitle($page, 'nl'));
    }

    public function testThePageScreensEditTheTitleOneWebsiteLanguageAtATime(): void
    {
        foreach (['admin/page.php', 'admin/page-new.php'] as $screen) {
            $source = self::source($screen);

            $this->assertStringContainsString('name="title"', $source);
            $this->assertStringNotContainsString('name="title_en"', $source, $screen . ' has no fixed English twin any more');
            $this->assertStringContainsString('admin_localized_bar(', $source, $screen . ' uses the localized-fields component');
        }

        $this->assertStringContainsString('admin_localized_input($editLanguage)', self::source('admin/page.php'));

        foreach (['api/admin/update-page.php', 'api/admin/create-page.php'] as $endpoint) {
            $source = self::source($endpoint);

            $this->assertStringContainsString("\$_POST['title']", $source);
            $this->assertStringContainsString('PageTranslation::TITLE => $title', $source);
            $this->assertStringNotContainsString('title_en', $source);
        }

        $this->assertStringContainsString("\$_POST['language_code']", self::source('api/admin/update-page.php'));
        $this->assertStringContainsString('PageLocalization::defaultLanguage()', self::source('api/admin/create-page.php'));
    }

    public function testTheSiteRootHasNoTrail(): void
    {
        $home = PageContent::forContentKey('index');
        $this->assertNotNull($home);

        $this->assertNull(
            PageBreadcrumb::forPage($home),
            '"Home / Home" is not a trail; the homepage is where one starts'
        );
    }

    public function testAPageThatCouldNotBeLoadedHasNoTrail(): void
    {
        $this->assertNull(PageBreadcrumb::forPage(null));
    }

    /* ------------------------------------------------------------------ */
    /* The switch                                                          */
    /* ------------------------------------------------------------------ */

    public function testAPageShowsItsTrailUnlessSomebodySwitchedItOff(): void
    {
        $page = $this->storePage();

        $this->assertTrue(PageBreadcrumb::isEnabled($page), 'on is what every page did before this was a choice');
        $this->assertNotNull(PageBreadcrumb::forPage($page));

        $this->setSwitch(false);

        $this->assertFalse(PageBreadcrumb::isEnabled($this->reload()));
        $this->assertNull(PageBreadcrumb::forPage($this->reload()), 'off means no trail at all, not an empty one');
        $this->assertSame('', trim($this->render(PageBreadcrumb::forPage($this->reload()))));
    }

    public function testARowWithoutTheColumnStillShowsItsTrail(): void
    {
        // What a page read by something that does not select the column looks
        // like — the same answer as a row from before the migration.
        $page = $this->storePage();
        unset($page[PageBreadcrumb::COLUMN]);

        $this->assertTrue(PageBreadcrumb::isEnabled($page));
    }

    public function testTheRepositoryWritesTheChoiceAndTreatsAnAbsentOneAsOn(): void
    {
        $page = $this->storePage();
        $repository = new PageRepository();
        $settings = [
            'slug' => (string) $page['slug'],
            'status' => PageContent::STATUS_PUBLISHED,
        ];

        $repository->update((int) $page['id'], $settings + ['show_breadcrumb' => false]);
        $this->assertFalse(PageBreadcrumb::isEnabled($this->reload()));

        $repository->update((int) $page['id'], $settings + ['show_breadcrumb' => true]);
        $this->assertTrue(PageBreadcrumb::isEnabled($this->reload()));

        // A caller that leaves the key out gets "on" rather than a silent
        // hide. api/admin/update-page.php is the only caller and always sends
        // one, which the next test is what keeps true.
        $repository->update((int) $page['id'], $settings);
        $this->assertTrue(PageBreadcrumb::isEnabled($this->reload()));
    }

    public function testTheScreenAndTheEndpointCarryTheChoiceInBothDirections(): void
    {
        // The same wiring check Tests\Service\PageUrlChangeTest makes on this
        // pair of files, for the same reason it gives: this project has no
        // browser harness for an authenticated admin screen.
        $screen = self::source('admin/page.php');
        $endpoint = self::source('api/admin/update-page.php');

        $this->assertStringContainsString('<input type="hidden" name="show_breadcrumb" value="0">', $screen);
        $this->assertStringContainsString('name="show_breadcrumb" value="1"', $screen);
        $this->assertStringContainsString('PageContent::isSiteRoot($page)', $screen);

        $this->assertStringContainsString("\$_POST['show_breadcrumb'] ?? '1'", $endpoint);
        $this->assertStringContainsString("'show_breadcrumb' => \$showBreadcrumb", $endpoint);
    }

    /* ------------------------------------------------------------------ */
    /* Independent of the Paginakop                                        */
    /* ------------------------------------------------------------------ */

    public function testAPageWithoutAPageHeroStillHasItsTrail(): void
    {
        $page = $this->storePage();
        $this->assertNull(
            (new PageHeroRepository())->findBySlug(self::TEST_KEY),
            'no header row at all — the case that used to leave a page with no trail'
        );

        $this->assertSame(['Home', 'Testpagina kruimelpad'], $this->labels(PageBreadcrumb::forPage($page)));
    }

    public function testAHiddenPageHeroLeavesTheTrailStanding(): void
    {
        $page = $this->storePage();
        $this->storeHeader(false);

        $this->assertSame(
            PageHeroContent::STATE_HIDDEN,
            PageHeroContent::forSlug(self::TEST_KEY)['state'],
            'the header is deliberately hidden'
        );
        $this->assertSame(['Home', 'Testpagina kruimelpad'], $this->labels(PageBreadcrumb::forPage($page)));
    }

    public function testAStoredLegacyBreadcrumbLabelIsNeverRead(): void
    {
        $page = $this->storePage();
        $this->storeHeader(true);

        // The value an editor typed before this phase, left in place on
        // purpose (db/migrations/20260916120000) and deliberately different
        // from the page's title.
        Database::connection()
            ->prepare('UPDATE page_heroes SET breadcrumb_label_nl = :label WHERE page_slug = :slug')
            ->execute(['label' => 'Oude kruimel', 'slug' => self::TEST_KEY]);
        PageHeroContent::clearCache();

        $html = $this->render(PageBreadcrumb::forPage($page));

        $this->assertStringNotContainsString('Oude kruimel', $html);
        $this->assertStringContainsString('Testpagina kruimelpad', $html);
        $this->assertArrayNotHasKey(
            'breadcrumb_label_nl',
            PageHeroContent::forSlug(self::TEST_KEY),
            'the block does not carry the field any more either'
        );
    }

    public function testCreatingAPageHeroLeavesTheLegacyColumnsAlone(): void
    {
        $this->storePage();
        $definition = BlockDefinitions::get('page_hero');
        $this->assertNotNull($definition);
        $definition->create(self::TEST_KEY);

        $row = (new PageHeroRepository())->findBySlug(self::TEST_KEY);
        $this->assertNotNull($row);
        $this->assertSame('', (string) $row['breadcrumb_label_nl'], 'a new row writes nothing into it');

        Database::connection()
            ->prepare('UPDATE page_heroes SET breadcrumb_label_nl = :label WHERE page_slug = :slug')
            ->execute(['label' => 'Bewaard', 'slug' => self::TEST_KEY]);

        (new PageHeroRepository())->upsert(self::TEST_KEY, PageHeroContent::startingValues() + ['is_active' => true]);

        $row = (new PageHeroRepository())->findBySlug(self::TEST_KEY);
        $this->assertNotNull($row);
        $this->assertSame(
            'Bewaard',
            (string) $row['breadcrumb_label_nl'],
            'saving the header again must not blank a legacy value; removing the column is a separate decision'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Dynamic routes                                                      */
    /* ------------------------------------------------------------------ */

    public function testARouteCanAddItsOwnLevelsAboveTheCurrentPage(): void
    {
        $html = $this->render(
            BreadcrumbTrail::home()
                ->to(BreadcrumbItem::link('Shop', 'Shop', '/shop.php'))
                ->to(BreadcrumbItem::current('Een product', 'A product'))
        );

        $this->assertSame(3, substr_count($html, '<li class="breadcrumb__item">'));
        $this->assertStringContainsString('<a href="/shop.php" data-nl="Shop" data-en="Shop">Shop</a>', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'), 'exactly one level is the current one');
    }

    public function testAnApplicationRouteIsNamedByTheRegistryThatOwnsIt(): void
    {
        // The two legal routes are Core's own and always registered; the
        // module routes come and go with their module, which is why a key the
        // registry does not know adds no level at all.
        $labels = $this->labels(BreadcrumbTrail::home()->toRoute('cookiebeleid'));

        $this->assertSame(['Home', 'Cookiebeleid'], $labels);
        $this->assertSame(
            ['Home'],
            $this->labels(BreadcrumbTrail::home()->toRoute('een-route-die-niet-bestaat')),
            'a trail never points at an address that is not registered'
        );
    }

    public function testAPageLevelFollowsThePageAndDropsItsLinkWhenItIsNotPublished(): void
    {
        $this->storePage();

        $trail = BreadcrumbTrail::home()->toPage(self::TEST_KEY)->to(BreadcrumbItem::current('Detail', 'Detail'));
        $this->assertSame(['Home', 'Testpagina kruimelpad', 'Detail'], $this->labels($trail));
        $this->assertStringContainsString('<a href="/' . self::TEST_SLUG . '"', $this->render($trail));

        Database::connection()
            ->prepare('UPDATE pages SET status = :status WHERE content_key = :key')
            ->execute(['status' => 'draft', 'key' => self::TEST_KEY]);
        PageContent::clearCache();

        $html = $this->render(BreadcrumbTrail::home()->toPage(self::TEST_KEY)->to(BreadcrumbItem::current('Detail', 'Detail')));
        $this->assertStringContainsString('>Testpagina kruimelpad</span>', $html, 'the level keeps its name');
        $this->assertStringNotContainsString(self::TEST_SLUG, $html, 'but not a link to a page a visitor cannot see');
    }

    public function testAPageLevelThatDoesNotExistAddsNothing(): void
    {
        $this->assertSame(
            ['Home'],
            $this->labels(BreadcrumbTrail::home()->toPage('__geen_enkele_pagina__'))
        );
    }

    public function testALevelThatExistsWithoutAPageFallsBackToItsRoute(): void
    {
        // An installation whose storefront is the Shop module's own overview
        // has no `pages` row for it, and the Shop's routes still name it. The
        // level must not quietly disappear there.
        $this->assertSame(
            ['Home', 'Cookiebeleid'],
            $this->labels(BreadcrumbTrail::home()->toPage('__geen_enkele_pagina__', 'cookiebeleid'))
        );
    }

    /* ------------------------------------------------------------------ */

    private static function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    /** The trail of one ordinary stored page — what most of the markup is about. */
    private function pageTrail(): ?BreadcrumbTrail
    {
        return PageBreadcrumb::forPage($this->storePage());
    }

    private function render(?BreadcrumbTrail $trail, bool $narrow = false): string
    {
        ob_start();
        render_breadcrumb($trail, $narrow);

        return (string) ob_get_clean();
    }

    /**
     * The visible label of every level, primary language first.
     *
     * @return list<string>
     */
    private function labels(?BreadcrumbTrail $trail): array
    {
        if ($trail === null) {
            return [];
        }

        return array_map(
            static fn (BreadcrumbItem $item): string => \App\Service\Language\SiteText::visible($item->labelNl, $item->labelEn),
            $trail->items()
        );
    }

    /** @return array<string, mixed> */
    private function storePage(): array
    {
        $id = (new PageRepository())->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_SLUG,
            'status' => PageContent::STATUS_PUBLISHED,
        ]);
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => 'Testpagina kruimelpad']);
        PageContent::clearCache();

        return $this->reload();
    }

    /** @return array<string, mixed> */
    private function reload(): array
    {
        PageContent::clearCache();
        $page = PageContent::forContentKey(self::TEST_KEY);
        $this->assertNotNull($page);

        return $page;
    }

    private function translateTo(string $titleEn): void
    {
        PageLocalization::save((int) $this->reload()['id'], 'en', [PageTranslation::TITLE => $titleEn]);
        PageContent::clearCache();
    }

    private function renameTo(string $title): void
    {
        PageLocalization::save((int) $this->reload()['id'], 'nl', [PageTranslation::TITLE => $title]);
        PageContent::clearCache();
    }

    private function setSwitch(bool $on): void
    {
        Database::connection()
            ->prepare('UPDATE pages SET show_breadcrumb = :on WHERE content_key = :key')
            ->execute(['on' => $on ? 1 : 0, 'key' => self::TEST_KEY]);
        PageContent::clearCache();
    }

    private function storeHeader(bool $active): void
    {
        (new PageHeroRepository())->upsert(
            self::TEST_KEY,
            PageHeroContent::startingValues() + ['is_active' => $active]
        );
        PageHeroContent::clearCache();
    }
}

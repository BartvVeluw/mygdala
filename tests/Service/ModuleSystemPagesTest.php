<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\ModuleSystemPages;
use App\Service\PageContent;
use App\Service\PageService;
use App\Service\PortfolioUrls;
use App\Service\ShopOverview;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;

/**
 * The Shop's and the Portfolio's system pages (Shop Product & Ordering 2.0,
 * MODULES.md "Systeempagina's van modules"), against the real database:
 *
 *   - every registered module names its page, on or off, and Core says whose
 *     page it is and whether that module is on;
 *   - the words `shop` and `portfolio` stay reserved with the module on and
 *     off: no ordinary page can claim them;
 *   - a module's page cannot be deleted;
 *   - a placeholder page (made by the migration, no visible block) is not a
 *     page of its own on the website — the module's own overview is — until
 *     it gets a block; a hidden block does not count;
 *   - Pagina's shows the page with its module's state, and no Verwijderen,
 *     with the module on and off.
 *
 * The migration itself — fresh install, upgrade, conflict, replay — is
 * Tests\Install\ModuleSystemPagesMigrationTest.
 */
final class ModuleSystemPagesTest extends TestCase
{
    /** @var array<int, array{module_default: int, status: string}> page id => what to put back */
    private array $restore = [];

    /** @var array{id: int, content_key: string, slug: string, english: array<string, mixed>|null}|null */
    private ?array $hidden = null;

    private ?string $overviewBefore = null;

    private bool $overviewTouched = false;

    /** @var list<int> */
    private array $sectionIds = [];

    protected function tearDown(): void
    {
        $db = Database::connection();
        foreach ($this->sectionIds as $id) {
            $db->prepare('DELETE FROM page_sections WHERE id = :id')->execute(['id' => $id]);
        }
        if ($this->hidden !== null) {
            $db->prepare('UPDATE pages SET content_key = :content_key, slug = :slug WHERE id = :id')
                ->execute(['content_key' => $this->hidden['content_key'], 'slug' => $this->hidden['slug'], 'id' => $this->hidden['id']]);
            if ($this->hidden['english'] === null) {
                $db->prepare("DELETE FROM page_translations WHERE page_id = :id AND language_code = 'en'")->execute(['id' => $this->hidden['id']]);
            } else {
                $db->prepare("UPDATE page_translations SET slug = :slug WHERE page_id = :id AND language_code = 'en'")
                    ->execute(['slug' => $this->hidden['english']['slug'], 'id' => $this->hidden['id']]);
            }
            $this->hidden = null;
        }
        foreach ($this->restore as $id => $values) {
            $db->prepare('UPDATE pages SET module_default = :module_default, status = :status WHERE id = :id')
                ->execute(['module_default' => $values['module_default'], 'status' => $values['status'], 'id' => $id]);
        }
        if ($this->overviewTouched) {
            if ($this->overviewBefore === null) {
                $db->prepare('DELETE FROM site_settings WHERE setting_key = ?')->execute([ShopOverview::SETTING_KEY]);
            } else {
                $db->prepare('UPDATE site_settings SET setting_value = ? WHERE setting_key = ?')->execute([$this->overviewBefore, ShopOverview::SETTING_KEY]);
            }
            SiteSettings::clearCache();
            ShopOverview::clearCache();
        }
        ModuleRegistry::overrideForTests(null);
        PageContent::clearCache();
    }

    public function testEveryModuleNamesItsPageOnOrOff(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'portfolio' => false]);

        $all = ModuleSystemPages::all();
        self::assertSame('/shop.php', $all['shop']['route_path']);
        self::assertSame('shop', $all['shop']['module']);
        self::assertSame('/portfolio', $all['portfolio']['route_path']);
        self::assertSame('portfolio', $all['portfolio']['module']);

        self::assertFalse(ModuleSystemPages::forPage(['content_key' => 'shop'])['enabled']);
        self::assertNull(ModuleSystemPages::forPage(['content_key' => 'over-mij']));

        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true]);
        self::assertTrue(ModuleSystemPages::forPage(['content_key' => 'portfolio'])['enabled']);
    }

    public function testTheWordsStayReservedWithTheModuleOnAndOff(): void
    {
        foreach ([false, true] as $on) {
            ModuleRegistry::overrideForTests(['shop' => $on, 'portfolio' => $on]);
            foreach (['shop', 'portfolio'] as $word) {
                self::assertNotNull(PageService::validateSlug(new PageRepository(), $word, null, 'nl'), $word . ' is refused with the module ' . ($on ? 'on' : 'off'));
                self::assertNotNull(PageService::validateSlug(new PageRepository(), $word, null, 'en'), $word . ' in English too');
            }
        }
    }

    public function testAModulePageCannotBeDeleted(): void
    {
        $page = $this->systemPage('portfolio');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dit is de systeempagina van Portfolio en kan niet worden verwijderd.');
        PageService::delete($page);
    }

    public function testAPlaceholderIsNotAPageOfItsOwnUntilItGetsAVisibleBlock(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'personalization' => true]);
        $page = $this->placeholder('portfolio');

        self::assertTrue(ModuleSystemPages::isPlaceholder($page));
        self::assertNull(PortfolioUrls::overviewPage(), 'the module\'s own overview stays');
        self::assertFalse(PageContent::isServedByAnEnabledModule($page), 'nothing lists or links the empty page');

        $hidden = $this->block($page, false);
        PageContent::clearCache();
        self::assertTrue(ModuleSystemPages::isPlaceholder($page), 'a hidden block does not count');

        $this->block($page, true);
        PageContent::clearCache();
        self::assertFalse(ModuleSystemPages::isPlaceholder($page), 'a visible block makes it the page');
        self::assertSame((int) $page['id'], (int) PortfolioUrls::overviewPage()['id']);
        self::assertTrue(PageContent::isServedByAnEnabledModule($page));
        self::assertGreaterThan(0, $hidden);
    }

    /**
     * "Zet de pagina op concept", which the refused delete advises, takes the
     * page off the website: a concept is the editor's choice, never the
     * module's overview standing in for it.
     */
    public function testAModulePageSetToConceptIsOffTheWebsite(): void
    {
        ModuleRegistry::overrideForTests(['shop' => true, 'portfolio' => true, 'personalization' => true]);
        $portfolio = $this->placeholder('portfolio');
        // The storefront page as it is, placeholder or not: a concept is
        // off the website either way.
        $shop = $this->systemPage('shop');
        $this->restore[(int) $shop['id']] = ['module_default' => (int) $shop['module_default'], 'status' => (string) $shop['status']];
        foreach ([$portfolio, $shop] as $page) {
            Database::connection()->prepare("UPDATE pages SET status = 'draft' WHERE id = :id")->execute(['id' => $page['id']]);
        }
        PageContent::clearCache();

        $draft = (array) (new PageRepository())->findById((int) $portfolio['id']);
        self::assertFalse(ModuleSystemPages::isPlaceholder($draft));
        self::assertSame((int) $portfolio['id'], (int) PortfolioUrls::overviewPage()['id'], 'the concept page, which portfolio.php answers with 404');
        self::assertSame((int) $shop['id'], (int) ShopOverview::storefrontPage()['id']);

        // Even where /shop.php would show the automatic listing.
        $this->chooseOverview('builtin');
        $server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => 'true']);
        if ($server === null || !$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        try {
            self::assertSame(404, $server->request('GET', '/shop.php')['status']);
            Database::connection()->prepare("UPDATE pages SET status = 'published' WHERE id = :id")->execute(['id' => $shop['id']]);
            self::assertSame(200, $server->request('GET', '/shop.php')['status'], 'published again: the page, or while it is empty the automatic listing');
        } finally {
            $server->stop();
        }
    }

    public function testAPageTheInstallationAlreadyHadIsNeverAPlaceholder(): void
    {
        $page = $this->systemPage('shop');
        $this->restore[(int) $page['id']] = ['module_default' => (int) $page['module_default'], 'status' => (string) $page['status']];
        Database::connection()->prepare('UPDATE pages SET module_default = 0 WHERE id = :id')->execute(['id' => $page['id']]);
        PageContent::clearCache();

        self::assertFalse(ModuleSystemPages::isPlaceholder((array) (new PageRepository())->findById((int) $page['id'])), 'whatever it holds');
        self::assertSame((int) $page['id'], (int) ShopOverview::storefrontPage()['id'], 'and it is the storefront page');
    }

    /**
     * A module page that is missing because another page holds its word, in
     * the neutral slug or in a translation: named, never renamed.
     */
    public function testAMissingModulePageWhoseWordIsTakenIsReported(): void
    {
        $page = $this->systemPage('portfolio');
        $db = Database::connection();
        $translation = $db->prepare("SELECT language_code, slug FROM page_translations WHERE page_id = :id AND language_code = 'en'");
        $translation->execute(['id' => $page['id']]);
        $englishBefore = $translation->fetch() ?: null;

        $this->hidden = ['id' => (int) $page['id'], 'content_key' => (string) $page['content_key'], 'slug' => (string) $page['slug'], 'english' => $englishBefore];
        $db->prepare("UPDATE pages SET content_key = 'zz-held-portfolio' WHERE id = :id")->execute(['id' => $page['id']]);
        PageContent::clearCache();

        self::assertSame(
            [['content_key' => 'portfolio', 'module_label' => 'Portfolio', 'page_id' => (int) $page['id']]],
            array_values(array_filter(ModuleSystemPages::conflicts(), static fn (array $c): bool => $c['content_key'] === 'portfolio')),
            'held in the neutral slug'
        );

        $db->prepare("UPDATE pages SET slug = 'zz-held-portfolio' WHERE id = :id")->execute(['id' => $page['id']]);
        $db->prepare(
            "INSERT INTO page_translations (page_id, language_code, slug, created_at, updated_at) VALUES (:id, 'en', 'portfolio', NOW(), NOW())
             ON DUPLICATE KEY UPDATE slug = 'portfolio'"
        )->execute(['id' => $page['id']]);

        self::assertSame(
            [(int) $page['id']],
            array_column(array_filter(ModuleSystemPages::conflicts(), static fn (array $c): bool => $c['content_key'] === 'portfolio'), 'page_id'),
            'held in a translation'
        );
    }

    public function testPaginasShowsTheModulePageWithItsStateAndNoDelete(): void
    {
        $shopPage = $this->systemPage('shop');
        $portfolioPage = $this->systemPage('portfolio');
        $accounts = new AdminTestSession();

        foreach ([false, true] as $on) {
            $server = BuiltInServer::start(['MODULE_SHOP_ENABLED' => $on ? 'true' : 'false', 'MODULE_PORTFOLIO_ENABLED' => $on ? 'true' : 'false']);
            if ($server === null || !$server->answers()) {
                $this->markTestSkipped("could not start PHP's built-in web server for this test");
            }

            try {
                [$session] = $accounts->signIn(['pages.manage']);
                $body = $server->request('GET', '/admin/pages.php', $session)['body'];

                foreach ([[$shopPage, 'Shop'], [$portfolioPage, 'Portfolio']] as [$page, $label]) {
                    $row = $this->row($body, (int) $page['id']);
                    self::assertStringContainsString('Systeempagina ' . $label, $row, $label);
                    self::assertSame($on ? 0 : 1, substr_count($row, 'Module staat uit'), $label . ' with the module ' . ($on ? 'on' : 'off'));
                    self::assertStringNotContainsString('/api/admin/delete-page.php', $row, $label . ' has no Verwijderen');
                }

                $editor = $server->request('GET', '/admin/page.php?id=' . (int) $portfolioPage['id'], $session)['body'];
                self::assertStringContainsString('Dit is de systeempagina van Portfolio', $editor);
                self::assertStringNotContainsString('/api/admin/delete-page.php', $editor);
            } finally {
                $server->stop();
                $accounts->forget();
            }
        }
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function systemPage(string $contentKey): array
    {
        $page = (new PageRepository())->findByContentKey($contentKey);
        if ($page === null) {
            self::markTestSkipped('this database has no ' . $contentKey . ' page (it runs after 20260928150000)');
        }

        return $page;
    }

    /**
     * The module's page as a placeholder: module_default on and, for this
     * test, no visible block of its own (a database copied from a real site
     * may have given it some; they are left alone and the test skips).
     *
     * @return array<string, mixed>
     */
    private function placeholder(string $contentKey): array
    {
        $page = $this->systemPage($contentKey);
        $this->restore[(int) $page['id']] = ['module_default' => (int) $page['module_default'], 'status' => (string) $page['status']];
        Database::connection()->prepare("UPDATE pages SET module_default = 1, status = 'published' WHERE id = :id")->execute(['id' => $page['id']]);
        PageContent::clearCache();

        $page = (array) (new PageRepository())->findById((int) $page['id']);
        if (!ModuleSystemPages::isPlaceholder($page)) {
            self::markTestSkipped('the ' . $contentKey . ' page of this database has blocks of its own');
        }

        return $page;
    }

    private function chooseOverview(string $value): void
    {
        $db = Database::connection();
        if (!$this->overviewTouched) {
            $stmt = $db->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ?');
            $stmt->execute([ShopOverview::SETTING_KEY]);
            $stored = $stmt->fetchColumn();
            $this->overviewBefore = $stored === false ? null : (string) $stored;
            $this->overviewTouched = true;
        }

        $db->prepare(
            'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute([ShopOverview::SETTING_KEY, $value]);
        SiteSettings::clearCache();
        ShopOverview::clearCache();
    }

    /** A spacer block on the page, visible or hidden. */
    private function block(array $page, bool $visible): int
    {
        $db = Database::connection();
        $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM page_sections WHERE page_id = ' . (int) $page['id'])->fetchColumn();
        $db->prepare(
            'INSERT INTO page_sections (page_id, page_slug, section_type, section_id, sort_order, is_active, created_at, updated_at)
             VALUES (:page_id, :page_slug, :type, :section_id, :sort_order, :active, NOW(), NOW())'
        )->execute([
            'page_id' => $page['id'],
            'page_slug' => $page['content_key'],
            'type' => 'zz_test_block',
            'section_id' => random_int(900000000, 999999999),
            'sort_order' => $sortOrder,
            'active' => $visible ? 1 : 0,
        ]);
        $id = (int) $db->lastInsertId();
        $this->sectionIds[] = $id;

        return $id;
    }

    private function row(string $html, int $pageId): string
    {
        $start = strpos($html, 'id="page-row-' . $pageId . '"');
        self::assertNotFalse($start, 'page ' . $pageId . ' is listed');
        $end = strpos($html, '</tr>', (int) $start);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}

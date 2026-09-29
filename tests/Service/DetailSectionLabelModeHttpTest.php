<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Repository\DetailSectionRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\BlockTranslationRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\Blocks\BlockLocalization;
use App\Service\DetailSectionContent;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\Routing\RequestLanguage;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdminTestSession;
use Tests\Support\BuiltInServer;
use Tests\Support\PageFixture;

/**
 * A Detailsectie's number or label (App\Service\Blocks\LabelMode, v0.1.13),
 * over real HTTP through admin/detail-section.php's endpoint, and on the page:
 *
 *   - a section made the old way, and a new one, are numbered 01, 02 in the
 *     page's order: only Detailsecties count, another block in between does
 *     not, a hidden one is no place;
 *   - none prints nothing; plain prints 1; own words are per language;
 *   - moving a section renumbers it, nothing is stored;
 *   - the anchor navigation keeps its own label (navigation label, else the
 *     title), whatever the section shows above its title;
 *   - an unknown mode is refused.
 */
final class DetailSectionLabelModeHttpTest extends TestCase
{
    private const KEY = 'zz-detail-label-http';

    private static ?BuiltInServer $server = null;

    private AdminTestSession $accounts;

    private int $pageId = 0;

    public static function setUpBeforeClass(): void
    {
        self::$server = BuiltInServer::start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;
    }

    protected function setUp(): void
    {
        if (self::$server === null || !self::$server->answers()) {
            $this->markTestSkipped("could not start PHP's built-in web server for this test");
        }
        if (BlockLocalization::defaultLanguage() !== 'nl' || !SiteLanguages::isActive('en')) {
            $this->markTestSkipped('this test expects the test database to publish nl (default) and en');
        }

        BlockDefinitions::reset();
        $this->accounts = new AdminTestSession();
        $this->removePage();
        $this->pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'ZZ Detail label');
    }

    protected function tearDown(): void
    {
        $this->removePage();
        RequestLanguage::reset();
        $this->accounts->forget();
        BlockDefinitions::reset();
    }

    public function testSectionsAreNumberedByTheirPlaceAmongTheDetailsectiesOfThePage(): void
    {
        $first = $this->section('Hout');
        $this->other();
        $second = $this->section('Acryl');
        $third = $this->section('Glas');

        self::assertSame('padded', $this->row($first)['label_mode'], 'a new section starts numbered, like every section before');
        self::assertSame(['Hout' => '01', 'Acryl' => '02', 'Glas' => '03'], $this->labels(), 'the rich text in between is no place');

        // Hidden: no place, the next moves up.
        (new PageSectionRepository())->setActive($second, false);
        self::assertSame(['Hout' => '01', 'Glas' => '02'], $this->labels());
        (new PageSectionRepository())->setActive($second, true);

        // Moved: renumbered, nothing stored.
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);
        $moved = self::$server->request('POST', '/api/admin/reorder-page-sections.php', $session, [
            'csrf_token' => $csrf, 'page_id' => (string) $this->pageId, 'section_ids' => $third . ',' . $first . ',' . $second,
        ]);
        self::assertSame(200, $moved['status']);
        self::assertSame(['Glas' => '01', 'Hout' => '02', 'Acryl' => '03'], $this->labels());
        self::assertArrayNotHasKey('label', $this->stored($first, 'nl'));
    }

    public function testEveryModeAndOwnWordsPerLanguage(): void
    {
        $first = $this->section('Hout');
        $second = $this->section('Acryl');
        $third = $this->section('Glas');
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        $this->save($session, $csrf, $first, 'Hout', ['label_mode' => 'none']);
        $this->save($session, $csrf, $second, 'Acryl', ['label_mode' => 'plain']);
        $this->save($session, $csrf, $third, 'Glas', ['label_mode' => 'custom', 'label' => 'Stap C']);
        $this->save($session, $csrf, $third, 'Glass', ['label_mode' => 'custom', 'label' => 'Step C'], 'en');

        self::assertSame(['Hout' => '', 'Acryl' => '2', 'Glas' => 'Stap C'], $this->labels());
        self::assertSame(['none', 'plain', 'custom'], [$this->row($first)['label_mode'], $this->row($second)['label_mode'], $this->row($third)['label_mode']]);
        self::assertSame('Step C', $this->stored($third, 'en')['label']);

        RequestLanguage::set('en', true);
        BlockLocalization::clearCache();
        self::assertSame('Step C', $this->labels()['Glass']);
        RequestLanguage::reset();

        $html = $this->renderPage();
        self::assertSame(2, substr_count($html, 'class="service-row__index"'), 'none keeps no room');
        self::assertStringContainsString('<span class="service-row__index">2</span>', $html);
        self::assertStringContainsString('<span class="service-row__index">Stap C</span>', $html);

        // Back to numbering: the words stay stored for later.
        $this->save($session, $csrf, $third, 'Glas', ['label_mode' => 'padded', 'label' => 'Stap C']);
        self::assertSame('03', $this->labels()['Glas']);
        self::assertSame('Stap C', $this->stored($third, 'nl')['label']);

        // An unknown mode: refused, nothing written.
        $this->save($session, $csrf, $third, 'Glas', ['label_mode' => 'roman']);
        self::assertSame('padded', $this->row($third)['label_mode']);
        self::assertContains('Kies een labelweergave uit de lijst.', (array) $this->accounts->read($session, 'admin_detail_section_errors'));
    }

    public function testTheAnchorNavigationKeepsItsOwnLabel(): void
    {
        $first = $this->section('Hout graveren');
        $second = $this->section('Acryl snijden');
        [$session, $csrf] = $this->accounts->signIn(['pages.manage']);

        $this->save($session, $csrf, $first, 'Hout graveren', ['anchor' => 'hout', 'nav_label' => 'Hout', 'label_mode' => 'custom', 'label' => 'Stap 1']);
        $this->save($session, $csrf, $second, 'Acryl snijden', ['anchor' => 'acryl', 'label_mode' => 'none']);

        self::assertSame(
            [['anchor' => 'hout', 'label' => 'Hout'], ['anchor' => 'acryl', 'label' => 'Acryl snijden']],
            DetailSectionContent::navItemsForPage(self::KEY),
            'navigation label, else the title: never the number or the own words'
        );
    }

    public function testTheEditorOffersTheFourModesWithOwnWordsOnlyForOwnText(): void
    {
        $section = $this->section('Hout');
        [$session] = $this->accounts->signIn(['pages.manage']);
        $screen = self::$server->request('GET', '/admin/detail-section.php?section=' . urlencode(self::KEY . ':' . $this->key($section)), $session)['body'];

        preg_match_all('/<select[^>]*name="label_mode"[^>]*>(.*?)<\/select>/s', $screen, $select);
        preg_match_all('/<option value="([a-z]+)"( selected)?>/', $select[1][0] ?? '', $options);
        self::assertSame(['none', 'padded', 'plain', 'custom'], $options[1], 'no icon for a Detailsectie');
        self::assertSame(' selected', $options[2][1], 'numbered 01, 02 by default');
        self::assertMatchesRegularExpression('/<div data-label-mode-when="custom" hidden>\s*<div class="admin-field"><div class="admin-field__label"><label for="detail-label">/', $screen);
        self::assertStringContainsString('/admin/assets/label-mode.js', $screen);
    }

    // ---------------------------------------------------------- helpers

    /** @param array<string, string> $fields */
    private function save(string $session, string $csrf, int $pageSectionId, string $title, array $fields, string $language = 'nl'): void
    {
        $response = self::$server->request('POST', '/api/admin/update-detail-section.php', $session, $fields + [
            'csrf_token' => $csrf,
            'section' => self::KEY . ':' . $this->key($pageSectionId),
            'language_code' => $language,
            'title' => $title,
            'is_active' => '1',
            'image_position' => 'image_right',
        ]);
        self::assertSame(302, $response['status']);
        DetailSectionContent::clearCache();
    }

    /** @return array<string, string> title => printed label, in the page's order */
    private function labels(): array
    {
        DetailSectionContent::clearCache();
        $labels = [];
        $repository = new DetailSectionRepository();
        foreach ($repository->findActiveForPageInBlockOrder(self::KEY) as $row) {
            $content = DetailSectionContent::forSection(self::KEY, (string) $repository->findById((int) $row['id'])['section_key']);
            $labels[$content['title']] = DetailSectionContent::positionMarkers(self::KEY, (int) $content['id'], $content['label_mode'], $content['label'])['index_label'];
        }

        return $labels;
    }

    private function renderPage(): string
    {
        DetailSectionContent::clearCache();
        ob_start();
        SectionRegistry::renderPage(self::KEY);

        return (string) ob_get_clean();
    }

    private function section(string $title): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('detail_section', self::KEY);
        BlockLocalization::save('detail_sections', (int) $sectionId, 'nl', ['title' => $title]);

        return (new PageSectionRepository())->create($this->pageId, self::KEY, 'detail_section', $sectionKey, $sectionId);
    }

    private function other(): void
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', self::KEY);
        (new PageSectionRepository())->create($this->pageId, self::KEY, 'rich_text', $sectionKey, $sectionId);
    }

    private function key(int $pageSectionId): string
    {
        return (string) (new PageSectionRepository())->findById($pageSectionId)['section_key'];
    }

    /** @return array<string, mixed> */
    private function row(int $pageSectionId): array
    {
        return (array) (new DetailSectionRepository())->findBySlugAndKey(self::KEY, $this->key($pageSectionId));
    }

    /** @return array<string, string> */
    private function stored(int $pageSectionId, string $language): array
    {
        $id = (int) $this->row($pageSectionId)['id'];

        return (new BlockTranslationRepository())->findForOwners(['detail_sections' => [$id]])['detail_sections'][$id][$language] ?? [];
    }

    private function removePage(): void
    {
        $pages = new PageRepository();
        $page = $pages->findByContentKey(self::KEY);
        if ($page === null) {
            return;
        }

        $sections = new PageSectionRepository();
        foreach ($sections->findForPage((int) $page['id']) as $row) {
            SectionRegistry::delete($row, $sections);
        }
        $pages->delete((int) $page['id']);
        PageContent::clearCache();
        BlockLocalization::clearCache();
        DetailSectionContent::clearCache();
    }
}

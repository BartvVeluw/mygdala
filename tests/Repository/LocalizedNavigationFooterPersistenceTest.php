<?php

namespace Tests\Repository;

use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Service\FooterService;
use App\Service\Language\LocalizedValue;
use App\Service\Language\SiteText;
use App\Service\NavigationService;
use PHPUnit\Framework\TestCase;

/**
 * SAVING ONE LANGUAGE MUST NEVER OVERWRITE THE OTHER, for the navigation and
 * the footer.
 *
 * This is the preservation matrix behind the bug these tests were written
 * for. An editor edits one language at a time (MULTILINGUAL.md); the other
 * language's control is still on the form, still carrying its RAW stored
 * value, and still submitted. So a save is always a two-column write in
 * which exactly one column changes, and the shape a regression takes is
 * always the same: the language saved last ends up in both columns, and a
 * visitor reads the same words whichever half of the switch they press.
 *
 * The defect that produced it lived in the editor's markup and stylesheet
 * rather than here (Tests\Service\MultilingualBoundaryTest guards that side),
 * but the matrix is the property the whole feature is judged on, so it is
 * asserted against real rows rather than inferred.
 *
 * Integration tests against the real database, same rationale and the same
 * cleanup discipline as NavigationRepositoryTest and FooterRepositoryTest:
 * every row is this test's own, carries a zz- prefix, and is removed by id
 * in tearDown().
 */
class LocalizedNavigationFooterPersistenceTest extends TestCase
{
    private NavigationRepository $navigation;
    private FooterRepository $footer;

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $columnIds = [];

    protected function setUp(): void
    {
        $this->navigation = new NavigationRepository();
        $this->footer = new FooterRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->navIds as $id) {
            $this->navigation->delete($id);
        }
        // footer_links.column_id CASCADEs, so the column is enough.
        foreach ($this->columnIds as $id) {
            $this->footer->deleteColumn($id);
        }
    }

    // ------------------------------------------------------------ navigation

    /**
     * The mandatory navigation matrix: start bilingual, save Dutch, save
     * English, and check after each save that the language nobody touched is
     * byte-for-byte what it was.
     */
    public function testSavingOneNavigationLabelLeavesTheOtherLanguageAlone(): void
    {
        $id = $this->makeNavItem('zz Navigatie NL', 'zz Navigation EN');

        $this->updateNavLabels($id, 'zz Nieuw NL', 'zz Navigation EN');

        $row = $this->navigation->findById($id);
        self::assertSame('zz Nieuw NL', $row['label_nl']);
        self::assertSame('zz Navigation EN', $row['label_en'], 'saving Dutch may not touch English');

        $this->updateNavLabels($id, 'zz Nieuw NL', 'zz New EN');

        $row = $this->navigation->findById($id);
        self::assertSame('zz Nieuw NL', $row['label_nl'], 'saving English may not touch Dutch');
        self::assertSame('zz New EN', $row['label_en']);
    }

    /** The same matrix the other way round: the report was order-independent. */
    public function testSavingTheNavigationLabelInEnglishFirstIsJustAsLossless(): void
    {
        $id = $this->makeNavItem('zz Navigatie NL', 'zz Navigation EN');

        $this->updateNavLabels($id, 'zz Navigatie NL', 'zz New EN');
        $row = $this->navigation->findById($id);
        self::assertSame('zz Navigatie NL', $row['label_nl']);
        self::assertSame('zz New EN', $row['label_en']);

        $this->updateNavLabels($id, 'zz Nieuw NL', 'zz New EN');
        $row = $this->navigation->findById($id);
        self::assertSame('zz Nieuw NL', $row['label_nl']);
        self::assertSame('zz New EN', $row['label_en']);
    }

    /**
     * The symptom itself, named: whichever language was saved last must not
     * be what BOTH halves of the public switch show.
     */
    public function testTheLastSavedNavigationLanguageDoesNotWinForBothLanguages(): void
    {
        $id = $this->makeNavItem('zz Navigatie NL', 'zz Navigation EN');

        $this->updateNavLabels($id, 'zz Nieuw NL', 'zz Navigation EN');
        $this->updateNavLabels($id, 'zz Nieuw NL', 'zz New EN');

        $item = $this->publicNavItem($id);
        $label = LocalizedValue::ofDutchEnglish($item['label_nl'], $item['label_en']);

        self::assertSame('zz Nieuw NL', $label->in('nl'));
        self::assertSame('zz New EN', $label->in('en'));
        self::assertNotSame(
            $item['label_nl'],
            $item['label_en'],
            'a visitor who switches language must not read the same words twice'
        );
    }

    /** What the shared header actually prints: one attribute per language. */
    public function testThePublicNavigationCarriesBothLanguagesSeparately(): void
    {
        $id = $this->makeNavItem('zz Navigatie NL', 'zz Navigation EN');
        $item = $this->publicNavItem($id);
        $attrs = SiteText::attrs((string) $item['label_nl'], (string) $item['label_en']);

        self::assertStringContainsString('data-nl="zz Navigatie NL"', $attrs);
        self::assertStringContainsString('data-en="zz Navigation EN"', $attrs);
    }

    /**
     * The read side that must NOT change: an untranslated label still shows a
     * visitor the primary language's words rather than a blank. Fallback is a
     * rendering rule; the columns above are what it reads from.
     */
    public function testAnUntranslatedNavigationLabelStillFallsBackForAVisitor(): void
    {
        $id = $this->makeNavItem('zz Alleen Nederlands', '');
        $item = $this->publicNavItem($id);

        self::assertSame('', $item['label_en'], 'the stored translation stays empty');
        self::assertStringContainsString(
            'data-en="zz Alleen Nederlands"',
            SiteText::attrs((string) $item['label_nl'], (string) $item['label_en'])
        );
    }

    // ---------------------------------------------------------------- footer

    public function testSavingOneFooterColumnTitleLeavesTheOtherLanguageAlone(): void
    {
        $id = $this->makeColumn('zz Kolom NL', 'zz Column EN');

        $this->updateColumnTitles($id, 'zz Nieuwe kolom', 'zz Column EN');
        $row = $this->footer->findColumnById($id);
        self::assertSame('zz Nieuwe kolom', $row['title_nl']);
        self::assertSame('zz Column EN', $row['title_en'], 'saving Dutch may not touch English');

        $this->updateColumnTitles($id, 'zz Nieuwe kolom', 'zz New column');
        $row = $this->footer->findColumnById($id);
        self::assertSame('zz Nieuwe kolom', $row['title_nl'], 'saving English may not touch Dutch');
        self::assertSame('zz New column', $row['title_en']);
    }

    public function testSavingOneFooterLinkLabelLeavesTheOtherLanguageAlone(): void
    {
        $columnId = $this->makeColumn('zz Kolom NL', 'zz Column EN');
        $linkId = $this->makeLink($columnId, 'zz Link NL', 'zz Link EN');

        $this->updateLinkLabels($linkId, 'zz Nieuwe link', 'zz Link EN');
        $row = $this->footer->findLinkById($linkId);
        self::assertSame('zz Nieuwe link', $row['label_nl']);
        self::assertSame('zz Link EN', $row['label_en'], 'saving Dutch may not touch English');

        $this->updateLinkLabels($linkId, 'zz Nieuwe link', 'zz New link');
        $row = $this->footer->findLinkById($linkId);
        self::assertSame('zz Nieuwe link', $row['label_nl'], 'saving English may not touch Dutch');
        self::assertSame('zz New link', $row['label_en']);
    }

    public function testTheLastSavedFooterLanguageDoesNotWinForBothLanguages(): void
    {
        $columnId = $this->makeColumn('zz Kolom NL', 'zz Column EN');
        $this->makeLink($columnId, 'zz Link NL', 'zz Link EN');

        $this->updateColumnTitles($columnId, 'zz Nieuwe kolom', 'zz Column EN');
        $this->updateColumnTitles($columnId, 'zz Nieuwe kolom', 'zz New column');

        $column = $this->publicFooterColumn($columnId);

        self::assertSame('zz Nieuwe kolom', $column['title_nl']);
        self::assertSame('zz New column', $column['title_en']);
        self::assertNotSame($column['title_nl'], $column['title_en']);
    }

    public function testThePublicFooterCarriesBothLanguagesSeparately(): void
    {
        $columnId = $this->makeColumn('zz Kolom NL', 'zz Column EN');
        $this->makeLink($columnId, 'zz Link NL', 'zz Link EN');

        $column = $this->publicFooterColumn($columnId);
        $title = SiteText::attrs($column['title_nl'], $column['title_en']);
        $link = SiteText::attrs($column['links'][0]['label_nl'], $column['links'][0]['label_en']);

        self::assertStringContainsString('data-nl="zz Kolom NL"', $title);
        self::assertStringContainsString('data-en="zz Column EN"', $title);
        self::assertStringContainsString('data-nl="zz Link NL"', $link);
        self::assertStringContainsString('data-en="zz Link EN"', $link);
    }

    /**
     * Footer text with no translation yet: the stored column stays empty and
     * the visitor still reads the primary language. The same distinction as
     * everywhere else, raw for the editor and resolved for the visitor, now
     * asked of a footer column and its link.
     */
    public function testAnUntranslatedFooterLabelStaysEmptyAndStillRendersForAVisitor(): void
    {
        $columnId = $this->makeColumn('zz Kolom NL', '');
        $this->makeLink($columnId, 'zz Link NL', '');

        $column = $this->publicFooterColumn($columnId);

        self::assertSame('', $column['title_en'], 'the stored translation stays empty');
        self::assertSame('', $column['links'][0]['label_en']);
        self::assertStringContainsString(
            'data-en="zz Kolom NL"',
            SiteText::attrs($column['title_nl'], $column['title_en'])
        );
    }

    // --------------------------------------------------------------- helpers

    private function makeNavItem(string $labelNl, string $labelEn): int
    {
        $id = $this->navigation->create([
            'label_nl' => $labelNl,
            'label_en' => $labelEn,
            'link_type' => 'none',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
        ]);
        $this->navIds[] = $id;

        return $id;
    }

    /** Exactly the payload api/admin/update-nav-item.php builds from the form. */
    private function updateNavLabels(int $id, string $labelNl, string $labelEn): void
    {
        $this->navigation->update($id, [
            'label_nl' => $labelNl,
            'label_en' => $labelEn,
            'link_type' => 'none',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
    }

    private function makeColumn(string $titleNl, string $titleEn): int
    {
        $id = $this->footer->createColumn(['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => true]);
        $this->columnIds[] = $id;

        return $id;
    }

    /** Exactly the payload api/admin/update-footer-column.php builds from the form. */
    private function updateColumnTitles(int $id, string $titleNl, string $titleEn): void
    {
        $this->footer->updateColumn($id, ['title_nl' => $titleNl, 'title_en' => $titleEn, 'is_visible' => true]);
    }

    private function makeLink(int $columnId, string $labelNl, string $labelEn): int
    {
        return $this->footer->createLink([
            'column_id' => $columnId,
            'label_nl' => $labelNl,
            'label_en' => $labelEn,
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => '/zz-test',
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
    }

    /** Exactly the payload api/admin/update-footer-link.php builds from the form. */
    private function updateLinkLabels(int $id, string $labelNl, string $labelEn): void
    {
        $this->footer->updateLink($id, [
            'label_nl' => $labelNl,
            'label_en' => $labelEn,
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => '/zz-test',
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);
    }

    /** @return array<string, mixed> the item as partials/header.php receives it */
    private function publicNavItem(int $id): array
    {
        foreach (NavigationService::tree() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail('navigation item ' . $id . ' is not in the public tree');
    }

    /** @return array<string, mixed> the column as partials/footer.php receives it */
    private function publicFooterColumn(int $id): array
    {
        foreach (FooterService::columns() as $column) {
            if ($column['id'] === $id) {
                return $column;
            }
        }

        self::fail('footer column ' . $id . ' is not in the public footer');
    }
}

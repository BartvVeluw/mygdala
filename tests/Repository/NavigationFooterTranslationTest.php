<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\FooterLocalization;
use App\Service\FooterService;
use App\Service\Language\SiteLanguages;
use App\Service\NavigationLocalization;
use App\Service\NavigationService;
use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;

/**
 * The words of the header menu and the footer, stored per website language
 * (Multilingual 2.0 phase 4, docs/multilingual/ARCHITECTURE.md), against the
 * real test database.
 *
 * SAVING ONE LANGUAGE NEVER TOUCHES ANOTHER. That used to be a property of
 * a form that posted both Dutch and English columns at once; it is now a
 * property of the storage itself, one row per owner per language, and this
 * class proves it for menu items, footer columns and footer links alike —
 * including for a third language that is nothing but a row in
 * site_languages.
 *
 * And the schema rules no code can walk past: an owner's words go with the
 * owner (CASCADE), a language that still has words cannot be deleted
 * (RESTRICT), and an empty language has no row.
 *
 * Every row is this test's own and removed by id in tearDown(); the German
 * language row too.
 */
final class NavigationFooterTranslationTest extends TestCase
{
    private NavigationRepository $navigation;
    private FooterRepository $footer;

    /** @var list<int> */
    private array $navIds = [];

    /** @var list<int> */
    private array $columnIds = [];

    private bool $addedGerman = false;

    protected function setUp(): void
    {
        $this->navigation = new NavigationRepository();
        $this->footer = new FooterRepository();

        self::assertSame('nl', SiteLanguages::defaultCode(), 'this test expects the Dutch-default test database');
    }

    protected function tearDown(): void
    {
        foreach ($this->navIds as $id) {
            $this->navigation->delete($id);
        }
        foreach ($this->columnIds as $id) {
            $this->footer->deleteColumn($id);
        }

        if ($this->addedGerman) {
            (new SiteLanguageRepository())->delete('de');
        }

        NavigationLocalization::clearCache();
        FooterLocalization::clearCache();
        SiteLanguages::clearCache();
    }

    // ------------------------------------------------------------ navigation

    public function testSavingOneLanguageOfAMenuLabelLeavesEveryOtherLanguageAlone(): void
    {
        $id = $this->navItem();
        NavigationLocalization::save($id, 'nl', 'zz Over ons');
        NavigationLocalization::save($id, 'en', 'zz About us');

        NavigationLocalization::save($id, 'nl', 'zz Wie wij zijn');
        self::assertSame(['zz Wie wij zijn', 'zz About us'], $this->storedLabels($id));

        NavigationLocalization::save($id, 'en', 'zz Who we are');
        self::assertSame(['zz Wie wij zijn', 'zz Who we are'], $this->storedLabels($id));
    }

    public function testAnEmptyTranslationRemovesOnlyThatLanguagesRow(): void
    {
        $id = $this->navItem();
        NavigationLocalization::save($id, 'nl', 'zz Contact');
        NavigationLocalization::save($id, 'en', 'zz Contact us');

        NavigationLocalization::save($id, 'en', '   ');

        self::assertSame(['nl'], $this->languagesWithARow('nav_item_translations', 'nav_item_id', $id));
        self::assertSame(['zz Contact', ''], $this->storedLabels($id));
    }

    public function testTheHeaderReadsTheLanguageOfTheRequestWithTheFallback(): void
    {
        $translated = $this->navItem('external', '/zz-a');
        NavigationLocalization::save($translated, 'nl', 'zz Winkel');
        NavigationLocalization::save($translated, 'en', 'zz Store');
        $untranslated = $this->navItem('external', '/zz-b');
        NavigationLocalization::save($untranslated, 'nl', 'zz Blog');
        NavigationLocalization::clearCache();

        $labels = static function (): array {
            $labels = [];
            foreach (NavigationService::tree() as $item) {
                $labels[$item['id']] = $item['label'];
            }

            return $labels;
        };

        $dutch = $this->in('nl', $labels);
        $english = $this->in('en', $labels);

        self::assertSame('zz Winkel', $dutch[$translated]);
        self::assertSame('zz Store', $english[$translated]);
        self::assertSame('zz Blog', $english[$untranslated], 'an untranslated label falls back to the default language');
    }

    public function testAThirdLanguageIsOnlyARowInTheRegistry(): void
    {
        $this->addGerman();
        $id = $this->navItem();
        NavigationLocalization::save($id, 'nl', 'zz Over ons');
        NavigationLocalization::save($id, 'en', 'zz About us');

        NavigationLocalization::save($id, 'de', 'zz Über uns');
        NavigationLocalization::clearCache();

        self::assertSame('zz Über uns', NavigationLocalization::raw($id, 'de'));
        self::assertSame(['zz Over ons', 'zz About us'], $this->storedLabels($id), 'German left Dutch and English alone');
        self::assertSame('zz Over ons', NavigationLocalization::items()->value($id, NavigationLocalization::LABEL, 'fr'), 'a language without words falls back to the default');
    }

    public function testALanguageTheRegistryDoesNotHaveIsRefused(): void
    {
        $id = $this->navItem();

        $this->expectException(\InvalidArgumentException::class);
        NavigationLocalization::save($id, 'xx', 'zz Nope');
    }

    public function testALabelLongerThanItsColumnIsRefusedRatherThanCut(): void
    {
        $id = $this->navItem();

        $this->expectException(\InvalidArgumentException::class);
        NavigationLocalization::save($id, 'nl', str_repeat('a', NavigationLocalization::LABEL_MAX_LENGTH + 1));
    }

    public function testDeletingAnItemTakesItsWordsInEveryLanguage(): void
    {
        $id = $this->navItem();
        NavigationLocalization::save($id, 'nl', 'zz Weg');
        NavigationLocalization::save($id, 'en', 'zz Gone');

        $this->navigation->delete($id);

        self::assertSame([], $this->languagesWithARow('nav_item_translations', 'nav_item_id', $id));
    }

    public function testALanguageThatStillHasMenuWordsCannotBeDeleted(): void
    {
        $this->addGerman();
        $id = $this->navItem();
        NavigationLocalization::save($id, 'de', 'zz Über uns');

        try {
            Database::connection()->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
            self::fail('the foreign key should have refused deleting a language with words');
        } catch (\PDOException $e) {
            self::assertSame('23000', $e->getCode());
        }

        $this->navigation->delete($id);
    }

    // ---------------------------------------------------------------- footer

    public function testSavingOneLanguageOfAFooterColumnOrLinkLeavesTheOtherAlone(): void
    {
        [$column, $link] = $this->columnWithLink();
        FooterLocalization::saveColumnTitle($column, 'nl', 'zz Service');
        FooterLocalization::saveColumnTitle($column, 'en', 'zz Support');
        FooterLocalization::saveLinkLabel($link, 'nl', 'zz Voorwaarden');
        FooterLocalization::saveLinkLabel($link, 'en', 'zz Terms');

        FooterLocalization::saveColumnTitle($column, 'en', 'zz Help');
        FooterLocalization::saveLinkLabel($link, 'nl', 'zz Algemene voorwaarden');
        FooterLocalization::clearCache();

        self::assertSame(
            ['zz Service', 'zz Help', 'zz Algemene voorwaarden', 'zz Terms'],
            [
                FooterLocalization::rawColumnTitle($column, 'nl'),
                FooterLocalization::rawColumnTitle($column, 'en'),
                FooterLocalization::rawLinkLabel($link, 'nl'),
                FooterLocalization::rawLinkLabel($link, 'en'),
            ]
        );
    }

    public function testThePublicFooterReadsTheLanguageOfTheRequest(): void
    {
        [$column, $link] = $this->columnWithLink();
        FooterLocalization::saveColumnTitle($column, 'nl', 'zz Juridisch');
        FooterLocalization::saveLinkLabel($link, 'nl', 'zz Privacy');
        FooterLocalization::saveLinkLabel($link, 'en', 'zz Privacy policy');
        FooterLocalization::clearCache();

        $ours = static fn (): array => array_values(array_filter(FooterService::columns(), static fn (array $c): bool => $c['id'] === $column));
        $dutch = $this->in('nl', $ours);
        $english = $this->in('en', $ours);

        self::assertCount(1, $english);
        self::assertSame('zz Juridisch', $dutch[0]['title']);
        self::assertSame('zz Juridisch', $english[0]['title'], 'an untranslated title falls back to the default language');
        self::assertSame('zz Privacy', $dutch[0]['links'][0]['label']);
        self::assertSame('zz Privacy policy', $english[0]['links'][0]['label']);
    }

    public function testDeletingAColumnTakesTheWordsOfItsLinksToo(): void
    {
        [$column, $link] = $this->columnWithLink();
        FooterLocalization::saveColumnTitle($column, 'nl', 'zz Kolom');
        FooterLocalization::saveLinkLabel($link, 'nl', 'zz Link');

        $this->footer->deleteColumn($column);

        self::assertSame([], $this->languagesWithARow('footer_column_translations', 'footer_column_id', $column));
        self::assertSame([], $this->languagesWithARow('footer_link_translations', 'footer_link_id', $link));
    }

    // --------------------------------------------------------------- helpers

    private function navItem(string $type = 'none', ?string $url = null): int
    {
        $id = $this->navigation->create([
            'link_type' => $type,
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => $url,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
        ]);
        $this->navIds[] = $id;

        return $id;
    }

    /** @return array{0: int, 1: int} */
    private function columnWithLink(): array
    {
        $column = $this->footer->createColumn(['is_visible' => true]);
        $this->columnIds[] = $column;

        $link = $this->footer->createLink([
            'column_id' => $column,
            'link_type' => 'external',
            'target_page_id' => null,
            'target_route' => null,
            'external_url' => '/zz-footer',
            'action_key' => null,
            'open_in_new_tab' => false,
            'is_visible' => true,
        ]);

        return [$column, $link];
    }

    /** @return array{0: string, 1: string} the stored Dutch and English label, no fallback */
    private function storedLabels(int $id): array
    {
        NavigationLocalization::clearCache();

        return [NavigationLocalization::raw($id, 'nl'), NavigationLocalization::raw($id, 'en')];
    }

    /** @return list<string> */
    private function languagesWithARow(string $table, string $ownerColumn, int $id): array
    {
        $stmt = Database::connection()->prepare("SELECT language_code FROM {$table} WHERE {$ownerColumn} = :id ORDER BY language_code DESC");
        $stmt->execute(['id' => $id]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function addGerman(): void
    {
        if (!SiteLanguages::exists('de')) {
            (new SiteLanguageRepository())->create('de', 'German', 'Deutsch');
            $this->addedGerman = true;
            SiteLanguages::clearCache();
        }
    }

    /**
     * Run $work while the request is answered in $language.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function in(string $language, \Closure $work): mixed
    {
        RequestLanguage::set($language, true);

        try {
            return $work();
        } finally {
            RequestLanguage::reset();
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageTranslationRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * page_translations against the test database (Multilingual 2.0 phase 2,
 * docs/multilingual/ARCHITECTURE.md): what the schema itself refuses, what
 * the repository writes, and PageLocalization's reads and writes on real
 * rows — including a third website language that needs no schema change.
 *
 * Every test runs inside a transaction on the shared connection and rolls it
 * back, like Tests\Repository\SiteLanguageRepositoryTest, so the test
 * database is the same after the run. The registry rows a test adds (German)
 * are rolled back with it.
 */
final class PageTranslationRepositoryTest extends TestCase
{
    private PDO $db;
    private PageTranslationRepository $repository;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        $this->repository = new PageTranslationRepository($this->db);
        SiteLanguages::clearCache();
        PageLocalization::clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        SiteLanguages::clearCache();
        PageLocalization::clearCache();
    }

    // ------------------------------------------------------------ the repository

    public function testOneLanguageIsWrittenAndReadBack(): void
    {
        $pageId = $this->createPage();

        $this->repository->save($pageId, 'en', 'About us', null, 'What we do');

        $row = $this->repository->find($pageId, 'en');
        self::assertNotNull($row);
        self::assertSame('About us', $row['title']);
        self::assertNull($row['meta_title']);
        self::assertSame('What we do', $row['meta_description']);
        self::assertTrue($this->repository->exists($pageId, 'en'));
        self::assertFalse($this->repository->exists($pageId, 'nl'));
    }

    public function testSavingAgainReplacesTheRowInsteadOfAddingOne(): void
    {
        $pageId = $this->createPage();

        $this->repository->save($pageId, 'nl', 'Over ons', 'SEO', null);
        $this->repository->save($pageId, 'nl', 'Wie wij zijn', null, 'Nieuw');

        self::assertSame(1, $this->translationRows($pageId));
        $row = $this->repository->find($pageId, 'nl');
        self::assertSame('Wie wij zijn', $row['title']);
        self::assertNull($row['meta_title']);
        self::assertSame('Nieuw', $row['meta_description']);
    }

    public function testTheSchemaRefusesASecondRowForTheSameLanguage(): void
    {
        $pageId = $this->createPage();
        $this->repository->save($pageId, 'nl', 'Over ons', null, null);

        $this->expectException(\PDOException::class);
        $this->db->prepare("INSERT INTO page_translations (page_id, language_code, title) VALUES (?, 'nl', 'Tweede')")
            ->execute([$pageId]);
    }

    public function testTheSchemaRefusesALanguageTheRegistryDoesNotHave(): void
    {
        $pageId = $this->createPage();

        $this->expectException(\PDOException::class);
        $this->repository->save($pageId, 'xx', 'Nowhere', null, null);
    }

    public function testTheSchemaRefusesAPageThatDoesNotExist(): void
    {
        $missing = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) + 1000 FROM pages')->fetchColumn();

        $this->expectException(\PDOException::class);
        $this->repository->save($missing, 'nl', 'Wees', null, null);
    }

    public function testDeletingAPageDeletesItsText(): void
    {
        $pageId = $this->createPage();
        $this->repository->save($pageId, 'nl', 'Over ons', null, null);
        $this->repository->save($pageId, 'en', 'About us', null, null);

        (new PageRepository($this->db))->delete($pageId);

        self::assertSame(0, $this->translationRows($pageId));
    }

    public function testALanguageWithPageTextCannotBeDeleted(): void
    {
        (new SiteLanguageRepository($this->db))->create('de', 'German', 'Deutsch');
        $pageId = $this->createPage();
        $this->repository->save($pageId, 'de', 'Über uns', null, null);

        // RESTRICT, not CASCADE: removing a language never takes its
        // translations along without anybody noticing.
        $this->expectException(\PDOException::class);
        (new SiteLanguageRepository($this->db))->delete('de');
    }

    public function testManyPagesAreReadInOneGo(): void
    {
        $first = $this->createPage();
        $second = $this->createPage();
        $this->repository->save($first, 'nl', 'Eerste', null, null);
        $this->repository->save($second, 'nl', 'Tweede', null, null);
        $this->repository->save($second, 'en', 'Second', null, null);

        $rows = $this->repository->findForPages([$first, $second, $second, 0, -3]);

        self::assertSame(['nl'], array_keys($rows[$first]));
        self::assertSame(['nl', 'en'], array_keys($rows[$second]));
        self::assertSame([], $this->repository->findForPages([]));
    }

    // ------------------------------------------------------------ PageLocalization on real rows

    public function testTheServiceStoresOneLanguageAndLeavesTheOthersAlone(): void
    {
        $pageId = $this->createPage();
        PageLocalization::save($pageId, 'nl', ['title' => 'Over ons', 'meta_title' => 'SEO nl', 'meta_description' => '']);
        PageLocalization::save($pageId, 'en', ['title' => '  About us  ']);

        self::assertSame('Over ons', PageLocalization::raw($pageId, PageTranslation::TITLE, 'nl'));
        self::assertSame('SEO nl', PageLocalization::raw($pageId, PageTranslation::META_TITLE, 'nl'));
        self::assertNull($this->repository->find($pageId, 'nl')['meta_description'], 'empty is stored as NULL');
        self::assertSame('About us', $this->repository->find($pageId, 'en')['title'], 'trimmed');
        self::assertSame('SEO nl', PageLocalization::value($pageId, PageTranslation::META_TITLE, 'en'));
    }

    public function testClearingEveryFieldOfALanguageRemovesItsRow(): void
    {
        $pageId = $this->createPage();
        PageLocalization::save($pageId, 'nl', ['title' => 'Over ons']);
        PageLocalization::save($pageId, 'en', ['title' => 'About us']);

        PageLocalization::save($pageId, 'en', ['title' => ' ', 'meta_title' => '', 'meta_description' => null]);

        self::assertFalse($this->repository->exists($pageId, 'en'));
        self::assertFalse(PageLocalization::has($pageId, 'en'));
        self::assertSame('Over ons', PageLocalization::title($pageId, 'en'));
    }

    public function testAThirdLanguageNeedsARowAndNoSchemaChange(): void
    {
        (new SiteLanguageRepository($this->db))->create('de', 'German', 'Deutsch');
        SiteLanguages::clearCache();
        $pageId = $this->createPage();

        PageLocalization::save($pageId, 'nl', ['title' => 'Over ons', 'meta_description' => 'Omschrijving']);
        PageLocalization::save($pageId, 'de', ['title' => 'Über uns']);
        PageLocalization::clearCache();

        self::assertSame(['nl', 'de'], array_keys(PageLocalization::translations($pageId)));
        self::assertSame('Über uns', PageLocalization::title($pageId, 'de'));
        self::assertSame('Omschrijving', PageLocalization::value($pageId, PageTranslation::META_DESCRIPTION, 'de'));
        self::assertSame('Over ons', PageLocalization::name($pageId));
    }

    public function testTheServiceRefusesALanguageBeforeTheDatabaseDoes(): void
    {
        $pageId = $this->createPage();

        $this->expectException(\InvalidArgumentException::class);
        PageLocalization::save($pageId, 'fr', ['title' => 'À propos']);
    }

    private function createPage(): int
    {
        static $sequence = 0;
        $sequence++;
        $key = 'zz-page-translation-test-' . $sequence . '-' . bin2hex(random_bytes(3));

        return (new PageRepository($this->db))->create([
            'content_key' => $key,
            'slug' => $key,
            'title' => 'Testpagina',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    private function translationRows(int $pageId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM page_translations WHERE page_id = ?');
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }
}

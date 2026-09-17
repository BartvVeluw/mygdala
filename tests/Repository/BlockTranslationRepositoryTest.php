<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\BlockTranslationRepository;
use App\Repository\SiteLanguageRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Blocks\TranslatableField;
use App\Service\Language\SiteLanguages;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * block_translations against the test database (Multilingual 2.0 phase 3,
 * docs/multilingual/ARCHITECTURE.md): what the schema refuses on its own,
 * what the repository writes, one query for many owners, and
 * BlockLocalization's reads and writes on real rows, including a third
 * website language that needs no schema change.
 *
 * Every test runs inside a transaction on the shared connection and rolls it
 * back, like PageTranslationRepositoryTest, so the owner rows, the words and
 * the German registry row a test adds never outlive it. The registry of
 * declared fields is pinned through BlockLocalization's test seam, on two
 * real content tables, so this test does not depend on which block types a
 * later phase converts.
 */
final class BlockTranslationRepositoryTest extends TestCase
{
    private const RICH = 'rich_text_sections';
    private const CARDS = 'contact_cards';

    private PDO $db;
    private BlockTranslationRepository $repository;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        $this->repository = new BlockTranslationRepository($this->db);
        SiteLanguages::clearCache();
        BlockLocalization::overrideRegistryForTests([
            self::RICH => [TranslatableField::rich('body', 1000)],
            self::CARDS => [TranslatableField::plain('title', 50)->required(), TranslatableField::plain('body', 200)],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        SiteLanguages::clearCache();
        BlockLocalization::overrideRegistryForTests(null);
        BlockLocalization::clearCache();
    }

    // ------------------------------------------------------------ the schema

    public function testOneFieldInOneLanguageOfOneOwnerExistsOnce(): void
    {
        $id = $this->owner(self::CARDS);
        $insert = $this->db->prepare(
            'INSERT INTO block_translations (owner_table, owner_id, language_code, field, value) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([self::CARDS, $id, 'nl', 'title', 'Eerste']);

        $this->expectException(\PDOException::class);
        $insert->execute([self::CARDS, $id, 'nl', 'title', 'Tweede']);
    }

    public function testOnlyARegisteredLanguageCanBeStored(): void
    {
        $id = $this->owner(self::CARDS);

        $this->expectException(\PDOException::class);
        $this->repository->replaceLanguage(self::CARDS, $id, 'xx', ['title' => 'Nee']);
    }

    public function testALanguageWithBlockWordsCannotBeDeleted(): void
    {
        (new SiteLanguageRepository($this->db))->create('de', 'German', 'Deutsch');
        $id = $this->owner(self::CARDS);
        $this->repository->replaceLanguage(self::CARDS, $id, 'de', ['title' => 'Karte']);

        $this->expectException(\PDOException::class);
        $this->db->prepare("DELETE FROM site_languages WHERE code = 'de'")->execute();
    }

    // ------------------------------------------------------------ the repository

    public function testReplacingALanguageWritesUpdatesAndRemovesItsFieldsOnly(): void
    {
        $id = $this->owner(self::CARDS);

        $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Titel', 'body' => 'Tekst']);
        $this->repository->replaceLanguage(self::CARDS, $id, 'en', ['title' => 'Title']);
        $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Nieuwe titel']);

        self::assertSame(
            ['nl' => ['title' => 'Nieuwe titel'], 'en' => ['title' => 'Title']],
            $this->repository->findForOwners([self::CARDS => [$id]])[self::CARDS][$id]
        );

        $this->repository->replaceLanguage(self::CARDS, $id, 'en', []);
        self::assertSame(['nl' => ['title' => 'Nieuwe titel']], $this->repository->findForOwners([self::CARDS => [$id]])[self::CARDS][$id]);
    }

    public function testAFieldThatDidNotChangeKeepsItsTimestamp(): void
    {
        $id = $this->owner(self::CARDS);
        $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Titel', 'body' => 'Tekst']);
        $this->db->prepare("UPDATE block_translations SET updated_at = '2020-01-01 00:00:00' WHERE owner_table = ? AND owner_id = ?")
            ->execute([self::CARDS, $id]);

        $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Titel', 'body' => 'tekst']);

        $stamps = $this->db->prepare('SELECT field, updated_at FROM block_translations WHERE owner_table = ? AND owner_id = ? ORDER BY field');
        $stamps->execute([self::CARDS, $id]);
        $byField = array_column($stamps->fetchAll(), 'updated_at', 'field');

        self::assertSame('2020-01-01 00:00:00', $byField['title']);
        self::assertNotSame('2020-01-01 00:00:00', $byField['body'], 'a change of capitals is a change');
    }

    public function testManyOwnersInSeveralTablesComeBackFromOneQuery(): void
    {
        $first = $this->owner(self::CARDS);
        $second = $this->owner(self::CARDS);
        $rich = $this->owner(self::RICH);
        $this->repository->replaceLanguage(self::CARDS, $first, 'nl', ['title' => 'Een']);
        $this->repository->replaceLanguage(self::CARDS, $second, 'en', ['title' => 'Two']);
        $this->repository->replaceLanguage(self::RICH, $rich, 'nl', ['body' => '<p>Drie</p>']);

        $before = $this->selects();
        $rows = $this->repository->findForOwners([self::CARDS => [$first, $second, 0], self::RICH => [$rich], 'unused' => []]);
        self::assertSame(1, $this->selects() - $before);

        self::assertSame('Een', $rows[self::CARDS][$first]['nl']['title']);
        self::assertSame('Two', $rows[self::CARDS][$second]['en']['title']);
        self::assertSame('<p>Drie</p>', $rows[self::RICH][$rich]['nl']['body']);
        self::assertSame([], $this->repository->findForOwners([]));
    }

    public function testTheSameIdInAnotherTableIsAnotherOwner(): void
    {
        $id = $this->owner(self::CARDS);
        $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Kaart']);

        self::assertSame([], $this->repository->findForOwners([self::RICH => [$id]]));
        self::assertSame(0, $this->repository->deleteOwner(self::RICH, $id));
        self::assertSame(1, $this->repository->deleteOwner(self::CARDS, $id));
    }

    public function testATableNameIsNeverInterpolatedUnlessItIsAPlainIdentifier(): void
    {
        foreach (['pages; DROP TABLE pages', 'Pages', '`pages`', ''] as $name) {
            try {
                $this->repository->ownerExists($name, 1);
                self::fail('accepted ' . $name);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    // ------------------------------------------------------------ BlockLocalization on real rows

    public function testSavingOneLanguageLeavesTheOthersAlone(): void
    {
        $id = $this->owner(self::CARDS);

        BlockLocalization::save(self::CARDS, $id, 'nl', ['title' => '  Titel ', 'body' => 'Tekst']);
        BlockLocalization::save(self::CARDS, $id, 'en', ['title' => 'Title', 'body' => '']);
        BlockLocalization::save(self::CARDS, $id, 'nl', ['title' => 'Titel 2', 'body' => 'Tekst']);

        BlockLocalization::clearCache();
        self::assertSame(['nl' => ['title' => 'Titel 2', 'body' => 'Tekst'], 'en' => ['title' => 'Title']], BlockLocalization::translations(self::CARDS, $id));
        self::assertSame('Tekst', BlockLocalization::value(self::CARDS, $id, 'body', 'en'));
    }

    public function testRichTextIsStoredSanitized(): void
    {
        $id = $this->owner(self::RICH);

        BlockLocalization::save(self::RICH, $id, 'nl', ['body' => '<p>Tekst<script>alert(1)</script></p>']);

        $stored = $this->repository->findForOwners([self::RICH => [$id]])[self::RICH][$id]['nl']['body'];
        self::assertStringContainsString('Tekst', $stored);
        self::assertStringNotContainsString('script', $stored);
    }

    public function testAThirdLanguageNeedsOnlyARowInTheRegistry(): void
    {
        (new SiteLanguageRepository($this->db))->create('de', 'German', 'Deutsch');
        SiteLanguages::clearCache();
        $id = $this->owner(self::CARDS);

        BlockLocalization::save(self::CARDS, $id, 'nl', ['title' => 'Titel', 'body' => 'Tekst']);
        BlockLocalization::save(self::CARDS, $id, 'de', ['title' => 'Karte']);

        BlockLocalization::clearCache();
        self::assertSame('Karte', BlockLocalization::value(self::CARDS, $id, 'title', 'de'));
        self::assertSame('Tekst', BlockLocalization::value(self::CARDS, $id, 'body', 'de'));
    }

    public function testAnUnregisteredLanguageOrAMissingOwnerIsRefusedAndNothingIsWritten(): void
    {
        $id = $this->owner(self::CARDS);

        foreach ([['fr', $id], ['nl', $id + 100000]] as [$language, $owner]) {
            try {
                BlockLocalization::save(self::CARDS, $owner, $language, ['title' => 'x']);
                self::fail('saved ' . $language . ' #' . $owner);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        $count = $this->db->prepare('SELECT COUNT(*) FROM block_translations WHERE owner_table = ? AND owner_id IN (?, ?)');
        $count->execute([self::CARDS, $id, $id + 100000]);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testPreloadingAPageOfOwnersCostsOneQueryAndReadingItCostsNone(): void
    {
        $ids = [];
        for ($i = 0; $i < 15; $i++) {
            $ids[] = $id = $this->owner(self::CARDS);
            $this->repository->replaceLanguage(self::CARDS, $id, 'nl', ['title' => 'Kaart ' . $i]);
        }
        $rich = $this->owner(self::RICH);
        BlockLocalization::clearCache();
        // The website language registry is read once per request anyway; it
        // is not what this test counts.
        BlockLocalization::defaultLanguage();

        $before = $this->selects();
        BlockLocalization::preload([self::CARDS => $ids, self::RICH => [$rich]]);
        self::assertSame(1, $this->selects() - $before, 'one query for sixteen owners in two tables');

        $before = $this->selects();
        foreach ($ids as $i => $id) {
            foreach (['nl', 'en'] as $language) {
                self::assertSame('Kaart ' . $i, BlockLocalization::value(self::CARDS, $id, 'title', $language));
                BlockLocalization::value(self::CARDS, $id, 'body', $language);
            }
        }
        BlockLocalization::value(self::RICH, $rich, 'body', 'en');
        self::assertSame(0, $this->selects() - $before, 'no query per block, per language or per field');
    }

    public function testDeletingAnOwnerRemovesEveryLanguage(): void
    {
        $id = $this->owner(self::CARDS);
        BlockLocalization::save(self::CARDS, $id, 'nl', ['title' => 'Titel']);
        BlockLocalization::save(self::CARDS, $id, 'en', ['title' => 'Title']);

        BlockLocalization::deleteOwner(self::CARDS, $id);

        self::assertSame([], $this->repository->findForOwners([self::CARDS => [$id]]));
        self::assertSame([], BlockLocalization::translations(self::CARDS, $id));
    }

    // ------------------------------------------------------------ orphans

    public function testOrphansAreFoundAndOnlyWordsWithoutAnOwnerArePurged(): void
    {
        $live = $this->owner(self::CARDS);
        $gone = $this->owner(self::CARDS);
        $this->repository->replaceLanguage(self::CARDS, $live, 'nl', ['title' => 'Blijft']);
        $this->repository->replaceLanguage(self::CARDS, $gone, 'nl', ['title' => 'Wees', 'body' => 'Wees']);
        $this->repository->replaceLanguage(self::CARDS, $live, 'en', ['subtitle' => 'Old field']);
        $this->repository->replaceLanguage('zz_switched_off_module_blocks', 1, 'nl', ['title' => 'Module uit']);
        $this->db->prepare('DELETE FROM contact_cards WHERE id = ?')->execute([$gone]);

        $report = BlockLocalization::orphans();

        self::assertContains(['owner_table' => self::CARDS, 'owner_id' => $gone, 'rows' => 2], $report['missing_owner']);
        self::assertNotContains($live, array_column($report['missing_owner'], 'owner_id'));
        self::assertContains(['owner_table' => self::CARDS, 'field' => 'subtitle', 'rows' => 1], $report['undeclared_field']);
        self::assertContains(['owner_table' => 'zz_switched_off_module_blocks', 'rows' => 1], $report['unregistered_table']);

        self::assertGreaterThanOrEqual(2, BlockLocalization::purgeOrphans());
        self::assertSame(0, BlockLocalization::purgeOrphans(), 'running it again removes nothing');

        self::assertSame([], $this->repository->findForOwners([self::CARDS => [$gone]]));
        self::assertSame(['nl' => ['title' => 'Blijft'], 'en' => ['subtitle' => 'Old field']], $this->repository->findForOwners([self::CARDS => [$live]])[self::CARDS][$live], 'reported-only words stay');
        self::assertSame([['owner_table' => 'zz_switched_off_module_blocks', 'rows' => 1]], array_values(array_filter(
            BlockLocalization::orphans()['unregistered_table'],
            static fn (array $row): bool => $row['owner_table'] === 'zz_switched_off_module_blocks'
        )));
    }

    private function owner(string $table): int
    {
        $this->db->prepare("INSERT INTO `{$table}` (page_slug, section_key, is_active, created_at, updated_at) VALUES ('zz-block-translation-test', ?, 1, NOW(), NOW())")
            ->execute(['zz-' . bin2hex(random_bytes(4))]);

        return (int) $this->db->lastInsertId();
    }

    private function selects(): int
    {
        return (int) $this->db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch()['Value'];
    }
}

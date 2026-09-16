<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Database;
use App\Repository\SiteLanguageRepository;
use App\Service\Language\ContentLanguages;
use App\Service\Language\SiteLanguages;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * site_languages against the test database (docs/multilingual/ARCHITECTURE.md):
 * the invariants the schema and the repository's SQL hold on their own, and
 * that the V1 adapter follows the registry.
 *
 * Every test runs inside a transaction on the shared connection and rolls it
 * back, so the registry the migration left in the test database is the same
 * after the run. The repository's own writes take part in that transaction,
 * exactly as they take part in the Setup Wizard's.
 */
final class SiteLanguageRepositoryTest extends TestCase
{
    private PDO $db;
    private SiteLanguageRepository $repository;

    protected function setUp(): void
    {
        $this->db = Database::connection();
        $this->db->beginTransaction();
        $this->repository = new SiteLanguageRepository($this->db);
        SiteLanguages::clearCache();
    }

    protected function tearDown(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        SiteLanguages::clearCache();
    }

    // ---------------------------------------------- what the migration left

    public function testTheRegistryHasExactlyOneActiveDefault(): void
    {
        $defaults = array_values(array_filter($this->repository->findAll(), static fn (array $r): bool => (int) $r['is_default'] === 1));

        self::assertCount(1, $defaults);
        self::assertSame(1, (int) $defaults[0]['is_active']);
        self::assertSame(
            0,
            (int) $this->db->query('SELECT COUNT(*) FROM site_languages WHERE is_default = 0')->fetchColumn(),
            'a row that is not the default is NULL, never 0'
        );
    }

    public function testTheTestDatabaseIsTheBilingualSiteItWasBuiltFrom(): void
    {
        self::assertSame(['nl', 'en'], $this->codes());
        self::assertSame('nl', SiteLanguages::defaultCode());
        self::assertSame('nl', ContentLanguages::primary());
    }

    public function testTheOrderIsTheSameEveryTime(): void
    {
        self::assertSame($this->codes(), $this->codes());
    }

    // ------------------------------------------------------------ the default

    public function testMovingTheDefaultLeavesExactlyOne(): void
    {
        self::assertTrue($this->repository->setDefault('en'));

        self::assertSame(['en'], $this->defaultCodes());
        self::assertSame(['nl', 'en'], $this->codes(), 'moving the default is not a reorder');
    }

    public function testTheV1AdapterFollowsTheRegistry(): void
    {
        self::assertSame('en', ContentLanguages::savePrimary('en'));

        self::assertSame('en', SiteLanguages::defaultCode());
        self::assertSame('en', ContentLanguages::primary());
        self::assertSame(['en', 'nl'], ContentLanguages::enabled());
    }

    public function testTheV1AdapterStoresAnUnsupportedChoiceAsDutch(): void
    {
        $this->repository->setDefault('en');

        self::assertSame('nl', ContentLanguages::savePrimary('de'));
        self::assertSame(['nl'], $this->defaultCodes());
    }

    public function testMakingTheDefaultTheDefaultAgainChangesNothing(): void
    {
        self::assertTrue($this->repository->setDefault('nl'));
        self::assertSame(['nl'], $this->defaultCodes());
    }

    public function testAnUnknownLanguageCannotBecomeTheDefault(): void
    {
        self::assertFalse($this->repository->setDefault('de'));
        self::assertSame(['nl'], $this->defaultCodes());

        $this->expectException(\InvalidArgumentException::class);
        SiteLanguages::setDefault('de');
    }

    public function testAnInactiveLanguageCannotBecomeTheDefault(): void
    {
        $this->repository->create('de', 'German', 'Deutsch', false);

        self::assertFalse($this->repository->setDefault('de'));
        self::assertSame(['nl'], $this->defaultCodes());
    }

    public function testTheDatabaseItselfRefusesASecondDefault(): void
    {
        $this->expectException(\PDOException::class);
        $this->db->exec("UPDATE site_languages SET is_default = 1 WHERE code = 'en'");
    }

    public function testTheDefaultCannotBeSwitchedOff(): void
    {
        self::assertFalse($this->repository->deactivate('nl'));
        self::assertSame(1, (int) $this->repository->findByCode('nl')['is_active']);
    }

    public function testTheDefaultCannotBeDeleted(): void
    {
        self::assertFalse($this->repository->delete('nl'));
        self::assertNotNull($this->repository->findByCode('nl'));
    }

    // -------------------------------------------------------- other languages

    public function testANewLanguageGoesToTheEndAndIsNeverTheDefault(): void
    {
        $this->repository->create('DE', 'German', 'Deutsch');

        $row = $this->repository->findByCode('de');
        self::assertNotNull($row, 'the code is stored in its normalised form');
        self::assertNull($row['is_default']);
        self::assertSame(1, (int) $row['is_active']);
        self::assertSame(['nl', 'en', 'de'], $this->codes());
    }

    public function testACodeIsUnique(): void
    {
        $this->expectException(\PDOException::class);
        $this->repository->create('en', 'English', 'English');
    }

    public function testAnInvalidCodeIsNeverStored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->create('en-gb', 'British English', 'British English');
    }

    public function testTheCodeColumnIsCaseSensitive(): void
    {
        // ascii_bin: an `EN` written past the repository is a different
        // value, not a duplicate that a case-insensitive collation would
        // quietly treat as `en`.
        self::assertNull($this->repository->findByCode('EN'));
    }

    public function testTiesInTheOrderAreBrokenByInsertion(): void
    {
        $this->repository->create('fr', 'French', 'Français');
        $this->repository->create('de', 'German', 'Deutsch');
        $this->db->exec("UPDATE site_languages SET sort_order = 5 WHERE code IN ('fr', 'de')");

        self::assertSame(['nl', 'en', 'fr', 'de'], $this->codes());
    }

    public function testAnotherLanguageCanBeSwitchedOffAndDeleted(): void
    {
        $this->repository->create('de', 'German', 'Deutsch');

        self::assertTrue($this->repository->deactivate('de'));
        self::assertSame(['nl', 'en'], array_map(
            static fn ($l): string => $l->code,
            SiteLanguages::active()
        ));

        self::assertTrue($this->repository->delete('de'));
        self::assertNull($this->repository->findByCode('de'));
    }

    public function testTheDefaultCanBeSwitchedOffOnceItIsNoLongerTheDefault(): void
    {
        $this->repository->setDefault('en');

        self::assertTrue($this->repository->deactivate('nl'));
        self::assertFalse($this->repository->deactivate('en'));
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_map(static fn (array $row): string => (string) $row['code'], $this->repository->findAll());
    }

    /** @return list<string> */
    private function defaultCodes(): array
    {
        return array_column(
            $this->db->query('SELECT code FROM site_languages WHERE is_default = 1')->fetchAll(),
            'code'
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScratchInstall;

/**
 * db/migrations/20260917120000_create_the_site_language_registry.php, on the
 * three kinds of database it meets (docs/multilingual/ARCHITECTURE.md):
 *
 *   fresh      every migration from zero, then the Setup Wizard
 *   Dutch      an installation deployed before the registry, Dutch primary
 *   English    the same, with English as its primary language
 *
 * What must hold on all of them: exactly Dutch and English, the stored
 * primary as the one active default and first in the order, unique codes,
 * the two replaced settings rows gone, not one content column touched, and a
 * second run that changes nothing.
 *
 * The registry each database had right after migrating is read once in
 * setUpBeforeClass(), so the tests that write afterwards (the wizard, the
 * replays) cannot change what the others assert.
 */
#[Group('migration-backfill')]
final class SiteLanguageRegistryMigrationTest extends TestCase
{
    private const FRESH = 'mygdala_scratch_lang_registry_fresh';
    private const DUTCH = 'mygdala_scratch_lang_registry_nl';
    private const ENGLISH = 'mygdala_scratch_lang_registry_en';

    /** The migration before this one. */
    private const BEFORE = '20260917100000';

    private const REGISTRY = '20260917120000';

    private const LEGACY_KEYS = ['primary_content_language', 'enabled_content_languages'];

    private static ?ScratchInstall $fresh = null;
    private static ?ScratchInstall $dutch = null;
    private static ?ScratchInstall $english = null;

    /** @var array<string, list<array{code: string, name: string, native_name: string, is_default: ?int, is_active: int, sort_order: int}>> */
    private static array $registryAfterMigrating = [];

    /** @var array<string, int> English menu labels before the migration ran */
    private static array $englishLabelsBefore = [];

    public static function setUpBeforeClass(): void
    {
        if (!ScratchInstall::available()) {
            return;
        }

        self::$fresh = ScratchInstall::fresh(self::FRESH);

        self::$dutch = ScratchInstall::upTo(self::DUTCH, self::BEFORE);
        self::$englishLabelsBefore[self::DUTCH] = self::englishLabels(self::$dutch);
        self::$dutch->catchUp();

        self::$english = ScratchInstall::upTo(self::ENGLISH, self::BEFORE);
        self::$english->pdo()->exec(
            "UPDATE site_settings SET setting_value = 'en', updated_at = NOW() WHERE setting_key = 'primary_content_language'"
        );
        self::$english->catchUp();

        foreach ([self::$fresh, self::$dutch, self::$english] as $install) {
            self::$registryAfterMigrating[$install->database] = self::registry($install);
        }
    }

    protected function setUp(): void
    {
        if (!ScratchInstall::available()) {
            $this->markTestSkipped('A from-zero install needs the MySQL root account (DB_ROOT_PASSWORD in .env).');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$fresh?->drop();
        self::$dutch?->drop();
        self::$english?->drop();
        self::$fresh = null;
        self::$dutch = null;
        self::$english = null;
    }

    // ------------------------------------------------------------ the schema

    public function testEveryDatabaseEndsWithTheSameTable(): void
    {
        $shapes = [];
        foreach ([self::$fresh, self::$dutch, self::$english] as $install) {
            $shapes[] = $install->rows(
                'SELECT column_name AS column_name, column_type AS column_type, is_nullable AS is_nullable, '
                . 'collation_name AS collation_name FROM information_schema.columns '
                . 'WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
                [$install->database, 'site_languages']
            );
        }

        self::assertNotSame([], $shapes[0]);
        self::assertSame($shapes[0], $shapes[1]);
        self::assertSame($shapes[0], $shapes[2]);

        $columns = array_column($shapes[0], null, 'column_name');
        self::assertSame(
            ['id', 'code', 'name', 'native_name', 'is_default', 'is_active', 'sort_order', 'created_at', 'updated_at'],
            array_keys($columns)
        );
        self::assertSame('ascii_bin', $columns['code']['collation_name']);
        self::assertSame('YES', $columns['is_default']['is_nullable']);
    }

    public function testCodesAndTheDefaultAreUniqueInTheSchema(): void
    {
        $unique = array_column(self::$fresh->rows(
            'SELECT index_name AS index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns '
            . 'FROM information_schema.statistics '
            . 'WHERE table_schema = ? AND table_name = ? AND non_unique = 0 GROUP BY index_name ORDER BY index_name',
            [self::FRESH, 'site_languages']
        ), 'columns', 'index_name');

        self::assertSame(
            ['PRIMARY' => 'id', 'uq_site_languages_code' => 'code', 'uq_site_languages_default' => 'is_default'],
            $unique
        );
    }

    // ------------------------------------------------------------ bootstrap

    public function testAFreshInstallGetsDutchAsTheDefaultAndEnglishBesideIt(): void
    {
        self::assertSame([
            ['nl', 'Dutch', 'Nederlands', 1, 1, 0],
            ['en', 'English', 'English', null, 1, 1],
        ], self::summary(self::FRESH));
    }

    public function testADutchInstallationKeepsDutchAsItsDefault(): void
    {
        self::assertSame([
            ['nl', 'Dutch', 'Nederlands', 1, 1, 0],
            ['en', 'English', 'English', null, 1, 1],
        ], self::summary(self::DUTCH));
    }

    public function testAnEnglishInstallationKeepsEnglishAsItsDefaultAndFirst(): void
    {
        self::assertSame([
            ['en', 'English', 'English', 1, 1, 0],
            ['nl', 'Dutch', 'Nederlands', null, 1, 1],
        ], self::summary(self::ENGLISH));
    }

    public function testNoOtherLanguageIsInvented(): void
    {
        foreach (self::$registryAfterMigrating as $database => $rows) {
            $codes = array_column($rows, 'code');
            sort($codes);
            self::assertSame(['en', 'nl'], $codes, $database);
        }
    }

    public function testTheReplacedSettingsRowsAreGone(): void
    {
        foreach ([self::$fresh, self::$dutch, self::$english] as $install) {
            self::assertSame([], self::legacyRows($install), $install->database);
        }
    }

    public function testNotOneContentColumnIsTouched(): void
    {
        self::assertGreaterThan(0, self::$englishLabelsBefore[self::DUTCH], 'the bootstrap menu has English labels to compare');
        self::assertSame(self::$englishLabelsBefore[self::DUTCH], self::englishLabels(self::$dutch));
        self::assertTrue(
            (bool) self::$dutch->rows(
                'SELECT COUNT(*) AS c FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [self::DUTCH, 'nav_items', 'label_en']
            )[0]['c']
        );
    }

    // ------------------------------------------------------------- replaying

    public function testRunningItAgainAddsNoLanguageAndKeepsTheDefault(): void
    {
        self::$english->replay(self::REGISTRY);

        self::assertSame(self::$registryAfterMigrating[self::ENGLISH], self::registry(self::$english));
    }

    public function testRunningItAgainKeepsAnOwnersLaterChoice(): void
    {
        // The registry, once filled, is the owner's: a second run must not
        // put the default back where the old settings row said it was.
        $pdo = self::$dutch->pdo();
        $pdo->exec("UPDATE site_languages SET is_default = NULL WHERE code = 'nl'");
        $pdo->exec("UPDATE site_languages SET is_default = 1 WHERE code = 'en'");

        self::$dutch->replay(self::REGISTRY);

        self::assertSame(['en'], self::defaults(self::$dutch));
        self::assertCount(2, self::registry(self::$dutch));
    }

    public function testAStoredPrimaryItDoesNotKnowBecomesDutch(): void
    {
        $this->emptyTheRegistry(self::$dutch, 'de');

        self::$dutch->replay(self::REGISTRY);

        self::assertSame(['nl'], self::defaults(self::$dutch));
        self::assertSame(['nl', 'en'], array_column(self::registry(self::$dutch), 'code'));
        self::assertSame([], self::legacyRows(self::$dutch));
    }

    public function testNoStoredPrimaryAtAllBecomesDutch(): void
    {
        $this->emptyTheRegistry(self::$dutch, null);

        self::$dutch->replay(self::REGISTRY);

        self::assertSame(['nl'], self::defaults(self::$dutch));
        self::assertSame(['nl', 'en'], array_column(self::registry(self::$dutch), 'code'));
    }

    // ------------------------------------------------------ the Setup Wizard

    public function testARefusedWizardLeavesTheRegistryAlone(): void
    {
        [$status] = self::$fresh->runScript(
            'tests/Support/complete-setup-cli.php',
            [json_encode(['site_name' => '', 'primary_content_language' => 'en'], JSON_THROW_ON_ERROR)]
        );

        self::assertSame(1, $status);
        self::assertSame(['nl'], self::defaults(self::$fresh));
    }

    public function testTheWizardsWebsiteLanguageBecomesTheRegistryDefaultAndNothingElse(): void
    {
        [$status, $output] = self::$fresh->runScript(
            'tests/Support/complete-setup-cli.php',
            [json_encode([
                'site_name' => 'Taaltest',
                'primary_content_language' => 'en',
                'primary_color' => '#2B6CB0',
                'font_pairing' => 'poppins-inter',
                'button_shape' => 'rounded',
            ], JSON_THROW_ON_ERROR)]
        );

        self::assertSame(0, $status, 'the wizard failed: ' . $output);
        self::assertSame(['en'], self::defaults(self::$fresh));
        self::assertSame(['nl', 'en'], array_column(self::registry(self::$fresh), 'code'), 'choosing a default is not a reorder');
        self::assertSame([], self::legacyRows(self::$fresh), 'no settings row comes back beside the registry');
    }

    // ------------------------------------------------------------ internals

    private function emptyTheRegistry(ScratchInstall $install, ?string $storedPrimary): void
    {
        $pdo = $install->pdo();
        $pdo->exec('DELETE FROM site_languages');

        if ($storedPrimary !== null) {
            $pdo->prepare(
                'INSERT INTO site_settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())'
            )->execute(['primary_content_language', $storedPrimary]);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function registry(ScratchInstall $install): array
    {
        return array_map(
            static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'native_name' => (string) $row['native_name'],
                'is_default' => $row['is_default'] === null ? null : (int) $row['is_default'],
                'is_active' => (int) $row['is_active'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $install->rows('SELECT * FROM site_languages ORDER BY sort_order ASC, id ASC')
        );
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: ?int, 4: int, 5: int}> */
    private static function summary(string $database): array
    {
        return array_map(
            static fn (array $row): array => array_values($row),
            self::$registryAfterMigrating[$database]
        );
    }

    /** @return list<string> */
    private static function defaults(ScratchInstall $install): array
    {
        return array_column($install->rows('SELECT code FROM site_languages WHERE is_default = 1'), 'code');
    }

    /** @return list<array<string, mixed>> */
    private static function legacyRows(ScratchInstall $install): array
    {
        return $install->rows(
            'SELECT setting_key FROM site_settings WHERE setting_key IN (?, ?)',
            self::LEGACY_KEYS
        );
    }

    private static function englishLabels(ScratchInstall $install): int
    {
        return (int) $install->rows(
            "SELECT COUNT(*) AS c FROM nav_items WHERE label_en IS NOT NULL AND TRIM(label_en) <> ''"
        )[0]['c'];
    }
}

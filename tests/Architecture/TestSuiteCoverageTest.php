<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * phpunit.xml lists its suites file by file, which keeps the tiers readable
 * and spares every test class a pile of annotations — but a hand-kept list
 * rots the moment someone adds a test and forgets it. This test is what stops
 * that: a new test file that belongs to no domain suite fails the build, and
 * so does a path left behind by a rename.
 *
 * It also pins the two settings the database isolation depends on, so they
 * cannot be dropped from phpunit.xml by accident.
 *
 * See TESTING.md.
 */
final class TestSuiteCoverageTest extends TestCase
{
    /** Suites that answer "which part of the system", between them covering everything. */
    private const DOMAIN_SUITES = ['modules', 'blog', 'blocks', 'cms', 'shop', 'personalization', 'analytics'];

    /** Suites that answer "what does it need to run"; these deliberately cover only part. */
    private const TIER_SUITES = ['unit', 'contract', 'fast', 'http', 'migration'];

    /**
     * This directory is about the suite structure itself rather than about a
     * part of the site, so it has no domain of its own.
     */
    private const OUTSIDE_THE_DOMAINS = 'Architecture/';

    /**
     * What "touches the database" and "makes a request" look like in a test's
     * source. Spelled in pieces on purpose: written out in full they would
     * appear in THIS file, and it would report itself.
     */
    private const DATABASE_MARKER = 'App' . '\\' . 'Database';
    private const REQUEST_MARKER = 'TestEnvironment' . '::baseUrl()';

    /** The CMS-only tier's own web server; see Tests\Module\CmsOnlyHttpTest. */
    private const CMS_REQUEST_MARKER = 'TestEnvironment' . '::cmsOnlyBaseUrl()';

    private static function makesRequests(string $source): bool
    {
        return str_contains($source, self::REQUEST_MARKER)
            || str_contains($source, self::CMS_REQUEST_MARKER);
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function configuration(): SimpleXMLElement
    {
        $xml = simplexml_load_file(self::root() . '/phpunit.xml');
        self::assertNotFalse($xml, 'phpunit.xml could not be parsed');

        return $xml;
    }

    /** @return list<string> every test file in tests/, relative to tests/ */
    private static function allTestFiles(): array
    {
        $found = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::root() . '/tests', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($directory as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if (str_ends_with($path, 'Test.php')) {
                $found[] = substr($path, strpos($path, '/tests/') + strlen('/tests/'));
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The test files a suite resolves to, expanding <directory> the way
     * PHPUnit does (recursively, files ending in Test.php).
     *
     * @return list<string> relative to tests/
     */
    private static function filesIn(SimpleXMLElement $configuration, string $suite): array
    {
        $entries = [];

        foreach ($configuration->testsuites->testsuite as $candidate) {
            if ((string) $candidate['name'] !== $suite) {
                continue;
            }

            foreach ($candidate->file as $file) {
                $entries[] = ['file', trim((string) $file)];
            }

            foreach ($candidate->directory as $directory) {
                $entries[] = ['directory', trim((string) $directory)];
            }
        }

        self::assertNotSame([], $entries, "phpunit.xml has no suite named \"{$suite}\"");

        $files = [];

        foreach ($entries as [$kind, $path]) {
            $absolute = self::root() . '/' . $path;

            if ($kind === 'file') {
                self::assertFileExists($absolute, "phpunit.xml suite \"{$suite}\" lists a file that no longer exists");
                $files[] = substr($path, strlen('tests/'));
                continue;
            }

            self::assertDirectoryExists(
                $absolute,
                "phpunit.xml suite \"{$suite}\" lists a directory that no longer exists"
            );

            $prefix = ltrim(substr($path, strlen('tests')), '/');
            if ($prefix !== '') {
                $prefix .= '/';
            }

            foreach (self::allTestFiles() as $candidateFile) {
                if ($prefix === '' || str_starts_with($candidateFile, $prefix)) {
                    $files[] = $candidateFile;
                }
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    public function testTheDefaultSuiteIsTheCompleteOne(): void
    {
        $configuration = self::configuration();

        $this->assertSame(
            'full',
            (string) $configuration['defaultTestSuite'],
            'a plain `phpunit` must run the full suite, and every file in it exactly once'
        );

        $this->assertSame(
            self::allTestFiles(),
            self::filesIn($configuration, 'full'),
            'the "full" suite must contain every test file'
        );
    }

    public function testTheBootstrapThatIsolatesTheDatabaseIsStillWiredUp(): void
    {
        $this->assertSame(
            'tests/bootstrap.php',
            (string) self::configuration()['bootstrap'],
            'without this bootstrap the suite would connect to the development database'
        );
    }

    public function testEveryTestFileBelongsToADomainSuite(): void
    {
        $configuration = self::configuration();

        $covered = [];
        foreach (self::DOMAIN_SUITES as $suite) {
            $covered = array_merge($covered, self::filesIn($configuration, $suite));
        }

        $expected = array_filter(
            self::allTestFiles(),
            static fn (string $file): bool => !str_starts_with($file, self::OUTSIDE_THE_DOMAINS)
        );

        $orphans = array_values(array_diff($expected, $covered));

        $this->assertSame(
            [],
            $orphans,
            'these test files are in no domain suite, so a targeted run would never reach them — '
                . 'add each to blocks/cms/shop/personalization/analytics in phpunit.xml: '
                . implode(', ', $orphans)
        );
    }

    public function testEverySuiteOnlyListsPathsThatExist(): void
    {
        $configuration = self::configuration();

        foreach ([...self::DOMAIN_SUITES, ...self::TIER_SUITES] as $suite) {
            // filesIn() asserts existence for every entry it walks.
            $this->assertNotSame([], self::filesIn($configuration, $suite), "suite \"{$suite}\" resolved to nothing");
        }
    }

    /**
     * The whole point of these two tiers is that they run anywhere, in a
     * second, without Docker. A test that opens a connection would take that
     * away — quietly, because a database usually happens to be there.
     */
    public function testTheUnitAndContractTiersTouchNoDatabaseAndNoWebServer(): void
    {
        $configuration = self::configuration();

        foreach (['unit', 'contract'] as $suite) {
            foreach (self::filesIn($configuration, $suite) as $file) {
                $source = (string) file_get_contents(self::root() . '/tests/' . $file);

                $this->assertStringNotContainsString(
                    self::DATABASE_MARKER,
                    $source,
                    "tests/{$file} is in the \"{$suite}\" tier but opens a database connection"
                );

                $this->assertFalse(
                    self::makesRequests($source),
                    "tests/{$file} is in the \"{$suite}\" tier but makes HTTP requests"
                );
            }
        }
    }

    /** Every HTTP test really is one, and every test that makes requests is listed. */
    public function testTheHttpTierListsExactlyTheTestsThatMakeRequests(): void
    {
        $requesting = [];

        foreach (self::allTestFiles() as $file) {
            $source = (string) file_get_contents(self::root() . '/tests/' . $file);

            if (self::makesRequests($source)) {
                $requesting[] = $file;
            }
        }

        $this->assertSame(
            $requesting,
            self::filesIn(self::configuration(), 'http'),
            'the "http" suite must list exactly the tests that talk to a web server'
        );
    }
}

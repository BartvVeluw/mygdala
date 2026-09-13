<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\CtaBandContent;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What a block looks like the moment an editor adds one — and specifically
 * that it does not assume the routes of the site this CMS grew out of.
 *
 * The CTA Band used to be created with `primary_url = '/contact.php'`. On
 * Van Veluw Laserdesign that is a real page; anywhere else it is a 404 that
 * ships inside every new band until somebody notices. A starting value has
 * to work on every installation, which leaves the site root: the one URL
 * every install answers.
 *
 * Stored bands are a different matter entirely and are left alone —
 * Tests\Install\LegacyUpgradeTest::testStoredCtaBandsKeepTheirOwnDestinations
 * proves that on an installation that has them.
 *
 * Blocks are created on a throwaway page of this test's own, the same
 * convention as Tests\Service\SectionRegistryTest, so nothing here can reach
 * real site content.
 */
final class GenericBlockDefaultsTest extends TestCase
{
    private const TEST_KEY = '__test_generic_defaults__';

    /**
     * The fixed URLs of this one site. A block that ships one as its
     * starting value is guessing at somebody else's sitemap.
     */
    private const LEGACY_ROUTES = [
        '/contact.php', 'contact.php',
        '/diensten.php', 'diensten.php',
        '/portfolio.php', 'portfolio.php',
        '/over-mij.php', 'over-mij.php',
    ];

    /**
     * Words only the copy of this one site contains — its name, its city, its
     * photographs and its machines. Matched case-insensitively.
     */
    private const SITE_COPY = [
        'Van Veluw',
        'vanveluw',
        'Laserdesign',
        'Nijmegen',
        'hero-collage',
        'Lasergravure',
        'MOPA',
    ];

    private PageRepository $pages;
    private PageSectionRepository $sections;
    private int $pageId;

    /** @var list<int> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->removeTestPage();

        $this->pageId = $this->pages->create([
            'content_key' => self::TEST_KEY,
            'slug' => self::TEST_KEY,
            'title' => 'Generieke blokstandaarden',
            'status' => 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            $row = $this->sections->findById($id);
            if ($row === null) {
                continue;
            }
            SectionRegistry::delete($row, $this->sections);
        }
        $this->created = [];

        $this->removeTestPage();
        CtaBandContent::clearCache();
    }

    public function testANewCtaBandDoesNotPointAtThisSitesContactPage(): void
    {
        $row = $this->newCtaBandRow();

        foreach (['primary_url', 'secondary_url'] as $column) {
            $this->assertNotContains(
                (string) ($row[$column] ?? ''),
                self::LEGACY_ROUTES,
                "A new CTA Band starts out pointing at a page only this site has ({$column})."
            );
        }
    }

    public function testANewCtaBandPointsSomewhereEveryInstallationAnswers(): void
    {
        // The block always renders its primary button, so the starting value
        // cannot simply be empty — it has to be a URL that resolves.
        $this->assertSame('/', (string) $this->newCtaBandRow()['primary_url']);
    }

    public function testNoBlockShipsAFixedRouteOfThisSiteAsAStartingValue(): void
    {
        // Read at the source rather than by creating every block type: some
        // need a module, a file or a parent row, and the rule is about what
        // is written down, not about what happens to be installed.
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Service/Blocks/*.php') ?: [] as $file) {
            $body = (string) file_get_contents($file);

            $start = strpos($body, 'public function create(');
            if ($start === false) {
                continue;
            }

            $create = substr($body, $start);

            foreach (self::LEGACY_ROUTES as $route) {
                if (str_contains($create, "'" . $route . "'") || str_contains($create, '"' . $route . '"')) {
                    $offenders[] = basename($file) . ' → ' . $route;
                }
            }
        }

        $this->assertSame([], $offenders, 'A block definition seeds a route only this site has.');
    }

    public function testNoContentOrBlockClassCarriesThisSitesCopyInARuntimeString(): void
    {
        // Every string a content class or a block definition can hand out —
        // a frontend fallback, a field-level fallback or an editor starting
        // value — is a literal in one of these files. Comments and docblocks
        // are not tokens of that kind, so history may still be told there.
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/src/Service/*Content.php') ?: [],
            glob($root . '/src/Service/Blocks/*.php') ?: []
        );

        $offenders = [];

        foreach ($files as $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }

                $literal = trim($token[1], '\'"');

                foreach (self::SITE_COPY as $needle) {
                    if (stripos($literal, $needle) !== false) {
                        $offenders[] = basename($file) . ':' . $token[2] . ' → ' . $needle;
                    }
                }

                if (in_array($literal, self::LEGACY_ROUTES, true)) {
                    $offenders[] = basename($file) . ':' . $token[2] . ' → ' . $literal;
                }
            }
        }

        $this->assertSame([], $offenders, 'A content class or block definition still carries copy of the site this CMS grew out of.');
    }

    /**
     * @return array<string, mixed>
     */
    private function newCtaBandRow(): array
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('cta_band', self::TEST_KEY);
        $this->created[] = $this->sections->create($this->pageId, self::TEST_KEY, 'cta_band', $sectionKey, $sectionId);

        $db = Database::connection();
        $statement = $db->prepare('SELECT * FROM cta_bands WHERE id = :id');
        $statement->execute(['id' => $sectionId]);
        $row = $statement->fetch();

        $this->assertNotFalse($row, 'The CTA Band block created no content row.');

        return $row;
    }

    private function removeTestPage(): void
    {
        $db = Database::connection();

        $statement = $db->prepare('SELECT id FROM pages WHERE content_key = :key');
        $statement->execute(['key' => self::TEST_KEY]);
        $row = $statement->fetch();

        if ($row !== false) {
            $delete = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $delete->execute(['id' => (int) $row['id']]);

            $delete = $db->prepare('DELETE FROM pages WHERE id = :id');
            $delete->execute(['id' => (int) $row['id']]);
        }

        // Only ever this test's own throwaway page_slug — an exact match,
        // never a LIKE pattern, in which `_` matches any character.
        $delete = $db->prepare('DELETE FROM cta_bands WHERE page_slug = :key');
        $delete->execute(['key' => self::TEST_KEY]);

        PageContent::clearCache();
    }
}

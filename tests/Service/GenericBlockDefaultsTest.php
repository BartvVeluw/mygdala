<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\CtaBandContent;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use PHPUnit\Framework\Attributes\Group;
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
 * Stored bands are a different matter entirely and are left alone — the last
 * test here is the proof.
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

    #[Group('migration-backfill')]
    public function testStoredCtaBandsWereNotRewritten(): void
    {
        $db = Database::connection();
        $row = $db->query(
            "SELECT primary_url FROM cta_bands WHERE page_slug = 'index' AND section_key = 'main'"
        )->fetch();

        if ($row === false) {
            $this->markTestSkipped('This database has no migrated homepage CTA band to check.');
        }

        $this->assertSame(
            'contact.php',
            (string) $row['primary_url'],
            'Changing the default for new blocks must not touch a band an editor already owns.'
        );
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

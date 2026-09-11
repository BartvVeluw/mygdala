<?php

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\PageContent;
use App\Service\SectionRegistry;
use Phinx\Db\Adapter\AdapterFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\TestEnvironment;

/**
 * What must happen when `page_sections` names a block type this CMS does not
 * know.
 *
 * It is not hypothetical. On 2026-09-07 "Gerelateerde producten" was briefly
 * a content block; it was rebuilt as the automatic section on a product page
 * before it was ever committed, and its code and tables went with it. The one
 * page_sections row an editor had already placed through the page builder did
 * not: nothing rolls back a row a person created, and no later migration ever
 * looked at it, because each of those selects on a section_type it knows.
 * From then on that page died mid-render — SectionRegistry threw, the footer
 * never printed, and with display_errors on the visitor got a stack trace.
 *
 * Everything below is asserted against a page with a RANDOM slug. That is the
 * point: the page it happened to hit was the one page an editor had built by
 * hand, so a fixture on `contact` or `index` would prove nothing about the
 * case that actually breaks.
 *
 *   1. the retirement migration reaches the block on such a page, leaves
 *      every other block alone, and does the same thing twice;
 *   2. the public page renders around an unknown block instead of dying on
 *      it;
 *   3. the page builder shows the editor that the block is there and
 *      unsupported, without going near the type it names.
 *
 * @see db/migrations/20260909100000_retire_the_never_shipped_related_products_block.php
 * @see CONTENT-BLOCKS.md, "Een blok verwijderen of vervangen"
 */
class UnknownContentBlockTest extends TestCase
{
    /**
     * The retired type this project really did leave behind — the migration
     * under test selects on exactly this string.
     */
    private const RETIRED_TYPE = 'related_products';

    /**
     * A type that is not registered and never was, for the rendering and
     * page-builder assertions: those are about ANY unknown type, and pinning
     * them to the retired one would quietly stop testing the general case the
     * day that string means something again.
     */
    private const UNKNOWN_TYPE = 'zz_never_registered_block';

    /**
     * Slug prefix of every page this test creates. Made of `[a-z0-9-]` so
     * .htaccess actually routes it (an underscore would 404 in Apache before
     * pagina.php ever ran), and hyphen-only so the cleanup's LIKE carries no
     * wildcard — `_` matches any character in SQL.
     */
    private const SLUG_PREFIX = 'zz-unknown-block-';

    private PageRepository $pages;
    private PageSectionRepository $sections;

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();

        $this->removeLeftovers();
    }

    protected function tearDown(): void
    {
        $this->removeLeftovers();

        PageContent::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* 1. The migration, on a page nobody knew the name of                 */
    /* ------------------------------------------------------------------ */

    public function testTheMigrationRetiresTheBlockOnAUserCreatedPage(): void
    {
        [$slug, $pageId] = $this->createPage();

        $first = $this->attachRichText($pageId, $slug, '<p>eerste blok</p>');
        $stale = $this->attachRaw($pageId, $slug, self::RETIRED_TYPE, 'custom-abcd1234');
        $last = $this->attachRichText($pageId, $slug, '<p>laatste blok</p>');

        $before = $this->sections->findById($stale);
        $this->assertNotNull($before);

        $this->runRetirementMigration();

        $after = $this->sections->findById($stale);
        $this->assertNotNull($after, 'the row must be preserved, not deleted');
        $this->assertSame(0, (int) $after['is_active'], 'the unrenderable block must be off');

        // Nothing about the instance itself may be rewritten: its key, the
        // content row it points at and its place in the list are what an
        // editor would need to recognise it.
        $this->assertSame('custom-abcd1234', (string) $after['section_key']);
        $this->assertSame((int) $before['section_id'], (int) $after['section_id']);
        $this->assertSame((int) $before['sort_order'], (int) $after['sort_order']);

        // The blocks around it are untouched.
        foreach ([$first, $last] as $id) {
            $row = $this->sections->findById($id);
            $this->assertNotNull($row);
            $this->assertSame(1, (int) $row['is_active'], 'a registered block must stay active');
        }

        $this->assertSame(
            ['rich_text', self::RETIRED_TYPE, 'rich_text'],
            array_map(
                static fn (array $row): string => (string) $row['section_type'],
                $this->sections->findForPage($pageId)
            ),
            'the page keeps its order'
        );
    }

    public function testTheMigrationDoesNothingTheSecondTime(): void
    {
        [$slug, $pageId] = $this->createPage();
        $stale = $this->attachRaw($pageId, $slug, self::RETIRED_TYPE, 'custom-idempotent');

        $this->runRetirementMigration();
        $afterFirst = $this->sections->findById($stale);
        $this->assertNotNull($afterFirst);

        $this->runRetirementMigration();
        $afterSecond = $this->sections->findById($stale);

        $this->assertNotNull($afterSecond, 'a second run must not delete anything');
        $this->assertSame($afterFirst, $afterSecond, 'a second run must change nothing at all');

        $this->assertCount(
            1,
            $this->sections->findForPage($pageId),
            'a second run must not duplicate the row'
        );
    }

    public function testTheMigrationSelectsOnTheTypeAndNotOnAPageSlug(): void
    {
        // Comments stripped: this is about what the migration DOES, and its
        // docblock is free to name the things it deliberately does not use.
        $source = php_strip_whitespace(
            dirname(__DIR__, 2)
            . '/db/migrations/20260909100000_retire_the_never_shipped_related_products_block.php'
        );

        // The whole failure mode this migration exists for is a block on a
        // page whose slug nobody knew when the migration was written.
        foreach (["'index'", "'contact'", "'diensten'", "'portfolio'", "'over-mij'", "'shop'"] as $knownPage) {
            $this->assertStringNotContainsString(
                $knownPage,
                $source,
                'a content-block migration must not select on a page slug'
            );
        }

        $this->assertStringContainsString('WHERE section_type', $source);

        // A historical migration that read the live registry would do
        // something different every time it is replayed.
        $this->assertStringNotContainsString('BlockDefinitions', $source);
    }

    /* ------------------------------------------------------------------ */
    /* 2. The public page                                                  */
    /* ------------------------------------------------------------------ */

    public function testAPublicPageRendersAroundAnUnknownBlock(): void
    {
        $this->skipUnlessServerReachable();

        [$slug, $pageId] = $this->createPage('published');

        $this->attachRichText($pageId, $slug, '<p>Blok boven de onbekende sectie</p>');
        $this->attachRaw($pageId, $slug, self::UNKNOWN_TYPE, 'custom-public');
        $this->attachRichText($pageId, $slug, '<p>Blok onder de onbekende sectie</p>');

        $response = $this->request('/' . $slug);

        $this->assertNotNull($response);
        $this->assertSame(200, $response['status'], 'one stale row must not take the page down');

        $this->assertStringContainsString('Blok boven de onbekende sectie', $response['body']);
        $this->assertStringContainsString('Blok onder de onbekende sectie', $response['body']);

        // The page finished: the closing tag is proof the render did not stop
        // halfway.
        $this->assertStringContainsString('</html>', $response['body']);

        // Nothing about the unknown block reaches the visitor — not the type,
        // not a class name, not a trace.
        $leaks = [self::UNKNOWN_TYPE, 'SectionRegistry', 'InvalidArgumentException', 'Fatal error', 'Stack trace'];
        foreach ($leaks as $leak) {
            $this->assertStringNotContainsString($leak, $response['body'], 'must not leak "' . $leak . '"');
        }
    }

    public function testTheUnknownBlockIsSkippedAndNotDeleted(): void
    {
        $this->skipUnlessServerReachable();

        [$slug, $pageId] = $this->createPage('published');
        $stale = $this->attachRaw($pageId, $slug, self::UNKNOWN_TYPE, 'custom-preserved');

        $this->request('/' . $slug);

        $row = $this->sections->findById($stale);
        $this->assertNotNull($row, 'rendering must never remove a section');
        $this->assertSame(1, (int) $row['is_active'], 'rendering must not change the row either');
    }

    /* ------------------------------------------------------------------ */
    /* 3. What the editor sees                                             */
    /* ------------------------------------------------------------------ */

    public function testThePageBuilderFlagsAnUnsupportedBlockWithoutReachingForIt(): void
    {
        [$slug, $pageId] = $this->createPage();
        $stale = $this->attachRaw($pageId, $slug, self::UNKNOWN_TYPE, 'custom-admin');

        $row = $this->sections->findById($stale);
        $this->assertNotNull($row);

        // The condition admin/page.php branches on.
        $this->assertFalse(SectionRegistry::exists(self::UNKNOWN_TYPE));

        // Everything the row could otherwise be used to reach stays shut: no
        // editor is linked, no delete is offered, and no table name is ever
        // derived from a type that came out of the database.
        $this->assertSame([], SectionRegistry::editLinks($row));
        $this->assertNull(SectionRegistry::editUrl($row));
        $this->assertFalse(SectionRegistry::isDeletable(self::UNKNOWN_TYPE));
        $this->assertNull(SectionRegistry::contentTable(self::UNKNOWN_TYPE));
        $this->assertNull(SectionRegistry::kind(self::UNKNOWN_TYPE));
        $this->assertNull(SectionRegistry::note(self::UNKNOWN_TYPE));

        // ...and the screen still describes the row instead of crashing on it.
        $this->assertSame(self::UNKNOWN_TYPE, SectionRegistry::instanceLabel($row));

        $builder = file_get_contents(dirname(__DIR__, 2) . '/admin/page.php');
        $this->assertIsString($builder);

        $this->assertStringContainsString('SectionRegistry::exists($sectionType)', $builder);
        $this->assertStringContainsString('page.not_supported', $builder);
        $this->assertStringContainsString('page.blok_kon_geladen_pagina', $builder);
    }

    /* ------------------------------------------------------------------ */
    /* 4. Registry coverage, on a fixture rather than on real content      */
    /* ------------------------------------------------------------------ */

    public function testEveryTypeOnAFixturePageResolvesExceptTheDeliberateUnknown(): void
    {
        [$slug, $pageId] = $this->createPage();

        // One row per registered type, addressed exactly the way the CMS
        // stores them, plus the one row that is meant not to resolve.
        foreach (BlockDefinitions::types() as $type) {
            $this->attachRaw($pageId, $slug, $type, 'custom-coverage');
        }
        $this->attachRaw($pageId, $slug, self::UNKNOWN_TYPE, 'custom-coverage');

        $unresolved = [];
        foreach ($this->sections->findForPage($pageId) as $row) {
            $type = (string) $row['section_type'];
            if (!BlockDefinitions::has($type)) {
                $unresolved[] = $type;
            }
        }

        $this->assertSame([self::UNKNOWN_TYPE], $unresolved);
    }

    public function testTheRetiredTypeIsNotRegistered(): void
    {
        $this->assertFalse(BlockDefinitions::has(self::RETIRED_TYPE));
        $this->assertFalse(SectionRegistry::exists(self::RETIRED_TYPE));
    }

    /* ------------------------------------------------------------------ */
    /* Fixture                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * A page with a slug this test invents on the spot — the whole point of
     * the fixture. Randomised per run so nothing can accidentally start
     * depending on the name.
     *
     * @return array{0: string, 1: int} slug, page id
     */
    private function createPage(string $status = 'draft'): array
    {
        $slug = self::SLUG_PREFIX . bin2hex(random_bytes(4));

        $pageId = $this->pages->create([
            'content_key' => $slug,
            'slug' => $slug,
            'title' => 'Onbekend-blok-testpagina',
            'status' => $status,
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);

        PageContent::clearCache();

        return [$slug, $pageId];
    }

    private function attachRichText(int $pageId, string $slug, string $html): int
    {
        [$sectionId, $sectionKey] = SectionRegistry::create('rich_text', $slug);

        $stmt = Database::connection()
            ->prepare('UPDATE rich_text_sections SET content_html = :html WHERE id = :id');
        $stmt->execute(['html' => $html, 'id' => $sectionId]);

        return $this->sections->create($pageId, $slug, 'rich_text', $sectionKey, $sectionId);
    }

    /**
     * Attaches a page_sections row for a type the registry does not have to
     * know — the exact shape a retired or never-shipped block leaves behind.
     * page_sections has UNIQUE(section_type, section_id), so every fixture
     * row gets a section_id of its own, far above anything a content table
     * has reached.
     */
    private function attachRaw(int $pageId, string $slug, string $type, string $sectionKey): int
    {
        static $next = 800000;

        return $this->sections->create($pageId, $slug, $type, $sectionKey, ++$next);
    }

    /**
     * Runs the migration under test through Phinx itself, against the test
     * database tests/bootstrap.php has already pinned this process to — the
     * same code path `phinx migrate` takes, rather than a copy of its SQL in
     * a test that would then prove nothing about the file that ships.
     */
    private function runRetirementMigration(): void
    {
        require_once dirname(__DIR__, 2)
            . '/db/migrations/20260909100000_retire_the_never_shipped_related_products_block.php';

        $adapter = AdapterFactory::instance()->getAdapter('mysql', [
            'adapter' => 'mysql',
            'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
            'name' => TestEnvironment::databaseName(),
            'user' => $_ENV['DB_USERNAME'] ?? '',
            'pass' => $_ENV['DB_PASSWORD'] ?? '',
            'port' => $_ENV['DB_PORT'] ?? '3306',
            'charset' => 'utf8mb4',
        ]);
        $adapter->setOutput(new NullOutput());
        $adapter->connect();

        $migration = new \RetireTheNeverShippedRelatedProductsBlock('test', 20260909100000);
        $migration->setAdapter($adapter);
        $migration->up();

        $adapter->disconnect();
    }

    private function skipUnlessServerReachable(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
    }

    /**
     * @return array{status: int, body: string}|null null when the request could not be made at all
     */
    private function request(string $path): ?array
    {
        $context = stream_context_create([
            'http' => ['ignore_errors' => true, 'timeout' => 5, 'follow_location' => 0],
        ]);

        $body = @file_get_contents(TestEnvironment::baseUrl() . $path, false, $context);
        if ($body === false && !isset($http_response_header)) {
            return null;
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Removes every page this test class has ever made, including the ones a
     * run that died halfway left behind — which is why it also runs before
     * the fixture is built. The LIKE is safe: SLUG_PREFIX is hyphen-only, so
     * it carries no `_` wildcard.
     */
    private function removeLeftovers(): void
    {
        $db = Database::connection();

        $stmt = $db->prepare('SELECT id, slug FROM pages WHERE slug LIKE :prefix');
        $stmt->execute(['prefix' => self::SLUG_PREFIX . '%']);

        foreach ($stmt->fetchAll() as $page) {
            $pageId = (int) $page['id'];
            $slug = (string) $page['slug'];

            $delete = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $delete->execute(['id' => $pageId]);

            $delete = $db->prepare('DELETE FROM rich_text_sections WHERE page_slug = :slug');
            $delete->execute(['slug' => $slug]);

            $delete = $db->prepare('DELETE FROM pages WHERE id = :id');
            $delete->execute(['id' => $pageId]);
        }
    }
}

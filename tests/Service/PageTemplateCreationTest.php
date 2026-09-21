<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Service\Blocks\BlockDefinitions;
use App\Service\PageContent;
use App\Service\PageSeo;
use App\Service\PageService;
use App\Service\PageTemplates\PageTemplateDefinition;
use App\Service\PageTemplates\PageTemplateInstaller;
use App\Service\PageTemplates\PageTemplates;
use App\Service\SectionRegistry;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;

/**
 * What a page template actually BUILDS: an ordinary CMS content page with a
 * head start, created in one transaction, and carrying no trace of the
 * template afterwards.
 *
 * Integration tests against the real (test) database, same convention as
 * Tests\Service\PageServiceTest — PageRepository is a thin PDO wrapper with
 * no mocking seam. Every page created here is tracked by id and removed
 * again in tearDown() by exact id, never by a LIKE pattern.
 *
 * The catalogue rules (which templates exist, what they may name) are
 * Tests\Service\PageTemplateRegistryTest. See PAGE-TEMPLATES.md.
 */
final class PageTemplateCreationTest extends TestCase
{
    private const PREFIX = 'zz-tpl-test-';

    private PageRepository $pages;

    private PageSectionRepository $sections;

    /** @var list<int> */
    private array $createdPageIds = [];

    /** @var list<string> */
    private array $createdContentKeys = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->sections = new PageSectionRepository();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();

        foreach ($this->createdPageIds as $pageId) {
            $stmt = $db->prepare('DELETE FROM page_sections WHERE page_id = :id');
            $stmt->execute(['id' => $pageId]);

            $stmt = $db->prepare('DELETE FROM pages WHERE id = :id');
            $stmt->execute(['id' => $pageId]);
        }

        // Every block's own content row is keyed by the page's content_key.
        // Deleted per table from the registry's own trusted metadata, and by
        // an exact key match — never a LIKE pattern, where "_" would match
        // any character and could reach real content.
        foreach ($this->createdContentKeys as $contentKey) {
            foreach (BlockDefinitions::all() as $definition) {
                $table = $definition->contentTable();
                if ($table === null) {
                    continue;
                }

                // The block's words per language first, or they stay behind as orphans.
                \Tests\Support\BlockTextFixture::removeForPage($table, $contentKey);
                $stmt = $db->prepare('DELETE FROM ' . $table . ' WHERE page_slug = :key');
                $stmt->execute(['key' => $contentKey]);
            }
        }

        $this->createdPageIds = [];
        $this->createdContentKeys = [];

        PageContent::clearCache();
    }

    /**
     * Creates a page from $templateKey exactly the way
     * api/admin/create-page.php does: slug and content key resolved through
     * App\Service\PageService first, then handed to the installer.
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function createFromTemplate(string $templateKey, string $slug, string $status = PageContent::STATUS_DRAFT): array
    {
        $contentKey = PageService::generateContentKey($this->pages, $slug);

        $pageId = PageTemplateInstaller::install(PageTemplates::get($templateKey), [
            'content_key' => $contentKey,
            'slug' => $slug,
            'status' => $status,
        ], [
            \App\Service\PageLocalization::defaultLanguage() => [
                \App\Service\PageTranslation::TITLE => 'Paginasjabloon-test ' . $slug,
            ],
        ]);

        $this->createdPageIds[] = $pageId;
        $this->createdContentKeys[] = $contentKey;

        $page = $this->pages->findById($pageId);
        self::assertIsArray($page);

        return [$pageId, $page];
    }

    /** @return list<string> the page's block types, in render order */
    private function sectionTypes(int $pageId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['section_type'],
            $this->sections->findForPage($pageId)
        );
    }

    // ---------------------------------------------------------------
    // Every template builds what it promises
    // ---------------------------------------------------------------

    /**
     * The heart of it: for each of the six templates, the page that comes out
     * carries exactly the blocks the definition names, in the order it names
     * them.
     */
    public function testEveryTemplateCreatesItsBlocksInOrder(): void
    {
        foreach (PageTemplates::all() as $key => $template) {
            [$pageId] = $this->createFromTemplate($key, self::PREFIX . $key);

            self::assertSame(
                $template->blocks(),
                $this->sectionTypes($pageId),
                'Template "' . $key . '" did not build the blocks it declares.'
            );
        }
    }

    /**
     * sort_order is a contiguous 0..n-1 run — the invariant the whole page
     * builder rests on (see PageSectionRepository::resequence()). A template
     * must leave a page in exactly the state hand-building it would.
     */
    public function testCreatedSectionsAreNumberedContiguouslyFromZero(): void
    {
        [$pageId] = $this->createFromTemplate('landing', self::PREFIX . 'ordering');

        $orders = array_map(
            static fn (array $row): int => (int) $row['sort_order'],
            $this->sections->findForPage($pageId)
        );

        self::assertSame(range(0, count($orders) - 1), $orders);
    }

    /**
     * A new "Lege pagina" has its Paginakop and nothing else — no text block,
     * neither attached nor merely created. Everything below the heading is
     * the editor's own choice.
     */
    public function testBlankTemplateCreatesAPageWithOnlyItsPageHero(): void
    {
        [$pageId, $page] = $this->createFromTemplate('blank', self::PREFIX . 'blank');

        self::assertSame(['page_hero'], $this->sectionTypes($pageId));

        $textTable = BlockDefinitions::get('rich_text')?->contentTable();
        self::assertIsString($textTable);

        $stmt = Database::connection()->prepare('SELECT COUNT(*) AS total FROM ' . $textTable . ' WHERE page_slug = :key');
        $stmt->execute(['key' => (string) $page['content_key']]);

        self::assertSame(0, (int) $stmt->fetch()['total'], 'the blank template left a text block behind');
    }

    /**
     * Every block the template placed is a real, active instance the page
     * builder will show and the frontend will render — not a placeholder row
     * some later step still has to fix up.
     */
    public function testCreatedSectionsAreActiveAndPointAtRealContentRows(): void
    {
        [$pageId, $page] = $this->createFromTemplate('about', self::PREFIX . 'rows');

        $contentKey = (string) $page['content_key'];

        foreach ($this->sections->findForPage($pageId) as $row) {
            $type = (string) $row['section_type'];

            self::assertSame(1, (int) $row['is_active'], 'Block "' . $type . '" was created hidden.');
            self::assertSame($contentKey, (string) $row['page_slug']);

            $table = BlockDefinitions::get($type)?->contentTable();
            self::assertIsString($table, 'Block "' . $type . '" has no content table.');

            $stmt = Database::connection()->prepare(
                'SELECT COUNT(*) AS total FROM ' . $table . ' WHERE id = :id'
            );
            $stmt->execute(['id' => (int) $row['section_id']]);

            self::assertSame(1, (int) $stmt->fetch()['total'], 'Block "' . $type . '" has no content row.');
        }
    }

    // ---------------------------------------------------------------
    // The result is an ordinary CMS page
    // ---------------------------------------------------------------

    /**
     * Structurally ordinary: not a system page, no fixed route, served at
     * /<slug> by pagina.php like every other content page.
     */
    public function testTemplateCreatedPageIsAnOrdinaryContentPage(): void
    {
        [, $page] = $this->createFromTemplate('services', self::PREFIX . 'ordinary');

        self::assertSame(0, (int) $page['is_system']);
        self::assertNull($page['route_path']);
        self::assertFalse(PageContent::hasOwnTemplate($page));
        self::assertFalse(PageContent::isRouteBound($page));
        self::assertFalse(PageContent::isSiteRoot($page));
        self::assertFalse(PageContent::isProtected($page));
        self::assertSame('/' . self::PREFIX . 'ordinary', PageContent::publicUrl($page));
    }

    /**
     * The whole architectural rule in one test: nothing anywhere records
     * which template a page came from. Every column of the row is checked,
     * so adding a `template` column later would fail here rather than
     * quietly creating a page type.
     */
    public function testNothingOnThePageRecordsWhichTemplateCreatedIt(): void
    {
        [, $page] = $this->createFromTemplate('landing', self::PREFIX . 'no-identity');

        foreach ($page as $column => $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach (PageTemplates::keys() as $templateKey) {
                if ($templateKey === 'blank' || $templateKey === 'standard') {
                    // Words too generic to assert on; the structural check
                    // below is what actually matters for those.
                    continue;
                }

                self::assertStringNotContainsStringIgnoringCase(
                    $templateKey,
                    $value,
                    'Column "' . $column . '" appears to record the template key.'
                );
            }
        }

        // Deliberately not a full column list: an unrelated migration must
        // not fail this test, but a column that remembers the template must.
        foreach (array_keys($page) as $column) {
            self::assertStringNotContainsStringIgnoringCase(
                'template',
                (string) $column,
                'The pages table gained a column that looks like a template identity.'
            );
            self::assertStringNotContainsStringIgnoringCase(
                'sjabloon',
                (string) $column,
                'The pages table gained a column that looks like a template identity.'
            );
        }

        // Nor does the attachment table: page_sections records position, not
        // provenance.
        $sectionRow = $this->sections->findForPage($this->createdPageIds[array_key_last($this->createdPageIds)])[0] ?? [];
        foreach (array_keys($sectionRow) as $column) {
            self::assertStringNotContainsStringIgnoringCase(
                'template',
                (string) $column,
                'page_sections gained a column that looks like a template identity.'
            );
        }
    }

    /**
     * A page created from a template must be as deletable as any other: the
     * editor can throw away everything the template made, including the page
     * itself.
     */
    public function testEveryGeneratedBlockCanBeDeletedAgain(): void
    {
        [$pageId] = $this->createFromTemplate('contact', self::PREFIX . 'deletable');

        foreach ($this->sections->findForPage($pageId) as $row) {
            $type = (string) $row['section_type'];

            self::assertTrue(SectionRegistry::isDeletable($type), 'Block "' . $type . '" cannot be deleted.');

            SectionRegistry::delete($row, $this->sections);
        }

        self::assertSame([], $this->sections->findForPage($pageId));

        $page = $this->pages->findById($pageId);
        self::assertIsArray($page);

        PageService::delete($page);

        self::assertNull($this->pages->findById($pageId));
    }

    /**
     * And the reverse: the editor can keep building on it with the very same
     * "+ Sectie toevoegen" the page builder offers, because the page has no
     * special state.
     */
    public function testMoreBlocksCanBeAddedToATemplateCreatedPage(): void
    {
        [$pageId, $page] = $this->createFromTemplate('standard', self::PREFIX . 'extendable');

        $available = SectionRegistry::availableForPage($page, $this->sections);
        self::assertArrayHasKey('faq', $available);

        [$sectionId, $sectionKey] = SectionRegistry::create('faq', (string) $page['content_key']);
        $this->sections->create($pageId, (string) $page['content_key'], 'faq', $sectionKey, $sectionId);

        self::assertSame(['page_hero', 'rich_text', 'faq'], $this->sectionTypes($pageId));
    }

    // ---------------------------------------------------------------
    // Atomicity
    // ---------------------------------------------------------------

    /**
     * A template that fails halfway must leave NOTHING: no page row, no
     * attached sections, and no block content row either.
     *
     * The stub below names the page hero twice. That block is stored per
     * page rather than per instance, so the second create() returns the same
     * content row id as the first, and attaching it a second time violates
     * page_sections' UNIQUE(section_type, section_id) — a genuine database
     * failure raised after the page and its first section were already
     * written.
     */
    public function testAFailureWhileBuildingSectionsRollsTheWholePageBack(): void
    {
        $template = new class () extends PageTemplateDefinition {
            public function key(): string
            {
                return 'zz-broken-stub';
            }

            public function label(): string
            {
                return 'Broken stub';
            }

            public function description(): string
            {
                return 'Names one block twice, which the database refuses.';
            }

            public function blocks(): array
            {
                return ['page_hero', 'page_hero'];
            }
        };

        $contentKey = self::PREFIX . 'rollback';

        try {
            PageTemplateInstaller::install($template, [
                'content_key' => $contentKey,
                'slug' => $contentKey,
                'status' => PageContent::STATUS_DRAFT,
            ], [
                \App\Service\PageLocalization::defaultLanguage() => [\App\Service\PageTranslation::TITLE => 'Rollback-test'],
            ]);

            self::fail('Expected the duplicate block to make the installation fail.');
        } catch (\Throwable $e) {
            // Expected — what matters is what is left behind.
        }

        $db = Database::connection();

        $page = $db->prepare('SELECT COUNT(*) AS total FROM pages WHERE content_key = :key');
        $page->execute(['key' => $contentKey]);
        self::assertSame(0, (int) $page->fetch()['total'], 'A half-created page was left behind.');

        $sections = $db->prepare('SELECT COUNT(*) AS total FROM page_sections WHERE page_slug = :key');
        $sections->execute(['key' => $contentKey]);
        self::assertSame(0, (int) $sections->fetch()['total'], 'Orphaned page_sections rows were left behind.');

        $heroes = $db->prepare('SELECT COUNT(*) AS total FROM page_heroes WHERE page_slug = :key');
        $heroes->execute(['key' => $contentKey]);
        self::assertSame(0, (int) $heroes->fetch()['total'], 'An orphaned block content row was left behind.');
    }

    /**
     * A template naming a block that cannot be placed is a programming error,
     * and it is caught before anything is written at all — there is not even
     * a transaction to roll back.
     */
    public function testATemplateNamingAnUnplaceableBlockCreatesNoPage(): void
    {
        $template = new class () extends PageTemplateDefinition {
            public function key(): string
            {
                return 'zz-unplaceable-stub';
            }

            public function label(): string
            {
                return 'Unplaceable stub';
            }

            public function description(): string
            {
                return 'Names a block type nothing registers.';
            }

            public function blocks(): array
            {
                return ['rich_text', 'zz_no_such_block'];
            }
        };

        $contentKey = self::PREFIX . 'unplaceable';

        $this->expectException(\RuntimeException::class);

        try {
            PageTemplateInstaller::install($template, [
                'content_key' => $contentKey,
                'slug' => $contentKey,
                'status' => PageContent::STATUS_DRAFT,
            ], [
                \App\Service\PageLocalization::defaultLanguage() => [\App\Service\PageTranslation::TITLE => 'Onplaatsbaar-test'],
            ]);
        } finally {
            $stmt = Database::connection()->prepare('SELECT COUNT(*) AS total FROM pages WHERE content_key = :key');
            $stmt->execute(['key' => $contentKey]);

            self::assertSame(0, (int) $stmt->fetch()['total'], 'A page was created for a template that cannot run.');
        }
    }

    // ---------------------------------------------------------------
    // SEO
    // ---------------------------------------------------------------

    /**
     * A template-created page goes through the ordinary CMS SEO system and
     * nothing else: the same PageSeo call every other content page makes,
     * with the same fallbacks. No template hardcodes metadata.
     */
    public function testTemplateCreatedPageUsesTheOrdinaryPageSeoSystem(): void
    {
        [, $page] = $this->createFromTemplate('about', self::PREFIX . 'seo');

        $default = \App\Service\PageLocalization::defaultLanguage();
        $pageId = (int) $page['id'];
        self::assertSame('', \App\Service\PageLocalization::raw($pageId, \App\Service\PageTranslation::META_TITLE, $default), 'A template must not hardcode an SEO title.');
        self::assertSame('', \App\Service\PageLocalization::raw($pageId, \App\Service\PageTranslation::META_DESCRIPTION, $default), 'A template must not hardcode a meta description.');
        self::assertSame(
            'Paginasjabloon-test ' . self::PREFIX . 'seo',
            \App\Service\PageLocalization::raw($pageId, \App\Service\PageTranslation::TITLE, $default),
            'The page keeps the title it was created with, in the default language.'
        );

        $metadata = PageSeo::forPage($page);

        self::assertStringContainsString(\App\Service\PageLocalization::name($pageId), $metadata->title());
        self::assertStringEndsWith('/' . self::PREFIX . 'seo', (string) $metadata->canonical);
        self::assertSame(PageContent::canonicalUrl($page), $metadata->canonical);
        self::assertSame(0, (int) $page['noindex'], 'A template must not set a page to noindex.');

        // Indexability follows publication for a template-created page in
        // exactly the way it does for one built by hand: a draft is not
        // indexable, and publishing makes it so. There is no template-
        // specific SEO path that could answer differently.
        self::assertFalse(PageSeo::isIndexable($page), 'A draft must not be indexable.');
        self::assertFalse($metadata->isIndexable());

        $published = ['status' => PageContent::STATUS_PUBLISHED] + $page;

        self::assertTrue(PageSeo::isIndexable($published));
        self::assertTrue(PageSeo::forPage($published)->isIndexable());
    }

    /**
     * The sitemap follows publication, exactly as it does for a page built
     * by hand — a draft is absent, and publishing puts it in under its own
     * canonical URL. There is no template-specific path into the sitemap.
     */
    public function testTemplateCreatedPageEntersTheSitemapOnlyOncePublished(): void
    {
        [$pageId, $page] = $this->createFromTemplate('standard', self::PREFIX . 'sitemap');

        $canonical = PageContent::canonicalUrl($page);
        $locations = static fn (): array => array_column(Sitemap::entries(), 'loc');

        self::assertNotContains($canonical, $locations(), 'A draft page must not be in the sitemap.');

        $this->pages->update($pageId, [
            'slug' => (string) $page['slug'],
            'status' => PageContent::STATUS_PUBLISHED,
        ]);
        PageContent::clearCache();

        self::assertContains($canonical, $locations(), 'A published page must be in the sitemap.');
    }

    /**
     * New pages start as drafts, so a template's starter blocks are never
     * live before the editor has looked at them.
     */
    public function testTemplateCreatedPageStartsAsADraft(): void
    {
        [, $page] = $this->createFromTemplate('landing', self::PREFIX . 'draft');

        self::assertSame(PageContent::STATUS_DRAFT, (string) $page['status']);
        self::assertFalse(PageContent::isPublished($page));
        self::assertNull(PageContent::forSlug((string) $page['slug']));
    }

    // ---------------------------------------------------------------
    // Forms
    // ---------------------------------------------------------------

    /**
     * Creating a Contact page must not create a form definition behind the
     * editor's back, and must not leave a block pointing at a form that does
     * not exist. The block is created pointing at nothing, which the form
     * block already handles: the page builder asks the editor to choose one.
     */
    public function testContactTemplateCreatesAnEmptyFormBlockAndNoFormDefinition(): void
    {
        $db = Database::connection();

        $formsBefore = (int) $db->query('SELECT COUNT(*) AS total FROM forms')->fetch()['total'];

        [$pageId, $page] = $this->createFromTemplate('contact', self::PREFIX . 'form');

        $formsAfter = (int) $db->query('SELECT COUNT(*) AS total FROM forms')->fetch()['total'];

        self::assertSame($formsBefore, $formsAfter, 'Creating a Contact page created a form definition.');

        $formSections = array_values(array_filter(
            $this->sections->findForPage($pageId),
            static fn (array $row): bool => (string) $row['section_type'] === 'form'
        ));

        self::assertCount(1, $formSections);

        $stmt = $db->prepare('SELECT form_id FROM form_blocks WHERE page_slug = :key');
        $stmt->execute(['key' => (string) $page['content_key']]);
        $row = $stmt->fetch();

        self::assertIsArray($row);
        self::assertNull($row['form_id'], 'The Contact template must not point the block at a form.');
    }

    // ---------------------------------------------------------------
    // Routing and the existing site
    // ---------------------------------------------------------------

    /**
     * Templates change nothing about slug validation: the create endpoint
     * still resolves the slug through PageService first, so a reserved
     * application route is refused before a template is ever applied.
     */
    public function testReservedRoutesAreStillRefusedForTemplateCreatedPages(): void
    {
        foreach (['contact', 'diensten', 'shop', 'admin', 'api', 'storage', 'index'] as $reserved) {
            self::assertNotNull(
                PageService::validateSlug($this->pages, $reserved, null, \App\Service\PageLocalization::defaultLanguage()),
                'Slug "' . $reserved . '" should still be reserved.'
            );
        }
    }

    /** A template can never mint a second homepage: only "/" is the site root. */
    public function testATemplateCannotCreateASecondHomepage(): void
    {
        [, $page] = $this->createFromTemplate('landing', self::PREFIX . 'not-home');

        self::assertFalse(PageContent::isSiteRoot($page));
        self::assertNotSame('/', PageContent::publicUrl($page));

        $stmt = Database::connection()->query("SELECT COUNT(*) AS total FROM pages WHERE route_path = '/'");

        self::assertSame(1, (int) $stmt->fetch()['total'], 'There must be exactly one site root.');
    }

    /**
     * Creating pages from templates leaves the six fixed-URL pages exactly as
     * they were: same rows, same paths, same protection.
     */
    public function testTheExistingFixedUrlPagesAreUntouched(): void
    {
        $before = Database::connection()
            ->query('SELECT content_key, slug, route_path, is_system, status FROM pages WHERE is_system = 1 ORDER BY sort_order')
            ->fetchAll();

        foreach (PageTemplates::keys() as $key) {
            $this->createFromTemplate($key, self::PREFIX . 'fixed-' . $key);
        }

        $after = Database::connection()
            ->query('SELECT content_key, slug, route_path, is_system, status FROM pages WHERE is_system = 1 ORDER BY sort_order')
            ->fetchAll();

        self::assertSame($before, $after);
    }

    /** The homepage stays protected, whatever templates do around it. */
    public function testTheHomepageRemainsProtected(): void
    {
        $home = $this->pages->findByContentKey('index');

        self::assertIsArray($home);
        self::assertTrue(PageContent::isSiteRoot($home));
        self::assertTrue(PageContent::isProtected($home));
    }
}

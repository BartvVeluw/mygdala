<?php

namespace Tests\Repository;

use App\Repository\FooterRepository;
use App\Repository\PageRepository;
use App\Repository\PageSectionRepository;
use App\Repository\RichTextRepository;
use App\Service\LinkResolver;
use App\Service\PageContent;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that migrating an existing database into the unified page model
 * (db/migrations/20260908100000_create_pages_table.php and the four
 * migrations after it) preserved everything it had to: every pre-existing
 * CMS page became a row, with the same public URL, the same content, the
 * same navigation/footer links — and now with Title/Slug/Status/SEO title/
 * Meta description as real, editable fields.
 *
 * Requires the migrations to have been run against the database this suite
 * connects to (the real local dev DB per this project's Docker setup; run
 * `phinx migrate` first if this fails with "no rows"), same convention as
 * Tests\Repository\PageSectionsBackfillTest.
 */
#[Group('migration-backfill')]
class PagesBackfillTest extends TestCase
{
    /** The six pages that keep their own PHP template and fixed route. */
    private const SYSTEM_PAGES = [
        'index' => '/',
        'shop' => '/shop.php',
        'diensten' => '/diensten.php',
        'portfolio' => '/portfolio.php',
        'over-mij' => '/over-mij.php',
        'contact' => '/contact.php',
    ];

    /** The former information_pages, now ordinary dynamic content pages. */
    private const CONTENT_PAGES = [
        'verzenden-retourneren',
        'algemene-voorwaarden',
        'privacyverklaring',
    ];

    private PageRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new PageRepository();
    }

    public function testEveryPreviouslyExistingCmsPageWasMigrated(): void
    {
        foreach (array_merge(array_keys(self::SYSTEM_PAGES), self::CONTENT_PAGES) as $contentKey) {
            $this->assertNotNull(
                $this->repository->findByContentKey($contentKey),
                "expected a pages row for \"{$contentKey}\" — run phinx migrate"
            );
        }
    }

    public function testExistingUrlsAreUnchanged(): void
    {
        foreach (self::SYSTEM_PAGES as $contentKey => $expectedUrl) {
            $page = $this->repository->findByContentKey($contentKey);
            $this->assertSame($expectedUrl, PageContent::publicUrl($page));
        }

        foreach (self::CONTENT_PAGES as $slug) {
            $page = $this->repository->findByContentKey($slug);
            $this->assertSame('/' . $slug, PageContent::publicUrl($page));
        }
    }

    public function testEveryMigratedPageExposesTheSameFieldsAsANewOne(): void
    {
        foreach (array_merge(array_keys(self::SYSTEM_PAGES), self::CONTENT_PAGES) as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);

            $this->assertArrayHasKey('title', $page);
            $this->assertNotSame('', trim((string) $page['title']), "{$contentKey} must have a Title");

            $this->assertArrayHasKey('slug', $page);
            $this->assertNotSame('', trim((string) $page['slug']), "{$contentKey} must have a Slug");

            $this->assertArrayHasKey('status', $page);
            $this->assertTrue(
                PageContent::isValidStatus((string) $page['status']),
                "{$contentKey} must have a valid Status"
            );

            // SEO fields must EXIST and be editable; they are allowed to be
            // empty — the migration deliberately invents no SEO copy.
            $this->assertArrayHasKey('meta_title', $page, "{$contentKey} must have a SEO title field");
            $this->assertArrayHasKey('meta_description', $page, "{$contentKey} must have a Meta description field");
        }
    }

    public function testPagesThatWerePublicAreMarkedPublished(): void
    {
        foreach (array_merge(array_keys(self::SYSTEM_PAGES), self::CONTENT_PAGES) as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);

            $this->assertSame(
                PageContent::STATUS_PUBLISHED,
                (string) $page['status'],
                "{$contentKey} was publicly reachable before the migration and must stay published"
            );
        }
    }

    public function testOnlyTheHomepageAndTheStorefrontAreProtected(): void
    {
        foreach (array_keys(self::SYSTEM_PAGES) as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);

            $this->assertTrue(PageContent::hasOwnTemplate($page));
            $this->assertTrue(
                PageContent::isRouteBound($page),
                "\"{$contentKey}\" is served at a fixed URL, so its slug stays locked"
            );

            $this->assertSame(
                in_array($contentKey, ['index', 'shop'], true),
                PageContent::isProtected($page),
                "only the site root and the storefront are protected — \"{$contentKey}\" must not be protected merely for having its own template"
            );
        }

        foreach (self::CONTENT_PAGES as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);

            $this->assertFalse(PageContent::hasOwnTemplate($page));
            $this->assertFalse(PageContent::isRouteBound($page));
            $this->assertFalse(
                PageContent::isProtected($page),
                "\"{$contentKey}\" is ordinary content and must be a normal, deletable content page"
            );
        }
    }

    public function testTheSixTemplatePagesKeepTheirOriginalSeoTitles(): void
    {
        // Copied verbatim from what each template hardcoded before the
        // migration — the rendered <title> must not have changed at all.
        $expected = [
            'index' => 'Van Veluw Laserdesign | Lasergraveren op hout & metaal in Nijmegen',
            'shop' => 'Shop | Gegraveerde producten — Van Veluw Laserdesign',
            'over-mij' => 'Over mij | Van Veluw Laserdesign — Nijmegen',
            'contact' => 'Contact & offerte aanvragen | Van Veluw Laserdesign',
        ];

        foreach ($expected as $contentKey => $title) {
            $page = $this->repository->findByContentKey($contentKey);

            $this->assertSame($title, PageContent::seoTitle($page, 'nl'));
            $this->assertNotSame('', PageContent::metaDescription($page, 'nl'));
            $this->assertNotSame('', PageContent::metaDescription($page, 'en'));
        }
    }

    public function testMigratedContentPagesKeepTheirBodyAsARichTextSection(): void
    {
        $sectionRepository = new PageSectionRepository();
        $richTextRepository = new RichTextRepository();

        foreach (self::CONTENT_PAGES as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);
            $sections = $sectionRepository->findForPage((int) $page['id']);

            // The hero and the body the migration attached, by the identity
            // it gave them — not the page's whole block list. These are
            // ordinary content pages: an administrator may add a block to
            // Privacyverklaring tomorrow, and that must not read as "the
            // migration lost the body".
            $identities = array_map(
                static fn (array $s): string => $s['section_type'] . ':' . ($s['section_key'] ?? ''),
                $sections
            );

            foreach (['page_hero:', 'rich_text:content'] as $identity) {
                $this->assertSame(
                    [$identity],
                    array_values(array_filter($identities, static fn (string $i): bool => $i === $identity)),
                    "\"{$contentKey}\" must still render the same hero + body it did before, attached exactly once"
                );
            }

            $body = $richTextRepository->findBySlugAndKey($contentKey, 'content');
            $this->assertNotNull($body);
            $this->assertNotSame('', trim((string) $body['content_html']), 'the page body must have survived the migration');
        }
    }

    public function testExistingSectionsStayAttachedToTheirOwnPage(): void
    {
        $sectionRepository = new PageSectionRepository();

        foreach (array_keys(self::SYSTEM_PAGES) as $contentKey) {
            $page = $this->repository->findByContentKey($contentKey);

            foreach ($sectionRepository->findForPage((int) $page['id']) as $section) {
                $this->assertSame(
                    $contentKey,
                    (string) $section['page_slug'],
                    'page_id and the section storage key must agree after the backfill'
                );
            }
        }
    }

    public function testFooterInformationLinksNowResolveThroughThePagesTable(): void
    {
        $links = (new FooterRepository())->findAllVisibleLinks();

        $pageLinks = array_values(array_filter(
            $links,
            static fn (array $l): bool => (string) $l['link_type'] === 'page'
        ));

        $this->assertNotEmpty($pageLinks, 'the footer had CMS-page links before the migration and must still have them');

        foreach ($pageLinks as $link) {
            $target = $this->repository->findById((int) $link['target_page_id']);
            $this->assertNotNull($target, 'every footer page link must point at a real pages row after the remap');

            $resolved = LinkResolver::resolve($link);
            $this->assertNotNull($resolved);
            $this->assertSame(PageContent::publicUrl($target), $resolved['href']);
        }
    }
}

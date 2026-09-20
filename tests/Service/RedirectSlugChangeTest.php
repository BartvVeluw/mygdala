<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\RedirectRepository;
use App\Service\PageContent;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectTarget;
use App\Service\Redirects\SlugChangeRedirects;
use App\Service\Sitemap;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Renaming a published CMS page keeps its old URL working, and keeps working
 * after the page is renamed again.
 *
 * The service is exercised directly rather than through
 * api/admin/update-page.php, because that endpoint's own job — which saves may
 * call this at all — is a set of conditions, not behaviour, and is asserted
 * against its source at the bottom of this file. What the redirects then DO is
 * proved over real HTTP.
 */
class RedirectSlugChangeTest extends TestCase
{
    private const PAGE_KEY = 'zz-redirect-slugchange';
    private const FIRST_SLUG = 'zz-redirect-slugchange-eerste';
    private const SECOND_SLUG = 'zz-redirect-slugchange-tweede';
    private const THIRD_SLUG = 'zz-redirect-slugchange-derde';

    private RedirectRepository $redirects;
    private PageRepository $pages;
    private SlugChangeRedirects $service;

    protected function setUp(): void
    {
        $this->redirects = new RedirectRepository();
        $this->pages = new PageRepository();
        $this->service = new SlugChangeRedirects($this->redirects);

        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $db = Database::connection();

        foreach ([self::FIRST_SLUG, self::SECOND_SLUG, self::THIRD_SLUG] as $slug) {
            $stmt = $db->prepare('DELETE FROM redirects WHERE source_path = :source');
            $stmt->execute(['source' => '/' . $slug]);
        }

        $stmt = $db->prepare('DELETE FROM pages WHERE content_key = :key');
        $stmt->execute(['key' => self::PAGE_KEY]);

        PageContent::clearCache();
    }

    private function createPage(string $slug, string $status = PageContent::STATUS_PUBLISHED): int
    {
        return \Tests\Support\PageFixture::create([
            'content_key' => self::PAGE_KEY,
            'slug' => $slug,
            'status' => $status,
        ], 'Redirect-hernoemtest');
    }

    /** @return array<string, mixed>|null */
    private function redirectFor(string $slug): ?array
    {
        return $this->redirects->findBySourcePath('/' . $slug);
    }

    /** @return array{status: int, location: ?string, body: string} */
    private function request(string $path): array
    {
        $handle = curl_init(TestEnvironment::baseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        $location = null;
        if (preg_match('/^Location:\s*(.+)$/mi', substr($response, 0, $headerSize), $matches) === 1) {
            $location = trim($matches[1]);
        }

        return ['status' => $status, 'location' => $location, 'body' => substr($response, $headerSize)];
    }

    public function testRenamingAPublishedPageCreatesAPermanentRedirect(): void
    {
        $this->assertTrue($this->service->record(self::FIRST_SLUG, self::SECOND_SLUG));

        $redirect = $this->redirectFor(self::FIRST_SLUG);

        $this->assertNotNull($redirect);
        $this->assertSame('/' . self::SECOND_SLUG, $redirect['target_value']);
        $this->assertSame(RedirectTarget::TYPE_INTERNAL, $redirect['target_type']);
        $this->assertSame(301, (int) $redirect['status_code']);
        $this->assertSame(1, (int) $redirect['is_active']);
        $this->assertSame(
            Redirect::ORIGIN_SLUG_CHANGE,
            $redirect['origin'],
            'an automatic redirect must be recognisable as one in the admin'
        );
    }

    public function testASaveThatDoesNotChangeTheSlugWritesNothing(): void
    {
        $this->assertFalse($this->service->record(self::FIRST_SLUG, self::FIRST_SLUG));
        $this->assertNull($this->redirectFor(self::FIRST_SLUG));
    }

    /**
     * The preferred outcome of two renames: every URL the page ever had points
     * straight at where it is now, so no visitor is sent through two hops and
     * no chain grows with the number of renames.
     */
    public function testASecondRenameKeepsTheOldestUrlWorkingAndPointsItAtTheNewest(): void
    {
        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);
        $this->service->record(self::SECOND_SLUG, self::THIRD_SLUG);

        $this->assertSame('/' . self::THIRD_SLUG, $this->redirectFor(self::FIRST_SLUG)['target_value']);
        $this->assertSame('/' . self::THIRD_SLUG, $this->redirectFor(self::SECOND_SLUG)['target_value']);
    }

    /**
     * Renaming a page back to a slug it used to have: the old redirect on that
     * path is removed because the path serves the page again, and nothing is
     * left pointing at itself.
     */
    public function testRenamingBackToAnEarlierSlugLeavesNoLoop(): void
    {
        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);
        $this->service->record(self::SECOND_SLUG, self::FIRST_SLUG);

        $this->assertNull(
            $this->redirectFor(self::FIRST_SLUG),
            'that path serves the page again, so nothing may redirect away from it'
        );

        $back = $this->redirectFor(self::SECOND_SLUG);
        $this->assertNotNull($back);
        $this->assertSame('/' . self::FIRST_SLUG, $back['target_value']);
    }

    /**
     * A destination somebody chose by hand is not a rename's to overrule.
     */
    public function testAManualRedirectOnTheOldPathIsLeftAlone(): void
    {
        $this->redirects->create([
            'source_path' => '/' . self::FIRST_SLUG,
            'target_type' => RedirectTarget::TYPE_INTERNAL,
            'target_value' => '/contact.php',
            'status_code' => Redirect::STATUS_PERMANENT,
            'is_active' => true,
            'origin' => Redirect::ORIGIN_MANUAL,
        ]);

        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);

        $redirect = $this->redirectFor(self::FIRST_SLUG);
        $this->assertSame('/contact.php', $redirect['target_value']);
        $this->assertSame(Redirect::ORIGIN_MANUAL, $redirect['origin']);
    }

    public function testTheOldUrlRedirectsAndTheNewOneRenders(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $pageId = $this->createPage(self::FIRST_SLUG);

        $this->assertSame(200, $this->request('/' . self::FIRST_SLUG)['status']);

        $this->pages->update($pageId, [
            'slug' => self::SECOND_SLUG,
            'status' => PageContent::STATUS_PUBLISHED,
            'noindex' => false,
        ]);
        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);

        $old = $this->request('/' . self::FIRST_SLUG);
        $this->assertSame(301, $old['status']);
        $this->assertSame(\App\Service\AppUrl::canonical(self::SECOND_SLUG), $old['location']);

        $new = $this->request('/' . self::SECOND_SLUG);
        $this->assertSame(200, $new['status']);

        // The destination of the redirect and the page's own canonical tag are
        // the same URL — they have to be, or the redirect would point at a URL
        // the page does not call its own.
        $this->assertStringContainsString(
            'rel="canonical" href="' . \App\Service\AppUrl::canonical(self::SECOND_SLUG) . '"',
            $new['body']
        );
    }

    public function testAfterASecondRenameBothHistoricalUrlsStillWork(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $pageId = $this->createPage(self::THIRD_SLUG);

        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);
        $this->service->record(self::SECOND_SLUG, self::THIRD_SLUG);

        foreach ([self::FIRST_SLUG, self::SECOND_SLUG] as $historical) {
            $response = $this->request('/' . $historical);

            $this->assertSame(301, $response['status'], '/' . $historical . ' must still work');
            $this->assertSame(
                \App\Service\AppUrl::canonical(self::THIRD_SLUG),
                $response['location'],
                '/' . $historical . ' must go straight to the page\'s current URL'
            );
        }

        $this->assertSame(200, $this->request('/' . self::THIRD_SLUG)['status']);
        $this->assertGreaterThan(0, $pageId);
    }

    /**
     * A redirect source is not a URL of this site, so it can never appear in
     * the sitemap. True by construction — every <loc> comes from a content
     * type's own canonicalUrl() (see SEO.md) and this table is not a content
     * type — and asserted here so it stays true.
     */
    public function testTheSitemapListsTheNewUrlAndNeverTheOldOne(): void
    {
        $this->createPage(self::SECOND_SLUG);
        $this->service->record(self::FIRST_SLUG, self::SECOND_SLUG);

        $locations = array_column(Sitemap::entries(), 'loc');

        $this->assertContains(\App\Service\AppUrl::canonical(self::SECOND_SLUG), $locations);
        $this->assertNotContains(\App\Service\AppUrl::canonical(self::FIRST_SLUG), $locations);
    }

    /**
     * The conditions under which update-page.php calls this at all. Asserted
     * against its source rather than by driving the endpoint, because each one
     * is a guard against writing a redirect nobody asked for: a brand-new page
     * (created as a draft, and create-page.php does not call this), an
     * ordinary save, a page with a fixed URL, and a rename that takes the page
     * offline in the same move.
     */
    public function testThePageEndpointOnlyRecordsARenameOfAPublishedPage(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/api/admin/update-page.php');

        // The language is part of the call since Multilingual 2.0 phase 6:
        // a rename is recorded in the URL space of the language it happened
        // in (docs/multilingual/ROUTING.md).
        $this->assertStringContainsString(
            '(new SlugChangeRedirects())->record($oldSlug, $slug, $languageCode)',
            $source
        );
        $this->assertStringContainsString('$oldSlug !== $slug', $source, 'only a real slug change may write one');
        // The other three conditions — no fixed URL, was published, stays
        // published — live in one rule the confirmation screen asks as well;
        // Tests\Service\PageUrlChangeTest proves each of them on that rule.
        $this->assertStringContainsString(
            'PageService::oldAddressWillRedirect($page, $status)',
            $source,
            'a fixed URL, a draft and a rename that unpublishes must not write one'
        );

        $this->assertStringNotContainsString(
            'SlugChangeRedirects',
            (string) file_get_contents(dirname(__DIR__, 2) . '/api/admin/create-page.php'),
            'a brand-new page has no old URL to preserve'
        );
        $this->assertStringNotContainsString(
            'SlugChangeRedirects',
            (string) file_get_contents(dirname(__DIR__, 2) . '/api/admin/delete-page.php'),
            'deleting a page must not invent a destination for its URL'
        );
    }
}

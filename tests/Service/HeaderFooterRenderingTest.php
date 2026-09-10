<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Repository\PageRepository;
use App\Service\HeaderCta;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * The header/footer settings where they meet real data and a real request:
 *
 *  - the CMS-PAGE target, which needs an actual `pages` row (the route and
 *    external targets are covered without a database in
 *    Tests\Service\HeaderFooterSettingsTest);
 *  - what a page actually renders, with the settings this installation has —
 *    the proof that turning literals into settings changed nothing;
 *  - and the same over the CMS-only deployment, where the Shop is off.
 *
 * Deliberately NOT a snapshot of the header and the footer. It asserts the
 * few strings that would tell you the feature broke, and nothing about the
 * markup around them.
 */
final class HeaderFooterRenderingTest extends TestCase
{
    private PageRepository $pages;

    /** @var list<int> */
    private array $createdPageIds = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPageIds as $id) {
            $this->pages->delete($id);
        }
        $this->createdPageIds = [];

        SiteSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    private function makePage(string $slug, bool $published = true): int
    {
        $id = $this->pages->create([
            'content_key' => $slug,
            'slug' => $slug,
            'title' => 'Test page ' . $slug,
            'status' => $published ? 'published' : 'draft',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);
        $this->createdPageIds[] = $id;

        return $id;
    }

    /** @param array<string, string> $overrides */
    private function ctaPointingAtPage(int $pageId, array $overrides = []): void
    {
        SiteSettings::overrideForTests(array_merge([
            'header_cta_enabled' => '1',
            'header_cta_label_nl' => 'Vraag offerte aan',
            'header_cta_label_en' => 'Request a quote',
            'header_cta_link_type' => 'page',
            'header_cta_target_page_id' => (string) $pageId,
        ], $overrides));
    }

    // ------------------------------------------------------- CMS-page target

    public function testACmsPageTargetResolvesToThatPagesCurrentUrl(): void
    {
        $pageId = $this->makePage('__test_cta_target__');
        $this->ctaPointingAtPage($pageId);

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('/__test_cta_target__', $cta['href']);
    }

    /**
     * The reason a page target beats a typed path: rename the page and the
     * button follows it, with nobody editing the button.
     */
    public function testTheButtonFollowsThePageWhenItsSlugChanges(): void
    {
        $pageId = $this->makePage('__test_cta_before__');
        $this->ctaPointingAtPage($pageId);

        $this->pages->update($pageId, [
            'slug' => '__test_cta_after__',
            'title' => 'Test page renamed',
            'status' => 'published',
            'meta_title' => null,
            'meta_title_en' => null,
            'meta_description' => null,
            'meta_description_en' => null,
        ]);

        $cta = HeaderCta::forHeader();

        $this->assertNotNull($cta);
        $this->assertSame('/__test_cta_after__', $cta['href']);
    }

    public function testAnUnpublishedTargetPageStopsTheButtonFromRendering(): void
    {
        $pageId = $this->makePage('__test_cta_draft__', published: false);
        $this->ctaPointingAtPage($pageId);

        $this->assertNull(HeaderCta::forHeader());
        $this->assertNotNull(HeaderCta::adminWarning());
        $this->assertSame((string) $pageId, SiteSettings::get('header_cta_target_page_id'));
    }

    public function testADeletedTargetPageStopsTheButtonFromRendering(): void
    {
        $pageId = $this->makePage('__test_cta_deleted__');
        $this->pages->delete($pageId);
        $this->createdPageIds = [];

        $this->ctaPointingAtPage($pageId);

        $this->assertNull(HeaderCta::forHeader());
    }

    /**
     * A page whose URL is a MODULE's own template — /shop.php — still exists
     * and is still editable while that module is off, but the URL answers
     * 404. Linking to it would send visitors there, so the button is dropped
     * exactly like an unregistered route key, and the setting is kept.
     */
    public function testATargetPageServedByASwitchedOffModuleStopsTheButtonFromRendering(): void
    {
        $shopPage = $this->pages->findBySlugPublished('shop');

        if ($shopPage === null) {
            $this->markTestSkipped('this installation has no module-served page to point at');
        }

        $this->ctaPointingAtPage((int) $shopPage['id']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $this->assertNotNull(HeaderCta::forHeader(), 'with the Shop on, a Shop page target must resolve');

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
        $this->assertNull(HeaderCta::forHeader());
        $this->assertSame((string) $shopPage['id'], SiteSettings::get('header_cta_target_page_id'));
    }

    // ------------------------------------------------------------ over HTTP

    private function get(string $baseUrl, string $path): string
    {
        $handle = curl_init($baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = (string) curl_exec($handle);
        curl_close($handle);

        return $body;
    }

    private function requireSite(): string
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        return TestEnvironment::baseUrl();
    }

    /**
     * The migration's whole job: this installation's header and footer read
     * exactly as they did when the same words were literals in the partials.
     */
    public function testThisInstallationsHeaderAndFooterAreUnchanged(): void
    {
        $body = $this->get($this->requireSite(), '/index.php');

        $this->assertStringContainsString(
            '<a href="/contact.php" class="btn btn--sm" data-nl="Vraag offerte aan" data-en="Request a quote">Vraag offerte aan</a>',
            $body,
            'the header button must render byte for byte as it did before it became a setting'
        );

        $this->assertStringContainsString(
            'data-nl="Ontworpen &amp; gebouwd met zorg in Nijmegen" data-en="Designed &amp; built with care in Nijmegen"',
            $body,
            'the footer slogan must render as it did before it became a setting'
        );
    }

    public function testNoSocialRowIsRenderedWhenNoProfileIsConfigured(): void
    {
        $body = $this->get($this->requireSite(), '/index.php');

        $this->assertStringNotContainsString('social-row', $body, 'an unconfigured social row must leave no markup at all');
    }

    /**
     * The header and footer settings are Core's, so a deployment without the
     * Shop gets the same button and the same slogan — and still no Shop.
     */
    public function testTheCmsOnlyDeploymentRendersTheSameButtonAndSlogan(): void
    {
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $body = $this->get(TestEnvironment::cmsOnlyBaseUrl(), '/index.php');

        $this->assertStringContainsString('data-nl="Vraag offerte aan"', $body);
        $this->assertStringContainsString('data-nl="Ontworpen &amp; gebouwd met zorg in Nijmegen"', $body);
        $this->assertStringNotContainsString('social-row', $body);

        $this->assertStringNotContainsString('assets/css/shop/', $body, 'no Shop stylesheet may return through the shell');
        $this->assertStringNotContainsString('assets/js/shop/', $body, 'no Shop script may return through the shell');
        $this->assertStringNotContainsString('header-cart', $body, 'the mini-cart belongs to the Shop');
    }
}

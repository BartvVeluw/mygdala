<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Database;
use App\Module\ModuleRegistry;
use App\Repository\NavigationRepository;
use App\Repository\PageRepository;
use App\Service\NavigationPresentation;
use App\Service\NavigationService;
use App\Service\PageService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * The header buttons and footer settings where they meet real data and a
 * real request:
 *
 *  - a header button with a CMS-PAGE target, which needs actual `pages` and
 *    `nav_items` rows (the route and external targets are covered without a
 *    database in Tests\Service\NavigationServiceTest);
 *  - what a page actually renders, with the data this installation has —
 *    the proof that moving the single button into the navigation changed
 *    nothing a visitor sees;
 *  - and the same over the CMS-only deployment, where the Shop is off.
 *
 * Deliberately NOT a snapshot of the header and the footer. It asserts the
 * few strings that would tell you the feature broke, and nothing about the
 * markup around them.
 */
final class HeaderFooterRenderingTest extends TestCase
{
    private PageRepository $pages;
    private NavigationRepository $navigation;

    /** @var list<int> */
    private array $createdPageIds = [];

    /** @var list<int> */
    private array $createdNavIds = [];

    protected function setUp(): void
    {
        $this->pages = new PageRepository();
        $this->navigation = new NavigationRepository();
    }

    protected function tearDown(): void
    {
        // Buttons first: a page a nav item points at cannot be deleted.
        foreach ($this->createdNavIds as $id) {
            Database::connection()->prepare('DELETE FROM nav_items WHERE id = :id')->execute(['id' => $id]);
        }
        foreach ($this->createdPageIds as $id) {
            $this->pages->delete($id);
        }
        $this->createdNavIds = [];
        $this->createdPageIds = [];

        SiteSettings::overrideForTests(null);
        ModuleRegistry::overrideForTests(null);
    }

    private function makePage(string $slug, bool $published = true): int
    {
        $id = \Tests\Support\PageFixture::create([
            'content_key' => $slug,
            'slug' => $slug,
            'status' => $published ? 'published' : 'draft',
        ], 'Test page ' . $slug);
        $this->createdPageIds[] = $id;

        return $id;
    }

    private function buttonPointingAtPage(int $pageId): int
    {
        $id = $this->navigation->create([
            'label_nl' => 'Vraag offerte aan',
            'label_en' => 'Request a quote',
            'link_type' => 'page',
            'target_page_id' => $pageId,
            'target_route' => null,
            'external_url' => null,
            'open_in_new_tab' => false,
            'parent_id' => null,
            'is_visible' => true,
            'presentation' => NavigationPresentation::BUTTON,
            'button_variant' => NavigationPresentation::VARIANT_PRIMARY,
        ]);
        $this->createdNavIds[] = $id;

        return $id;
    }

    /**
     * The buttons as the header would render them, limited to this test's
     * own rows so whatever else the test database holds does not matter.
     *
     * @return list<array<string, mixed>>
     */
    private function renderedButtons(int $id): array
    {
        return array_values(array_filter(
            NavigationService::buildButtons([$this->navigation->findById($id)]),
            static fn (array $button): bool => $button['id'] === $id
        ));
    }

    // ------------------------------------------------------- CMS-page target

    public function testACmsPageTargetResolvesToThatPagesCurrentUrl(): void
    {
        $pageId = $this->makePage('__test_cta_target__');
        $buttonId = $this->buttonPointingAtPage($pageId);

        $buttons = $this->renderedButtons($buttonId);

        $this->assertCount(1, $buttons);
        $this->assertSame('/__test_cta_target__', $buttons[0]['href']);
    }

    /**
     * The reason a page target beats a typed path: rename the page and the
     * button follows it, with nobody editing the button.
     */
    public function testTheButtonFollowsThePageWhenItsSlugChanges(): void
    {
        $pageId = $this->makePage('__test_cta_before__');
        $buttonId = $this->buttonPointingAtPage($pageId);

        $this->pages->update($pageId, [
            'slug' => '__test_cta_after__',
            'status' => 'published',
        ]);

        $this->assertSame('/__test_cta_after__', $this->renderedButtons($buttonId)[0]['href'] ?? null);
    }

    public function testAnUnpublishedTargetPageStopsTheButtonFromRendering(): void
    {
        $pageId = $this->makePage('__test_cta_draft__', published: false);
        $buttonId = $this->buttonPointingAtPage($pageId);

        $this->assertSame([], $this->renderedButtons($buttonId));
        $this->assertSame($pageId, (int) $this->navigation->findById($buttonId)['target_page_id'], 'the row is kept');
    }

    /**
     * A page a button points at cannot be deleted out from under it: the
     * same rule as a menu item (PageService::references(), and the RESTRICT
     * foreign key behind it). The single CTA in site_settings could not be
     * seen by that rule; a button row can.
     */
    public function testATargetPageCannotBeDeletedWhileAButtonPointsAtIt(): void
    {
        $pageId = $this->makePage('__test_cta_deleted__');
        $this->buttonPointingAtPage($pageId);

        $this->assertSame(1, PageService::references($pageId)['nav']);
    }

    /**
     * A page whose URL is a MODULE's own template — /shop.php — still exists
     * and is still editable while that module is off, but the URL answers
     * 404. Linking to it would send visitors there, so the button is dropped
     * exactly like an unregistered route key, and the row is kept.
     */
    public function testATargetPageServedByASwitchedOffModuleStopsTheButtonFromRendering(): void
    {
        $shopPage = $this->pages->findBySlugPublished('shop');

        if ($shopPage === null) {
            $this->markTestSkipped('this installation has no module-served page to point at');
        }

        $buttonId = $this->buttonPointingAtPage((int) $shopPage['id']);

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true]);
        $this->assertCount(1, $this->renderedButtons($buttonId), 'with the Shop on, a Shop page target must resolve');

        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false]);
        $this->assertSame([], $this->renderedButtons($buttonId));
        $this->assertSame((int) $shopPage['id'], (int) $this->navigation->findById($buttonId)['target_page_id']);
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
     * Two migrations' whole job: this installation's header and footer read
     * exactly as they did when the same words were literals in the partials,
     * and the button kept its markup when it moved from site_settings into
     * the navigation (20260916230000).
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

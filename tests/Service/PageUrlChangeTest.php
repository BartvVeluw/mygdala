<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\PageContent;
use App\Service\PageService;
use PHPUnit\Framework\TestCase;

/**
 * Changing a page's web address on purpose, and only on purpose.
 *
 * Two rules, tested directly on App\Service\PageService — they decide from a
 * `pages` row and the submitted values, so no database is needed — and the
 * wiring that puts them between an editor and a moved URL, asserted against
 * the source of the two screens and the endpoint. This project has no
 * browser harness for an authenticated admin; that is the same reason
 * Tests\Service\RedirectSlugChangeTest reads update-page.php.
 *
 * What is deliberately NOT here, and must never be: anything that searches
 * content for the old address and rewrites it. Links that point at the page
 * by id follow it by themselves (App\Service\PageUsage), and typed links are
 * kept working by the redirect, not by string replacement.
 */
final class PageUrlChangeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function page(string $slug, string $status = PageContent::STATUS_PUBLISHED, ?string $routePath = null): array
    {
        return [
            'id' => 7,
            'content_key' => 'test',
            'slug' => $slug,
            'status' => $status,
            'route_path' => $routePath,
            'is_system' => $routePath === null ? 0 : 1,
        ];
    }

    private static function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
    }

    // ------------------------------------------------- asking before a move

    public function testASaveThatKeepsTheAddressIsNeverAskedAbout(): void
    {
        $this->assertFalse(PageService::urlChangeNeedsConfirmation(self::page('over-ons'), 'over-ons', ''));
    }

    public function testANewAddressIsAskedAboutFirst(): void
    {
        $this->assertTrue(PageService::urlChangeNeedsConfirmation(self::page('over-ons'), 'over-mij', ''));
    }

    public function testTheConfirmedAddressGoesThrough(): void
    {
        $this->assertFalse(PageService::urlChangeNeedsConfirmation(self::page('over-ons'), 'over-mij', 'over-mij'));
    }

    public function testAnAddressChangedAgainAfterConfirmingIsAskedAboutAgain(): void
    {
        $this->assertTrue(PageService::urlChangeNeedsConfirmation(self::page('over-ons'), 'wie-wij-zijn', 'over-mij'));
    }

    /**
     * Not because a redirect is at stake — it is not — but because the editor
     * should know what the change means before it happens; the confirmation
     * says so in words.
     */
    public function testADraftIsAskedAboutToo(): void
    {
        $this->assertTrue(
            PageService::urlChangeNeedsConfirmation(self::page('concept', PageContent::STATUS_DRAFT), 'concept-2', '')
        );
    }

    public function testAPageOnAFixedUrlNeverAsks(): void
    {
        $this->assertFalse(
            PageService::urlChangeNeedsConfirmation(self::page('shop', routePath: '/shop.php'), 'winkel', '')
        );
    }

    // ------------------------------------------- what happens to the old one

    public function testAPublishedPageThatStaysPublishedKeepsItsOldAddressWorking(): void
    {
        $this->assertTrue(PageService::oldAddressWillRedirect(self::page('a'), PageContent::STATUS_PUBLISHED));
    }

    public function testADraftHasNoOldAddressToKeep(): void
    {
        $this->assertFalse(
            PageService::oldAddressWillRedirect(self::page('a', PageContent::STATUS_DRAFT), PageContent::STATUS_PUBLISHED)
        );
    }

    public function testMovingAndUnpublishingInOneSaveWritesNoRedirect(): void
    {
        $this->assertFalse(PageService::oldAddressWillRedirect(self::page('a'), PageContent::STATUS_DRAFT));
    }

    public function testAFixedUrlNeverRedirects(): void
    {
        $this->assertFalse(
            PageService::oldAddressWillRedirect(self::page('shop', routePath: '/shop.php'), PageContent::STATUS_PUBLISHED)
        );
    }

    // --------------------------------------------------------------- wiring

    public function testTheEndpointAsksBeforeItWritesAnything(): void
    {
        $source = self::source('api/admin/update-page.php');

        $ask = strpos($source, 'PageService::urlChangeNeedsConfirmation($page, $slug, $confirmedSlug)');
        $write = strpos($source, '$repository->update(');

        $this->assertIsInt($ask);
        $this->assertIsInt($write);
        $this->assertLessThan($write, $ask, 'a moved address is confirmed before anything is saved');
        $this->assertLessThan($ask, (int) strpos($source, 'Csrf::validate('), 'the guards still come first');

        $this->assertStringContainsString("\$_POST['confirmed_slug']", $source);
        $this->assertStringContainsString("\$_SESSION['admin_page_url_change']", $source);
    }

    public function testNothingRewritesContentWhenAnAddressChanges(): void
    {
        foreach (['api/admin/update-page.php', 'src/Service/PageUsage.php', 'src/Service/Redirects/SlugChangeRedirects.php'] as $file) {
            $source = self::source($file);

            foreach (['str_replace(', 'preg_replace(', 'UPDATE rich_text', 'UPDATE cta_bands', 'UPDATE nav_items', 'UPDATE footer_links'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, $file . ' must not rewrite content for a new address');
            }
        }
    }

    public function testTheEditorKeepsTheAddressBehindADeliberateStep(): void
    {
        $source = self::source('admin/page.php');

        // Exactly one address field, and it lives inside the disclosure.
        $this->assertSame(1, substr_count($source, 'name="slug"'));
        $details = strpos($source, '<details class="admin-collapse admin-url-change"');
        $field = strpos($source, 'name="slug"');
        $this->assertIsInt($details);
        $this->assertIsInt($field);
        $this->assertGreaterThan($details, $field);
        $this->assertLessThan((int) strpos($source, '</details>', $details), $field);

        // A fixed URL is stated, not offered.
        $this->assertStringContainsString("admin_te('page.url_fixed')", $source);

        // The confirmation carries the one address it confirms and re-submits
        // the settings form the fields already fill: still one form.
        $this->assertSame(1, substr_count($source, 'name="confirmed_slug"'));
        $this->assertSame(1, substr_count($source, 'action="/api/admin/update-page.php"'));

        // It tells the editor what the endpoint will do, from the same rule,
        // and where the page is linked from.
        $this->assertStringContainsString('PageService::oldAddressWillRedirect($page,', $source);
        $this->assertStringContainsString('PageUsage::forPageId($pageId)', $source);
    }

    public function testANewPageBuildsItsAddressFromTheTitleUntilTheEditorTypesOne(): void
    {
        $form = self::source('admin/page-new.php');
        $endpoint = self::source('api/admin/create-page.php');
        $script = self::source('admin/assets/admin.js');

        foreach (['data-slug-source', 'data-slug-target', 'name="slug_auto"', 'data-slug-auto', 'data-slug-preview-value'] as $needle) {
            $this->assertStringContainsString($needle, $form);
        }

        $this->assertStringContainsString("\$_POST['slug_auto']", $endpoint);
        $this->assertStringContainsString("if (\$slugInput === '' || \$slugIsAutomatic)", $endpoint);

        $this->assertStringContainsString('[data-slug-auto]', $script);
        $this->assertStringContainsString('[data-slug-preview-value]', $script);
    }

    /**
     * "Webadres" on the screen, "slug" in the explanation only.
     */
    public function testTheScreenSaysWebAddressAndKeepsSlugForTheExplanation(): void
    {
        $catalogues = [
            'nl' => require dirname(__DIR__, 2) . '/src/Service/Language/messages/nl.php',
            'en' => require dirname(__DIR__, 2) . '/src/Service/Language/messages/en.php',
        ];

        $labels = [
            'page.url_label', 'page.url_fixed', 'page.url_change', 'page.url_change_warning', 'page.url_new',
            'page.url_usage_none', 'page.url_usage_one', 'page.url_usage_many', 'page.url_usage_follow',
            'page.url_typed_links', 'page.url_confirm_title', 'page.url_confirm_intro', 'page.url_confirm_old',
            'page.url_confirm_new', 'page.url_confirm_redirect', 'page.url_confirm_manual', 'page.url_confirm_draft',
            'page.url_confirm_unpublishing', 'page.url_confirm_submit', 'page.url_confirm_cancel',
            'page.url_usage_kind_menu', 'page.url_usage_kind_footer', 'page.url_usage_kind_header_button',
            'page.url_placeholder', 'page.url_preview', 'page.url_preview_empty',
        ];

        foreach ($catalogues as $language => $catalogue) {
            foreach ($labels as $key) {
                $this->assertArrayHasKey($key, $catalogue, $language . ': ' . $key);
                $this->assertStringNotContainsStringIgnoringCase('slug', $catalogue[$key], $language . ': ' . $key);
            }

            foreach (['help.page.url', 'help.page.url_new'] as $key) {
                $this->assertArrayHasKey($key, $catalogue, $language . ': ' . $key);
                $this->assertStringContainsString('slug', $catalogue[$key], $language . ': the technical term belongs in the explanation');
            }
        }
    }
}

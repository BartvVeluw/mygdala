<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminNavigation;
use PHPUnit\Framework\TestCase;

/**
 * The preview of a page, read from the source: what admin/page-preview.php
 * must do before it shows anything, what it must never do, and that the
 * public side stayed exactly as closed as it was.
 *
 * Tests\Service\PagePreviewAccessTest proves the same promises over real
 * HTTP. This file keeps them true without a web server or a database, so
 * `fast` fails the moment a guard moves or a write creeps in.
 */
final class PagePreviewContractTest extends TestCase
{
    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testThePreviewAsksThePageEditorsOwnGuardBeforeItReadsOrRendersAnything(): void
    {
        $source = self::source('admin/page-preview.php');

        $login = strpos($source, 'AdminAuth::requireLogin();');
        $permission = strpos($source, "AdminAuth::requirePermission('pages.manage');");
        $read = strpos($source, '->findById(');
        $document = stripos($source, '<!doctype html>');

        $this->assertIsInt($login);
        $this->assertIsInt($permission);
        $this->assertIsInt($read);
        $this->assertIsInt($document);
        $this->assertLessThan($permission, $login, 'signed in first');
        $this->assertLessThan($read, $permission, 'allowed to manage pages before a page is even looked up');
        $this->assertLessThan($document, $read);
    }

    public function testThePreviewIsNeverCachedOrIndexedAndLetsGoOfTheAdminSessionFirst(): void
    {
        $source = self::source('admin/page-preview.php');
        $document = (int) stripos($source, '<!doctype html>');

        foreach ([
            "header('Cache-Control: private, no-store, max-age=0');",
            "header('X-Robots-Tag: noindex, nofollow');",
            'session_write_close();',
        ] as $statement) {
            $position = strpos($source, $statement);

            $this->assertIsInt($position, $statement);
            $this->assertLessThan($document, $position, $statement . ' must run before any output');
        }
    }

    public function testThePreviewChangesNothing(): void
    {
        $source = self::source('admin/page-preview.php');

        foreach (['->update(', '->create(', '->delete(', '->updateOgImagePath(', 'SlugChangeRedirects', 'Csrf::'] as $needle) {
            $this->assertStringNotContainsString($needle, $source);
        }

        $this->assertDoesNotMatchRegularExpression('/\b(UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+[a-z_]+/', $source);
    }

    public function testThePreviewIsBuiltFromTheSamePiecesAsAPublicPageTemplate(): void
    {
        $preview = self::source('admin/page-preview.php');
        $template = self::source('pagina.php');

        foreach ([
            "/partials/page-head.php'",
            'SectionRegistry::collectPageAssets(',
            "/partials/page-assets.php'",
            "/partials/header.php'",
            'SectionRegistry::renderPage(',
            "/partials/footer.php'",
            "/partials/page-scripts.php'",
        ] as $piece) {
            $this->assertStringContainsString($piece, $preview, 'the preview renders ' . $piece);
            $this->assertStringContainsString($piece, $template, 'as pagina.php does');
        }
    }

    public function testNothingPublicLooksAtAPreviewOrAtTheAdminSession(): void
    {
        foreach ([
            'pagina.php', 'index.php', 'shop.php', 'diensten.php', 'portfolio.php', 'over-mij.php', 'contact.php',
            'sitemap.php', 'partials/page-head.php', 'partials/header.php', 'partials/footer.php',
        ] as $public) {
            $source = self::source($public);

            foreach (['AdminAuth', 'vvl_admin_session', "\$_GET['preview']", 'page-preview'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, $public . ' must not know about ' . $needle);
            }
        }
    }

    public function testADraftStillAnswersWithA404OnItsPublicAddress(): void
    {
        // The whole path is looked up (a nested page, docs/pages/NESTING.md),
        // and the page at its end through forSlug(), which only ever returns a
        // published page.
        $this->assertStringContainsString('PageContent::forPath(explode(\'/\', $slug))', self::source('pagina.php'));
        $this->assertMatchesRegularExpression(
            '/function forPath\(.*?self::forSlug\(/s',
            self::source('src/Service/PageContent.php')
        );

        foreach (['diensten.php', 'portfolio.php', 'over-mij.php', 'contact.php'] as $template) {
            $this->assertStringContainsString(
                '!\App\Service\PageContent::isPublished($page)',
                self::source($template),
                $template . ' must keep answering a draft with a 404'
            );
        }
    }

    public function testTheEditorOffersThePreviewAndTheSidebarKnowsTheScreen(): void
    {
        $editor = self::source('admin/page.php');

        $this->assertStringContainsString('href="/admin/page-preview.php?id=<?= $pageId ?>"', $editor);
        $this->assertStringContainsString("admin_te('page.preview')", $editor);

        $claimedBy = null;
        foreach (AdminNavigation::items() as $item) {
            if (in_array('page-preview.php', $item['scripts'], true)) {
                $claimedBy = $item['key'];
            }
        }

        $this->assertSame('pages', $claimedBy, 'the preview belongs to the Pagina\'s section');
    }

    public function testTheBarHasOneStylesheetThatOnlyThePreviewAsksFor(): void
    {
        $this->assertStringContainsString('Owner: admin/page-preview.php', self::source('assets/css/page-preview.css'));
        $this->assertStringContainsString(
            "PageAssets::requireStyle('assets/css/page-preview.css')",
            self::source('admin/page-preview.php')
        );

        foreach (['pagina.php', 'index.php', 'shop.php', 'partials/page-assets.php', 'src/Service/PageAssets.php'] as $other) {
            $this->assertStringNotContainsString('page-preview.css', self::source($other), $other);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;

/**
 * The preview of one content block, read from the source: what
 * admin/block-preview.php must do before it shows anything, what it leaves
 * out, and what keeps a preview from doing anything but being looked at.
 *
 * Tests\Service\BlockPreviewAccessTest proves the same promises over real
 * HTTP; this file keeps them true without a web server or a database, so
 * `fast` fails the moment a guard moves, a header goes or a block's markup
 * creeps into the screen. The samples themselves are
 * Tests\Service\BlockSampleContractTest.
 */
final class BlockPreviewContractTest extends TestCase
{
    private static function source(string $relativePath): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The PHP of the screen without its comments, for checks on what it does. */
    private static function code(string $relativePath): string
    {
        $code = '';
        foreach (token_get_all(self::source($relativePath)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    public function testThePreviewAsksThePageEditorsOwnGuardBeforeItLooksUpABlock(): void
    {
        $source = self::code('admin/block-preview.php');

        $login = strpos($source, 'AdminAuth::requireLogin();');
        $permission = strpos($source, "AdminAuth::requirePermission('pages.manage');");
        $lookup = strpos($source, 'BlockDefinitions::get(');
        $document = stripos($source, '<!doctype html>');

        $this->assertIsInt($login);
        $this->assertIsInt($permission);
        $this->assertIsInt($lookup);
        $this->assertIsInt($document);
        $this->assertLessThan($permission, $login, 'signed in first');
        $this->assertLessThan($lookup, $permission, 'allowed to manage pages before a block is even looked up');
        $this->assertLessThan($document, $lookup);
    }

    /**
     * The type from the request only ever hits or misses a registry key, and
     * everything that misses, a block without a sample included, is a 404.
     */
    public function testTheTypeIsOnlyEverARegistryKey(): void
    {
        $source = self::code('admin/block-preview.php');

        $this->assertStringContainsString("preg_match('/^[a-z][a-z0-9_]{0,63}$/', \$typeParam)", $source);
        $this->assertStringContainsString('BlockDefinitions::get($typeParam)', $source);
        $this->assertStringContainsString('if ($definition === null || $sample === null) {', $source);
        $this->assertStringContainsString('http_response_code(404);', $source);
        $this->assertStringNotContainsString('new $', $source, 'a class name from the request');
    }

    public function testThePreviewIsNeverCachedIndexedFramedElsewhereOrAbleToSendAForm(): void
    {
        $source = self::code('admin/block-preview.php');
        $document = (int) stripos($source, '<!doctype html>');

        foreach ([
            "header('Cache-Control: private, no-store, max-age=0');",
            "header('X-Robots-Tag: noindex, nofollow');",
            "header(\"Content-Security-Policy: form-action 'none'; frame-ancestors 'self'\");",
            'session_write_close();',
        ] as $statement) {
            $position = strpos($source, $statement);

            $this->assertIsInt($position, $statement);
            $this->assertLessThan($document, $position, $statement . ' must run before any output');
        }

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $source);
    }

    /**
     * The real block, asked for the real way: its assets through the same
     * call a public page's blocks go through, its markup through its own
     * definition. Nothing about any block is written in the screen.
     */
    public function testTheBlockBringsItsOwnMarkupAndAssets(): void
    {
        $source = self::code('admin/block-preview.php');

        $this->assertStringContainsString('SectionRegistry::collectBlockAssets($definition);', $source);
        $this->assertStringContainsString('$definition->renderSample($sample,', $source);
        $this->assertStringContainsString('PageAssets::renderStyles();', $source);
        $this->assertStringContainsString("require dirname(__DIR__) . '/partials/page-scripts.php';", $source);

        $this->assertDoesNotMatchRegularExpression('/render_section_[a-z_]+\(/', $source, 'a partial called by name');
        $this->assertDoesNotMatchRegularExpression('/<link[^>]+rel="stylesheet"/', $source);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=/', $source);
        $this->assertStringNotContainsString('<section', $source);

        $registry = self::code('src/Service/SectionRegistry.php');
        $this->assertStringContainsString('self::collectBlockAssets(self::definition($type));', $registry, 'a page and the preview ask for a block\'s assets the same way');
    }

    /**
     * No header and no footer: the page-view tracker runs in the header, and
     * the cookie banner comes with the head's asset partial. A preview counts
     * no visitor and has nothing covering the block.
     */
    public function testThePreviewCountsNoVisitorShowsNoBannerAndReadsNoContent(): void
    {
        $source = self::code('admin/block-preview.php');

        foreach (['partials/header.php', 'partials/footer.php', 'partials/page-assets.php', 'partials/page-head.php', 'PageViewTracker', 'cookie-consent'] as $shellPart) {
            $this->assertStringNotContainsString($shellPart, $source);
        }

        foreach (['Repository', 'Content::', 'PageContent', 'renderPage', '->render(', 'SiteSettings', 'Database'] as $contentRead) {
            $this->assertStringNotContainsString($contentRead, $source, "the preview reads stored content through {$contentRead}");
        }

        $tracker = self::source('partials/header.php');
        $this->assertStringContainsString('PageViewTracker::trackCurrentRequest();', $tracker, 'the tracker still lives in the header this screen leaves out');
    }

    public function testTheGuardScriptStopsLinksAndSubmitsBeforeABlockSeesThem(): void
    {
        $script = self::source('assets/js/block-preview.js');

        $this->assertStringContainsString('Owner: admin/block-preview.php', $script);
        $this->assertMatchesRegularExpression('/addEventListener\(\s*"click",[\s\S]*?closest\("a\[href\]"\)[\s\S]*?preventDefault\(\)[\s\S]*?true\s*\)/', $script);
        $this->assertMatchesRegularExpression('/addEventListener\(\s*"submit",[\s\S]*?preventDefault\(\);\s*event\.stopImmediatePropagation\(\);[\s\S]*?true\s*\)/', $script);
        $code = preg_replace('#/\*.*?\*/#s', '', $script) ?? '';
        $this->assertStringNotContainsString('fetch(', $code);
        $this->assertStringNotContainsString('innerHTML', $code);

        $this->assertStringContainsString("PageAssets::requireScript('assets/js/block-preview.js');", self::code('admin/block-preview.php'));
        $this->assertStringContainsString("PageAssets::requireStyle('assets/css/block-preview.css');", self::code('admin/block-preview.php'));
        $this->assertStringContainsString('Owner: admin/block-preview.php', self::source('assets/css/block-preview.css'));

        foreach (array_merge(glob(dirname(__DIR__, 2) . '/*.php') ?: [], glob(dirname(__DIR__, 2) . '/partials/*.php') ?: [], glob(dirname(__DIR__, 2) . '/src/Service/Blocks/*.php') ?: []) as $file) {
            $this->assertStringNotContainsString("'assets/js/block-preview.js'", (string) file_get_contents($file), basename($file) . ' asks for the preview\'s script');
            $this->assertStringNotContainsString("'assets/css/block-preview.css'", (string) file_get_contents($file), basename($file) . ' asks for the preview\'s stylesheet');
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Service\ItemGalleryContent;
use PHPUnit\Framework\TestCase;

/**
 * "Toon op homepage" is gone from the Portfolio (db/migrations/20260928210000,
 * MODULES.md "Portfolio"), read from the source: no code, screen, endpoint,
 * script, stylesheet or CMS text knows the flag or its homepage selection
 * any more, and a gallery scope "featured" does not exist. Only the
 * migrations that made and removed the columns still name them, as history.
 *
 * What a featured gallery became (a hand-picked list of the same projects,
 * db/migrations/20260928200000) is Tests\Install\PortfolioSelectionMigrationTest; that the editor has no
 * switch and an old form's `is_featured` is not read,
 * Tests\Module\PortfolioRelatedEditorHttpTest.
 */
final class PortfolioHomepageFlagRemovedTest extends TestCase
{
    private const FORGOTTEN = ['is_featured', 'featured_sort_order', 'SCOPE_FEATURED', 'findFeaturedItems', 'moveFeaturedItem', 'nextFeaturedSortOrder', 'move-featured-gallery-item', 'homepage_uitlichting', 'toon_homepage'];

    public function testNoCodeScreenEndpointOrScriptKnowsTheFlag(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            self::tree($root . '/src'),
            self::tree($root . '/admin'),
            self::tree($root . '/api'),
            self::tree($root . '/partials'),
            self::tree($root . '/assets/js'),
            self::tree($root . '/assets/css'),
        );
        self::assertGreaterThan(100, count($files), 'the whole application was read');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::FORGOTTEN as $name) {
                self::assertStringNotContainsString($name, $source, substr($file, strlen($root) + 1) . ' still knows ' . $name);
            }
        }

        self::assertFileDoesNotExist($root . '/api/admin/move-featured-gallery-item.php');
    }

    public function testNoCmsTextSpeaksOfIt(): void
    {
        foreach (['nl', 'en'] as $language) {
            $messages = require dirname(__DIR__, 2) . '/src/Service/Language/messages/' . $language . '.php';

            foreach (['portfolio.toon_homepage', 'help.portfolio.featured', 'portfolio.homepage_uitlichting', 'portfolio.volgorde_items_greep_uit', 'portfolio.homepage', 'portfolio.homepage_2', 'portfolio.wel_homepage', 'block_projects.scope_featured', 'block_projects.instance_featured'] as $key) {
                self::assertArrayNotHasKey($key, $messages, $language . ': ' . $key);
            }

            foreach ($messages as $key => $text) {
                self::assertStringNotContainsStringIgnoringCase('Toon op homepage', (string) $text, $language . ': ' . $key);
                self::assertStringNotContainsStringIgnoringCase('Show on the homepage', (string) $text, $language . ': ' . $key);
            }
        }
    }

    public function testAGalleryHasNoFeaturedScope(): void
    {
        self::assertSame(['all', 'category', 'manual'], ItemGalleryContent::SCOPES);
        self::assertFalse(ItemGalleryContent::isPortfolioScope('featured'));
    }

    /** @return list<string> */
    private static function tree(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['php', 'js', 'css'], true) && !str_contains($file->getPathname(), '/vendor/')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}

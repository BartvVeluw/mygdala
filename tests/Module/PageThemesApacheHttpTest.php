<?php

declare(strict_types=1);

namespace Tests\Module;

use App\Repository\PageRepository;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageService;
use App\Service\PageTranslation;
use PHPUnit\Framework\TestCase;
use Tests\Support\PageFixture;
use Tests\Support\PageThemeFixture;
use Tests\Support\TestEnvironment;

/**
 * One themed page over real Apache and .htaccess (the HTTP tier,
 * TESTING.md): php_test runs with the Paginathema's module pinned on and
 * shows the theme, at the Dutch and at the English address; php_cms runs the
 * same code and the same database with it pinned off and shows the same page
 * in the site theme — no attribute, no page-theme block, no theme font. The
 * in-process and built-in-server side of the same rules is
 * PageThemesRenderingHttpTest.
 */
final class PageThemesApacheHttpTest extends TestCase
{
    private const KEY = 'zz-pt-apache';
    private const SLUG_EN = 'zz-pt-apache-en';

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }
        if (!TestEnvironment::cmsOnlySiteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::cmsOnlyUnreachableMessage());
        }

        $this->removePage();
        PageThemeFixture::removeAll();
    }

    protected function tearDown(): void
    {
        $this->removePage();
        PageThemeFixture::removeAll();
    }

    public function testTheModuleDecidesWhetherTheStoredThemeIsShown(): void
    {
        $themeId = PageThemeFixture::create('Apache');
        $pageId = PageFixture::create(['content_key' => self::KEY, 'slug' => self::KEY, 'status' => PageContent::STATUS_PUBLISHED], 'Apache thema');
        PageLocalization::save($pageId, 'en', [PageTranslation::TITLE => 'Apache theme'], self::SLUG_EN);
        PageThemeFixture::assign($pageId, $themeId);
        PageContent::clearCache();

        foreach (['/' . self::KEY, '/en/' . self::SLUG_EN] as $path) {
            $on = $this->get(TestEnvironment::baseUrl() . $path);
            self::assertSame(200, $on['status'], $path);
            self::assertStringContainsString('<main id="main" data-page-theme="zz-test-apache">', $on['body'], $path);
            self::assertStringContainsString('main[data-page-theme="zz-test-apache"]{', $on['body'], $path);
            self::assertStringContainsString('family=Playfair+Display', $on['body'], $path);
        }

        $off = $this->get(TestEnvironment::cmsOnlyBaseUrl() . '/' . self::KEY);
        self::assertSame(200, $off['status']);
        self::assertStringContainsString('<main id="main">', $off['body']);
        self::assertStringNotContainsString('page-theme', $off['body']);
        self::assertStringNotContainsString('Playfair', $off['body']);

        self::assertSame($themeId, (int) (new PageRepository())->findById($pageId)['page_theme_id'], 'the stored choice is untouched');
    }

    /** @return array{status: int, body: string} */
    private function get(string $url): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);

        $body = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => $body];
    }

    private function removePage(): void
    {
        $page = (new PageRepository())->findByContentKey(self::KEY);
        if ($page !== null) {
            PageThemeFixture::assign((int) $page['id'], null);
            PageService::delete($page);
        }

        PageContent::clearCache();
        PageLocalization::clearCache();
    }
}

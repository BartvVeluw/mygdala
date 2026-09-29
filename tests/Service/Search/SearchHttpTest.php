<?php

declare(strict_types=1);

namespace Tests\Service\Search;

use App\Database;
use App\Repository\PageRepository;
use App\Repository\SiteLanguageRepository;
use App\Repository\SiteSettingRepository;
use App\Service\Language\SiteLanguages;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;
use App\Service\Search\SearchService;
use App\Service\SiteSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

/**
 * Site search over the real Apache of php_test (SEARCH.md): what only the
 * deployment shows, not what SearchService already proves in-process.
 *
 *   - /zoeken and /en/search are no files: they reach zoeken.php through the
 *     .htaccess catch-all and the dispatcher, per language;
 *   - the switch (Navigatie → "Zoeken tonen", site_settings.nav_search_enabled)
 *     is read per request: off, both routes and the live endpoint are 404;
 *     on, they answer;
 *   - whatever a visitor types comes back escaped only;
 *   - a query of several words finds a page this test made, under its own
 *     pretty URL, in each language.
 *
 * The setting is restored in tearDown(), and English is made an active site
 * language only when it was not, and put back as it was.
 */
final class SearchHttpTest extends TestCase
{
    private const TOKEN = 'zqhttpsearch';

    private ?string $storedSetting = null;

    /** null: English was already active; 'created' or 'activated': what setUp() did. */
    private ?string $englishChange = null;

    /** @var list<int> */
    private array $pageIds = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $statement = Database::connection()->prepare('SELECT setting_value FROM site_settings WHERE setting_key = :key');
        $statement->execute(['key' => SearchService::SETTING]);
        $value = $statement->fetchColumn();
        $this->storedSetting = $value === false ? null : (string) $value;

        $languages = new SiteLanguageRepository();
        $english = $languages->findByCode('en');
        if ($english === null) {
            $languages->create('en', 'English', 'English');
            $this->englishChange = 'created';
        } elseif (!(bool) $english['is_active']) {
            $languages->activate('en');
            $this->englishChange = 'activated';
        }
        SiteLanguages::clearCache();
    }

    protected function tearDown(): void
    {
        $db = Database::connection();
        $pages = new PageRepository();
        foreach ($this->pageIds as $id) {
            $pages->delete($id);
        }
        $this->pageIds = [];

        $db->prepare('DELETE FROM site_settings WHERE setting_key = :key')->execute(['key' => SearchService::SETTING]);
        if ($this->storedSetting !== null) {
            (new SiteSettingRepository())->upsertMany([SearchService::SETTING => $this->storedSetting]);
        }

        $languages = new SiteLanguageRepository();
        if ($this->englishChange === 'created') {
            $languages->delete('en');
        } elseif ($this->englishChange === 'activated') {
            $languages->deactivate('en');
        }
        $this->englishChange = null;

        SiteSettings::clearCache();
        SiteLanguages::clearCache();
        PageContent::clearCache();
        PageLocalization::clearCache();
    }

    public function testWithSearchOffBothResultRoutesAndTheLiveEndpointAreNotFound(): void
    {
        $this->switchSearch(false);

        foreach (['/zoeken?q=tafel', '/en/search?q=table', '/api/search.php?q=tafel&lang=nl'] as $path) {
            $response = $this->get($path);
            $this->assertSame(404, $response['status'], $path);
            $this->assertStringNotContainsString('search-page__form', $response['body'], $path);
        }

        // Nor does the header offer it.
        $this->assertStringNotContainsString(SearchService::SCRIPT, $this->get('/')['body']);
    }

    public function testWithSearchOnTheResultPageAnswersInEachLanguage(): void
    {
        $this->switchSearch(true);

        $dutch = $this->get('/zoeken?q=tafel');
        $this->assertSame(200, $dutch['status']);
        $this->assertStringContainsString('<html lang="nl"', $dutch['body']);
        $this->assertStringContainsString('action="/zoeken"', $dutch['body']);
        $this->assertMatchesRegularExpression('#<meta name="robots" content="noindex#', $dutch['body']);

        $english = $this->get('/en/search?q=table');
        $this->assertSame(200, $english['status']);
        $this->assertStringContainsString('<html lang="en"', $english['body']);
        $this->assertStringContainsString('action="/en/search"', $english['body']);

        // The Dutch word under the English prefix is no page of its own: the
        // dispatcher sends it to the English word, query kept.
        $mixed = $this->get('/en/zoeken?q=table');
        $this->assertSame(301, $mixed['status']);
        $this->assertSame('/en/search?q=table', $mixed['location']);
    }

    public function testTheLiveEndpointFollowsTheSwitch(): void
    {
        $this->switchSearch(true);

        $response = $this->get('/api/search.php?q=tafel&lang=nl');

        $this->assertSame(200, $response['status']);
        $this->assertIsArray(json_decode($response['body'], true), $response['body']);
    }

    public function testWhatTheVisitorTypedComesBackEscapedOnly(): void
    {
        $this->switchSearch(true);
        $typed = '<script>alert(1)</script>"\'';

        foreach (['/zoeken', '/en/search'] as $route) {
            $response = $this->get($route . '?q=' . rawurlencode($typed));

            $this->assertSame(200, $response['status'], $route);
            $this->assertStringNotContainsString('<script>alert(1)', $response['body'], $route);
            $this->assertStringNotContainsString('"\'', $response['body'], $route . ': the quotes never close an attribute');
            $this->assertStringContainsString('value="&lt;script&gt;alert(1)&lt;/script&gt;&quot;&#039;"', $response['body'], $route);
        }

        $live = $this->get('/api/search.php?lang=nl&q=' . rawurlencode($typed));
        $this->assertSame(200, $live['status']);
        $this->assertStringNotContainsString('<script>', $live['body'], 'the JSON escapes it too');
    }

    public function testSeveralWordsFindAPageInEachLanguageUnderItsPrettyUrl(): void
    {
        $this->switchSearch(true);
        $slug = 'zq-search-http-' . bin2hex(random_bytes(4));
        $id = (new PageRepository())->create(['content_key' => $slug, 'slug' => $slug, 'status' => PageContent::STATUS_PUBLISHED]);
        $this->pageIds[] = $id;
        PageLocalization::save($id, 'nl', [PageTranslation::TITLE => 'Eikenhouten ' . self::TOKEN . ' tafel']);
        PageLocalization::save($id, 'en', [PageTranslation::TITLE => 'Oak ' . self::TOKEN . ' table'], $slug . '-en');

        // Two words, out of order, neither the whole title.
        $dutch = $this->get('/zoeken?q=' . rawurlencode('tafel ' . self::TOKEN));
        $this->assertSame(200, $dutch['status']);
        $this->assertStringContainsString('href="/' . $slug . '"', $dutch['body']);
        $this->assertStringContainsString('Eikenhouten ' . self::TOKEN . ' tafel', $dutch['body']);

        $english = $this->get('/en/search?q=' . rawurlencode('table ' . self::TOKEN));
        $this->assertSame(200, $english['status']);
        $this->assertStringContainsString('href="/en/' . $slug . '-en"', $english['body']);
        $this->assertStringContainsString('Oak ' . self::TOKEN . ' table', $english['body']);

        // The found address is a real page on this server, per language.
        $this->assertSame(200, $this->get('/' . $slug)['status']);
        $this->assertSame(200, $this->get('/en/' . $slug . '-en')['status']);

        // A word the page does not have finds nothing.
        $none = $this->get('/zoeken?q=' . rawurlencode('stoel ' . self::TOKEN));
        $this->assertStringNotContainsString('href="/' . $slug . '"', $none['body']);
    }

    // ---------------------------------------------------------------- helpers

    private function switchSearch(bool $on): void
    {
        (new SiteSettingRepository())->upsertMany([SearchService::SETTING => $on ? '1' : '0']);
        SiteSettings::clearCache();
    }

    /** @return array{status: int, body: string, location: string} */
    private function get(string $path): array
    {
        $location = '';
        $handle = curl_init(TestEnvironment::baseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'location' => $location];
    }
}

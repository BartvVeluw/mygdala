<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontStorage;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\TestCase;
use Tests\Support\FontLibraryFixture;
use Tests\Support\TestEnvironment;

/**
 * The Font Library's files over real Apache and assets/fonts/library/.htaccess
 * (the HTTP tier, TESTING.md) — what PHP's built-in server cannot show:
 *
 *   - a stored font is served with its font MIME type, `nosniff` and a year
 *     of immutable caching (a replaced file gets a new name, so this is
 *     safe);
 *   - anything in the folder that is not a generated font name is refused,
 *     a script included, before any handler runs it;
 *   - a missing file is a 404, and the page that names it still renders;
 *   - a page that uses the family names exactly that URL.
 */
final class FontLibraryApacheHttpTest extends TestCase
{
    private string $directory = '';

    /** @var list<array<string, mixed>> */
    private array $roles = [];

    /** @var list<string> */
    private array $strays = [];

    protected function setUp(): void
    {
        if (!TestEnvironment::siteIsReachable()) {
            $this->markTestSkipped(TestEnvironment::unreachableMessage());
        }

        $this->directory = dirname(__DIR__, 2) . '/' . FontStorage::PUBLIC_PREFIX;
        $this->roles = FontLibraryFixture::rolesSnapshot();
        FontLibraryFixture::removeAll($this->roles);
    }

    protected function tearDown(): void
    {
        foreach ($this->strays as $stray) {
            @unlink($this->directory . $stray);
        }

        FontLibraryFixture::removeAll($this->roles);
        ThemeSettings::clearCache();
    }

    public function testAStoredFontIsServedWithItsFontType(): void
    {
        $id = FontLibraryFixture::create('Apache', ['400'], 'sans', new FontStorage());
        $file = FontLibrary::family($id)['variants'][0]['file_name'];

        $response = $this->get('/assets/fonts/library/' . $file);

        self::assertSame(200, $response['status']);
        self::assertMatchesRegularExpression('#^Content-Type: font/woff2\s*$#mi', $response['headers']);

        foreach (['woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf'] as $extension => $type) {
            $name = bin2hex(random_bytes(16)) . '.' . $extension;
            file_put_contents($this->directory . $name, 'x');
            $this->strays[] = $name;
            self::assertMatchesRegularExpression('#^Content-Type: ' . preg_quote($type, '#') . '\s*$#mi', $this->get('/assets/fonts/library/' . $name)['headers'], $extension);
        }
    }

    /**
     * nosniff and the year of caching need mod_headers, like the SVG rule in
     * the root .htaccess. The folder's rules always declare them, guarded the
     * same way; on a server that has mod_headers (the SVG rule proves it)
     * they must also arrive. The HTTP tier's image has no mod_headers, so
     * there only the declaration is proven — never a skip.
     */
    public function testAStoredFontIsNotSniffedAndCachedForAYear(): void
    {
        $rules = (string) file_get_contents($this->directory . '.htaccess');
        self::assertMatchesRegularExpression(
            '#<IfModule mod_headers\.c>\s*Header set X-Content-Type-Options "nosniff"\s*Header set Cache-Control "public, max-age=31536000, immutable"\s*</IfModule>#',
            $rules
        );

        $probe = 'zz-font-probe-' . bin2hex(random_bytes(4)) . '.svg';
        $probePath = dirname(__DIR__, 2) . '/assets/media/' . $probe;
        file_put_contents($probePath, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        try {
            $svg = $this->get('/assets/media/' . $probe);
        } finally {
            @unlink($probePath);
        }

        $id = FontLibraryFixture::create('Cache', ['400'], 'sans', new FontStorage());
        $response = $this->get('/assets/fonts/library/' . FontLibrary::family($id)['variants'][0]['file_name']);

        if (preg_match('#^X-Content-Type-Options:#mi', $svg['headers']) !== 1) {
            // No mod_headers on this server: then nothing else may set a
            // shorter cache either.
            self::assertDoesNotMatchRegularExpression('#^Cache-Control:#mi', $response['headers']);

            return;
        }

        self::assertMatchesRegularExpression('#^X-Content-Type-Options: nosniff\s*$#mi', $response['headers']);
        self::assertMatchesRegularExpression('#^Cache-Control: public, max-age=31536000, immutable\s*$#mi', $response['headers']);
    }

    public function testNothingButAGeneratedFontNameIsServed(): void
    {
        foreach (['evil.php' => '<?php echo "ran";', 'notes.txt' => 'x', 'Roboto-Regular.ttf' => 'x', bin2hex(random_bytes(16)) . '.svg' => '<svg/>'] as $name => $bytes) {
            file_put_contents($this->directory . $name, $bytes);
            $this->strays[] = $name;

            $response = $this->get('/assets/fonts/library/' . $name);
            self::assertSame(403, $response['status'], $name);
            self::assertStringNotContainsString('ran', $response['body'], $name);
        }

        self::assertSame(403, $this->get('/assets/fonts/library/.htaccess')['status']);
    }

    public function testAMissingFileIsA404AndThePageStillRenders(): void
    {
        $id = FontLibraryFixture::create('Kwijt', ['400']);
        ThemeSettings::save(['body_font_family_id' => (string) $id]);
        $file = FontLibrary::family($id)['variants'][0]['file_name'];

        self::assertSame(404, $this->get('/assets/fonts/library/' . $file)['status']);

        $home = $this->get('/');
        self::assertSame(200, $home['status']);
        self::assertStringContainsString('url("/assets/fonts/library/' . $file . '")', $home['body']);
        self::assertStringContainsString("--font-body: 'mygdala-font-" . $id . "', " . FontLibrary::CATEGORIES['sans'], $home['body']);
    }

    /** @return array{status: int, headers: string, body: string} */
    private function get(string $path): array
    {
        $handle = curl_init(TestEnvironment::baseUrl() . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
    }
}

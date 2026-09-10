<?php

declare(strict_types=1);

namespace Tests\Service\Personalization;

use App\Repository\PersonalizationFontRepository;
use App\Service\Personalization\PersonalizationFonts;
use App\Service\Personalization\PersonalizationFontUploader;
use PHPUnit\Framework\TestCase;
use Tests\Support\PersonalizationFontFixture;

/**
 * The GLOBAL engraving font library: the registry
 * (App\Service\Personalization\PersonalizationFonts, backed by
 * `personalization_fonts`) and the uploader that validates a font file
 * before it can ever get into it.
 *
 * The registry half runs against the real dev database, like every other
 * repository test here — "an inactive font disappears from the whole shop"
 * is a statement about rows, not about a mock. Every fixture font uses an
 * obviously-fake key prefix and is removed again in tearDown().
 *
 * The uploader half needs no database at all: it is a pure file-validation
 * class, and the tests hand it real byte signatures rather than a mock, since
 * the signature check IS the thing worth proving.
 */
final class PersonalizationFontLibraryTest extends TestCase
{
    private PersonalizationFontFixture $fonts;

    /** @var list<string> absolute paths written by a test */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->fonts = new PersonalizationFontFixture();
        PersonalizationFonts::clearCache();
    }

    protected function tearDown(): void
    {
        $this->fonts->remove();

        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        $this->tempFiles = [];

        PersonalizationFonts::clearCache();
    }

    /* ------------------------------------------------------------------ */
    /* The registry                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * The five keys the Phase 2 code registry used still exist, still under
     * the same keys — which is what keeps every zone's stored font list and
     * every historical order's `font_key` meaningful.
     */
    public function testTheBuiltInFontsSurvivedTheMoveIntoTheDatabase(): void
    {
        foreach (['quattrocento_sans', 'trirong', 'georgia', 'arial', 'courier'] as $key) {
            $this->assertTrue(PersonalizationFonts::isValid($key), $key . ' must still exist');

            $record = PersonalizationFonts::record($key);
            $this->assertNotNull($record);
            $this->assertSame('builtin', $record['source']);
            $this->assertNotSame('', $record['stack']);
        }
    }

    public function testAnActiveFontIsOfferedAndAnInactiveOneIsNot(): void
    {
        $active = $this->fonts->create('Fixture Aan', true);
        $inactive = $this->fonts->create('Fixture Uit', false);

        $this->assertContains($active['font_key'], PersonalizationFonts::activeKeys());
        $this->assertNotContains($inactive['font_key'], PersonalizationFonts::activeKeys());

        // Both are still VALID keys, though: a deactivated font must stay
        // resolvable so a historical order can still name it.
        $this->assertTrue(PersonalizationFonts::isValid($inactive['font_key']));
        $this->assertFalse(PersonalizationFonts::isActive($inactive['font_key']));
        $this->assertSame('Fixture Uit', PersonalizationFonts::label($inactive['font_key']));
    }

    public function testAFontCanBeDeactivatedAndReactivated(): void
    {
        $font = $this->fonts->create('Fixture Schakelaar', true);

        $this->fonts->setActive($font['id'], false);
        $this->assertNotContains($font['font_key'], PersonalizationFonts::activeKeys());

        $this->fonts->setActive($font['id'], true);
        $this->assertContains($font['font_key'], PersonalizationFonts::activeKeys());
    }

    /**
     * Library ORDER is not cosmetic: the first active font is what a text
     * zone starts on, which is how the owner picks a default without
     * configuring anything per product.
     */
    public function testTheFirstActiveFontInLibraryOrderIsTheDefault(): void
    {
        $keys = PersonalizationFonts::activeKeys();

        $this->assertNotEmpty($keys);
        $this->assertSame($keys[0], PersonalizationFonts::fallbackKey());
    }

    public function testAnArbitraryKeyIsNeverValidAndNeverSurvivesSanitising(): void
    {
        $this->assertFalse(PersonalizationFonts::isValid('comic_sans_hacker'));
        $this->assertFalse(PersonalizationFonts::isValid(42));
        $this->assertFalse(PersonalizationFonts::isValid(null));

        $this->assertSame(['trirong'], PersonalizationFonts::sanitize('trirong,comic_sans_hacker'));
        $this->assertSame([], PersonalizationFonts::sanitize(['nope', 42, null, ['x']]));
    }

    /**
     * A submitted font is only ever accepted when it is in the list the
     * customer was actually offered.
     */
    public function testASubmittedFontIsResolvedAgainstTheOfferedListOnly(): void
    {
        $allowed = ['trirong', 'georgia'];

        $this->assertSame('georgia', PersonalizationFonts::resolveSubmitted('georgia', $allowed, 'trirong'));
        $this->assertSame('trirong', PersonalizationFonts::resolveSubmitted('courier', $allowed, 'trirong'));
        $this->assertSame('trirong', PersonalizationFonts::resolveSubmitted(null, $allowed, 'trirong'));
        $this->assertSame('trirong', PersonalizationFonts::resolveSubmitted(['array'], $allowed, 'trirong'));
    }

    /* ------------------------------------------------------------------ */
    /* Self-hosted faces                                                   */
    /* ------------------------------------------------------------------ */

    public function testAnUploadedFontGetsItsOwnNamespacedFamilyAndFontFace(): void
    {
        $font = $this->fonts->createUpload('Fixture Upload', 'assets/fonts/personalization/abc123.woff2', 'woff2');

        $record = PersonalizationFonts::record($font['font_key']);
        $family = PersonalizationFonts::familyName($font['font_key']);

        $this->assertSame('upload', $record['source']);
        $this->assertStringContainsString("'" . $family . "'", $record['stack']);
        // Always ends in a real fallback family, so a face that fails to load
        // degrades to something sane.
        $this->assertStringContainsString('Quattrocento Sans', $record['stack']);

        $css = PersonalizationFonts::faceCss([$font['font_key']]);

        $this->assertStringContainsString("@font-face{font-family:'" . $family . "'", $css);
        $this->assertStringContainsString("url('/assets/fonts/personalization/abc123.woff2')", $css);
        $this->assertStringContainsString("format('woff2')", $css);
        $this->assertStringContainsString('font-display:swap', $css);
    }

    public function testABuiltInFontContributesNoFontFaceAtAll(): void
    {
        $this->assertSame('', PersonalizationFonts::faceCss(['trirong', 'georgia']));
    }

    public function testFontsAreNeverLoadedFromARemoteService(): void
    {
        $font = $this->fonts->createUpload('Fixture Lokaal', 'assets/fonts/personalization/xyz.woff', 'woff');
        $css = PersonalizationFonts::faceCss([$font['font_key']]);

        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
        $this->assertStringContainsString("url('/assets/", $css);
    }

    /* ------------------------------------------------------------------ */
    /* Deletion safety                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * A font nothing has ever been ordered in can be deleted; the CMS asks
     * this before it does anything destructive.
     */
    public function testAFontNoOrderUsedIsNotReportedAsUsed(): void
    {
        $font = $this->fonts->create('Fixture Ongebruikt', true);

        $this->assertFalse((new PersonalizationFontRepository())->isUsedByOrder($font['font_key']));
    }

    /**
     * ...and the guard itself really looks at the order table, so a font that
     * IS referenced cannot be deleted by accident.
     */
    public function testTheDeleteEndpointRefusesAFontAnOrderUsed(): void
    {
        $endpoint = (string) file_get_contents(dirname(__DIR__, 3) . '/api/admin/delete-personalization-font.php');

        $this->assertStringContainsString('isUsedByOrder(', $endpoint);
        $this->assertStringContainsString('niet actief', $endpoint, 'the refusal must offer deactivation instead');

        $guardPos = strpos($endpoint, 'isUsedByOrder(');
        $deletePos = strpos($endpoint, '$repository->delete(');
        $this->assertIsInt($guardPos);
        $this->assertIsInt($deletePos);
        $this->assertLessThan($deletePos, $guardPos, 'the guard must run before the delete');
    }

    /* ------------------------------------------------------------------ */
    /* The uploader                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function fakeUpload(string $filename, string $bytes): array
    {
        $path = sys_get_temp_dir() . '/zz-test-font-' . bin2hex(random_bytes(6));
        file_put_contents($path, $bytes);
        $this->tempFiles[] = $path;

        return [
            'name' => $filename,
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($bytes),
        ];
    }

    public function testEveryBrowserReadyFontFormatIsAccepted(): void
    {
        $uploader = new PersonalizationFontUploader(sys_get_temp_dir());

        $cases = [
            'a.woff2' => "wOF2\x00\x00\x00\x00padding",
            'b.woff' => "wOFF\x00\x00\x00\x00padding",
            'c.ttf' => "\x00\x01\x00\x00padding",
            'd.otf' => "OTTO\x00\x00\x00\x00padding",
        ];

        foreach ($cases as $name => $bytes) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $this->assertSame(
                $extension,
                $uploader->validate($this->fakeUpload($name, $bytes), false),
                $name . ' must be accepted'
            );
        }

        $this->assertSame(['woff2', 'woff', 'ttf', 'otf'], PersonalizationFontUploader::allowedExtensions());
    }

    public function testAFileThatIsNotAFontIsRejectedWhateverItIsCalled(): void
    {
        $uploader = new PersonalizationFontUploader(sys_get_temp_dir());

        // A PHP script renamed to .woff2 is the case that matters.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/geen geldig WOFF2/i');

        $uploader->validate($this->fakeUpload('evil.woff2', "<?php echo 'pwned';"), false);
    }

    public function testADisallowedExtensionIsRejectedBeforeAnythingIsRead(): void
    {
        $uploader = new PersonalizationFontUploader(sys_get_temp_dir());

        foreach (['font.eot', 'font.svg', 'fonts.zip', 'font.php', 'font'] as $name) {
            try {
                $uploader->validate($this->fakeUpload($name, "wOF2\x00\x00\x00\x00"), false);
                $this->fail($name . ' must be rejected');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('WOFF2', $e->getMessage());
            }
        }
    }

    public function testAnOversizedOrEmptyFontIsRejected(): void
    {
        $uploader = new PersonalizationFontUploader(sys_get_temp_dir());

        $file = $this->fakeUpload('big.woff2', "wOF2\x00\x00\x00\x00");
        $file['size'] = PersonalizationFontUploader::MAX_BYTES + 1;

        try {
            $uploader->validate($file, false);
            $this->fail('an oversized font must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('te groot', $e->getMessage());
        }

        $empty = $this->fakeUpload('empty.woff2', '');
        $empty['size'] = 0;

        try {
            $uploader->validate($empty, false);
            $this->fail('an empty font must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('leeg', $e->getMessage());
        }
    }

    public function testAMissingUploadIsRejectedWithAnAdminFacingMessage(): void
    {
        $uploader = new PersonalizationFontUploader(sys_get_temp_dir());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Kies een lettertypebestand.');

        $uploader->validate(['error' => UPLOAD_ERR_NO_FILE], false);
    }
}

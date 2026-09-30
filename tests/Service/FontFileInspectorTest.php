<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Language\AdminTranslator;
use App\Service\Theme\FontFileInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\FontFileFixture;

/**
 * App\Service\Theme\FontFileInspector: what the Font Library accepts as a
 * font file, and every way a file is refused — no database, no server.
 *
 *   - WOFF2, WOFF, TTF and OTF are accepted, and the format comes from the
 *     BYTES (an OTF named .ttf is an OTF);
 *   - a non-font with a font's name, a wrong extension, a browser MIME that
 *     says something else, a server-sniffed image, an empty file, a file over
 *     the limit and a collection are refused, each with its own message;
 *   - structure: a truncated directory, a record outside the file, a missing
 *     table, a wrong head magic, a WOFF length that lies, a bad WOFF2 number
 *     and a WOFF2 stream shorter than declared;
 *   - the client's file name never becomes more than a display name.
 */
final class FontFileInspectorTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function validFonts(): array
    {
        return [
            'woff2' => ['Roboto-Regular.woff2', FontFileFixture::woff2(), 'woff2'],
            'woff' => ['Roboto-Regular.woff', FontFileFixture::woff(), 'woff'],
            'ttf' => ['Roboto-Regular.ttf', FontFileFixture::ttf(), 'ttf'],
            'otf' => ['Roboto-Regular.otf', FontFileFixture::otf(), 'otf'],
            'OTF bytes named .ttf' => ['Mixed.ttf', FontFileFixture::otf(), 'otf'],
            'TTF bytes named .otf' => ['Mixed.otf', FontFileFixture::ttf(), 'ttf'],
            'upper-case extension' => ['ROBOTO.WOFF2', FontFileFixture::woff2(), 'woff2'],
        ];
    }

    #[DataProvider('validFonts')]
    public function testAValidFontIsAcceptedAsTheFormatItReallyIs(string $name, string $bytes, string $format): void
    {
        self::assertSame($format, $this->inspect($name, $bytes));
    }

    public function testTheBrowsersUsualFontTypesAreAccepted(): void
    {
        foreach (['', 'application/octet-stream', 'font/ttf', 'font/woff2', 'application/x-font-ttf', 'application/vnd.oasis.opendocument.formula-template'] as $type) {
            self::assertSame('ttf', $this->inspect('a.ttf', FontFileFixture::ttf(), $type), $type);
        }
    }

    public function testRandomBytesWithAFontNameAreNotAFont(): void
    {
        $this->assertRefused('fonts.error_not_a_font', 'Evil.woff2', random_bytes(2048));
    }

    public function testAnImageWithAFontNameIsRefusedOnItsBytes(): void
    {
        $this->assertRefused('fonts.error_not_a_font', 'Photo.woff2', FontFileFixture::png());
    }

    /** @return array<string, array{string}> */
    public static function refusedExtensions(): array
    {
        return [
            'php' => ['shell.php'],
            'svg font' => ['icons.svg'],
            'zip' => ['Roboto.zip'],
            'eot' => ['old.eot'],
            'collection' => ['family.ttc'],
            'double extension' => ['font.woff2.php'],
            'none' => ['woff2'],
        ];
    }

    #[DataProvider('refusedExtensions')]
    public function testOnlyTheFourExtensionsAreAccepted(string $name): void
    {
        $this->assertRefused('fonts.error_extension', $name, FontFileFixture::woff2());
    }

    public function testAnExtensionThatDisagreesWithTheBytesIsNamed(): void
    {
        $this->assertRefused('fonts.error_wrong_extension', 'Roboto.woff2', FontFileFixture::woff(), '', ['format' => 'WOFF']);
        $this->assertRefused('fonts.error_wrong_extension', 'Roboto.woff', FontFileFixture::ttf(), '', ['format' => 'TTF']);
    }

    /** @return array<string, array{string}> */
    public static function refusedClientTypes(): array
    {
        return [
            'html' => ['text/html'],
            'php' => ['application/x-php'],
            'script' => ['application/javascript'],
            'image' => ['image/png'],
            'svg' => ['image/svg+xml'],
            'zip' => ['application/zip'],
            'plain text' => ['text/plain'],
        ];
    }

    #[DataProvider('refusedClientTypes')]
    public function testABrowserTypeThatSaysSomethingElseIsRefused(string $type): void
    {
        $this->assertRefused('fonts.error_not_a_font', 'Roboto.woff2', FontFileFixture::woff2(), $type);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->assertRefused('fonts.error_empty', 'Empty.woff2', '');
    }

    public function testAFileOverTheLimitIsRefused(): void
    {
        $bytes = FontFileFixture::ttf();
        $this->assertRefused('fonts.error_too_large', 'Huge.ttf', $bytes . str_repeat("\x00", FontFileInspector::MAX_BYTES - strlen($bytes) + 1), '', ['max' => 5]);
    }

    public function testPhpsOwnSizeLimitIsTheSameMessage(): void
    {
        $this->expectExceptionMessage(AdminTranslator::trans('fonts.error_too_large', ['file' => 'Huge.ttf', 'max' => 5]));
        (new FontFileInspector())->inspectUpload(['name' => 'Huge.ttf', 'error' => UPLOAD_ERR_INI_SIZE], false);
    }

    public function testNoFileAndAFailedUploadAreRefused(): void
    {
        try {
            (new FontFileInspector())->inspectUpload(['error' => UPLOAD_ERR_NO_FILE], false);
            self::fail('no file must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame(AdminTranslator::trans('fonts.error_no_file'), $e->getMessage());
        }

        try {
            (new FontFileInspector())->inspectUpload(['name' => 'a.ttf', 'error' => UPLOAD_ERR_PARTIAL], false);
            self::fail('a partial upload must be refused');
        } catch (\RuntimeException $e) {
            self::assertSame(AdminTranslator::trans('fonts.error_upload_failed', ['file' => 'a.ttf']), $e->getMessage());
        }
    }

    public function testAFileThatDidNotArriveAsAnUploadIsRefused(): void
    {
        $upload = FontFileFixture::upload('Roboto.ttf', FontFileFixture::ttf());
        $this->paths[] = $upload['tmp_name'];

        $this->expectExceptionMessage(AdminTranslator::trans('fonts.error_upload_failed', ['file' => 'Roboto.ttf']));
        (new FontFileInspector())->inspectUpload($upload);
    }

    public function testACollectionIsRefused(): void
    {
        $this->assertRefused('fonts.error_not_a_font', 'Family.ttf', FontFileFixture::ttc());
    }

    /** @return array<string, array{string, string}> */
    public static function brokenFonts(): array
    {
        return [
            'truncated directory' => ['truncated', 'a.ttf'],
            'record outside the file' => ['outside', 'a.ttf'],
            'no cmap' => ['no_cmap', 'a.ttf'],
            'head magic' => ['head_magic', 'a.ttf'],
            'woff length' => ['woff_length', 'a.woff'],
            'woff2 number' => ['woff2_base128', 'a.woff2'],
            'woff2 stream' => ['woff2_stream', 'a.woff2'],
        ];
    }

    #[DataProvider('brokenFonts')]
    public function testADamagedFontIsRefused(string $rule, string $name): void
    {
        $this->assertRefused('fonts.error_damaged', $name, FontFileFixture::broken($rule));
    }

    public function testTheClientNameIsOnlyEverADisplayName(): void
    {
        self::assertSame('evil.woff2', FontFileInspector::displayName('../../../etc/evil.woff2'));
        self::assertSame('evil.woff2', FontFileInspector::displayName('C:\\Windows\\evil.woff2'));
        self::assertSame('evil.woff2', FontFileInspector::displayName("ev\x00il.woff2"));
        self::assertSame('lettertype', FontFileInspector::displayName('/'));
        self::assertSame(255, mb_strlen(FontFileInspector::displayName(str_repeat('a', 300) . '.ttf')));

        // A path in the name changes nothing about the answer: the bytes decide.
        self::assertSame('woff2', $this->inspect('../../assets/css/core.woff2', FontFileFixture::woff2()));
    }

    public function testAMessageNamesTheFileWithoutItsPath(): void
    {
        try {
            $this->inspect('../../x/<b>Evil.woff2', FontFileFixture::png());
            self::fail('an image must be refused');
        } catch (\RuntimeException $e) {
            // Plain text; the screen escapes it (admin/font-family.php).
            self::assertStringContainsString('"<b>Evil.woff2"', $e->getMessage());
            self::assertStringNotContainsString('../', $e->getMessage());
        }
    }

    public function testTheFormatHintsAreTheCssFormatNames(): void
    {
        self::assertSame(['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'], FontFileInspector::FORMATS);
    }

    private function inspect(string $name, string $bytes, string $type = ''): string
    {
        $path = FontFileFixture::file($bytes);
        $this->paths[] = $path;

        return (new FontFileInspector())->inspectUpload(
            ['name' => $name, 'type' => $type, 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)],
            false
        );
    }

    /** @param array<string, string|int> $replace */
    private function assertRefused(string $key, string $name, string $bytes, string $type = '', array $replace = []): void
    {
        try {
            $this->inspect($name, $bytes, $type);
            self::fail($name . ' must be refused with ' . $key);
        } catch (\RuntimeException $e) {
            self::assertSame(AdminTranslator::trans($key, ['file' => FontFileInspector::displayName($name)] + $replace), $e->getMessage());
        }
    }
}

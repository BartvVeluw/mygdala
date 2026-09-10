<?php

declare(strict_types=1);

namespace Tests\Service\Personalization;

use App\Service\Personalization\PersonalizationUploadValidator;
use PHPUnit\Framework\TestCase;

/**
 * The filename-handling half of the upload validator, tested directly.
 *
 * validate() itself insists on a real PHP upload (is_uploaded_file()), which
 * by design cannot be faked from a unit test — that path is covered
 * end-to-end over real HTTP by
 * tests/Service/PersonalizationUploadHttpTest.php, which posts genuine
 * multipart bodies. What matters here is the rule that the customer's
 * filename is DISPLAY METADATA and can never become a path.
 */
final class PersonalizationUploadValidatorTest extends TestCase
{
    public function testAnOrdinaryFilenameSurvivesIntact(): void
    {
        $this->assertSame(
            'mijn logo.png',
            PersonalizationUploadValidator::sanitizeOriginalFilename('mijn logo.png', 'png')
        );
    }

    /**
     * The stored filename is generated server-side from a random token, so
     * this value never reaches a filesystem call — but it is still stripped
     * of every path component, because a name that LOOKS like a path is a
     * trap waiting for a future caller.
     */
    public function testEveryPathComponentIsStripped(): void
    {
        $this->assertSame('passwd', PersonalizationUploadValidator::sanitizeOriginalFilename('../../etc/passwd', 'png'));
        $this->assertSame('logo.png', PersonalizationUploadValidator::sanitizeOriginalFilename('/var/www/logo.png', 'png'));
        $this->assertSame('logo.png', PersonalizationUploadValidator::sanitizeOriginalFilename('C:\\Users\\bart\\logo.png', 'png'));
        $this->assertSame('logo.png', PersonalizationUploadValidator::sanitizeOriginalFilename('..\\..\\logo.png', 'png'));
    }

    public function testATraversalOnlyNameFallsBackToAGenericName(): void
    {
        $this->assertSame('afbeelding.png', PersonalizationUploadValidator::sanitizeOriginalFilename('..', 'png'));
        $this->assertSame('afbeelding.png', PersonalizationUploadValidator::sanitizeOriginalFilename('.', 'png'));
        $this->assertSame('afbeelding.jpg', PersonalizationUploadValidator::sanitizeOriginalFilename('', 'jpg'));
        $this->assertSame('afbeelding.jpg', PersonalizationUploadValidator::sanitizeOriginalFilename(null, 'jpg'));
        $this->assertSame('afbeelding.png', PersonalizationUploadValidator::sanitizeOriginalFilename(12345, 'png'));
    }

    /**
     * The name ends up inside a quoted Content-Disposition header on the
     * admin download endpoint; a newline or a quote in it must never be able
     * to reach that header.
     */
    public function testControlCharactersAndQuotesAreRemoved(): void
    {
        $sanitized = PersonalizationUploadValidator::sanitizeOriginalFilename(
            "lo\r\ngo\"; filename=\"evil.php\x00.png",
            'png'
        );

        $this->assertStringNotContainsString("\r", $sanitized);
        $this->assertStringNotContainsString("\n", $sanitized);
        $this->assertStringNotContainsString('"', $sanitized);
        $this->assertStringNotContainsString("\x00", $sanitized);
    }

    public function testAnAbsurdlyLongFilenameIsTruncated(): void
    {
        $sanitized = PersonalizationUploadValidator::sanitizeOriginalFilename(
            str_repeat('a', 5000) . '.png',
            'png'
        );

        $this->assertLessThanOrEqual(200, mb_strlen($sanitized));
    }

    public function testUnicodeFilenamesArePreserved(): void
    {
        $this->assertSame(
            'kèrstbal-2026.png',
            PersonalizationUploadValidator::sanitizeOriginalFilename('kèrstbal-2026.png', 'png')
        );
    }
}

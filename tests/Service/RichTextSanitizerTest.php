<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Covers the allowlist RichTextSanitizer enforces for every rich-text CMS
 * field in this project, including rich_text_sections.content_html
 * (see App\Service\RichTextContent /
 * api/admin/update-information-page.php). Pure logic, no database.
 */
class RichTextSanitizerTest extends TestCase
{
    public function testStripsScriptTags(): void
    {
        $result = RichTextSanitizer::sanitize('<p>Hallo</p><script>alert(1)</script>');

        $this->assertSame('<p>Hallo</p>', $result);
    }

    public function testStripsInlineEventHandlers(): void
    {
        $result = RichTextSanitizer::sanitize('<p onclick="alert(1)">Hallo</p>');

        $this->assertSame('<p>Hallo</p>', $result);
    }

    public function testStripsDisallowedTagsButKeepsText(): void
    {
        $result = RichTextSanitizer::sanitize('<div><iframe src="https://evil.example"></iframe>Tekst</div>');

        $this->assertSame('Tekst', $result);
    }

    public function testRejectsJavascriptUrlScheme(): void
    {
        $result = RichTextSanitizer::sanitize('<p><a href="javascript:alert(1)">klik</a></p>');

        $this->assertStringNotContainsString('javascript:', (string) $result);
    }

    public function testKeepsAllowedFormattingTags(): void
    {
        $html = '<h2>Titel</h2><p>Een <strong>vette</strong> en <em>cursieve</em> alinea met een '
            . '<a href="https://example.com">link</a>.</p><ul><li>Punt een</li><li>Punt twee</li></ul>'
            . '<ol><li>Eerste</li><li>Tweede</li></ol>';

        $result = RichTextSanitizer::sanitize($html);

        $this->assertStringContainsString('<h2>Titel</h2>', (string) $result);
        $this->assertStringContainsString('<strong>vette</strong>', (string) $result);
        $this->assertStringContainsString('<em>cursieve</em>', (string) $result);
        $this->assertStringContainsString('<ul>', (string) $result);
        $this->assertStringContainsString('<ol>', (string) $result);
        $this->assertStringContainsString('href="https://example.com"', (string) $result);
    }

    public function testStripsStyleAndClassAttributes(): void
    {
        $result = RichTextSanitizer::sanitize('<p class="evil" style="color:red;">Tekst</p>');

        $this->assertSame('<p>Tekst</p>', $result);
    }

    public function testEmptyInputReturnsNull(): void
    {
        $this->assertNull(RichTextSanitizer::sanitize(''));
        $this->assertNull(RichTextSanitizer::sanitize(null));
        $this->assertNull(RichTextSanitizer::sanitize('   '));
    }

    public function testNormalizesQuillBulletList(): void
    {
        $quillHtml = '<ol><li data-list="bullet"><span class="ql-ui"></span>Eerste</li>'
            . '<li data-list="bullet"><span class="ql-ui"></span>Tweede</li></ol>';

        $result = RichTextSanitizer::sanitize($quillHtml);

        $this->assertStringContainsString('<ul>', (string) $result);
        $this->assertStringNotContainsString('<ol>', (string) $result);
        $this->assertStringNotContainsString('data-list', (string) $result);
        $this->assertStringNotContainsString('ql-ui', (string) $result);
    }
}

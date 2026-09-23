<?php

declare(strict_types=1);

namespace Tests\Service\Media;

use App\Service\Media\SvgRefused;
use App\Service\Media\SvgSanitizer;
use App\Service\Media\VideoFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What App\Service\Media\SvgSanitizer lets through, what it removes, and what
 * it refuses (MEDIA.md, "SVG"). No database and no files: the sanitizer takes
 * a string and hands one back.
 *
 * The refusals are the security contract, one case per way an SVG can run
 * code or reach outside itself. A case that starts to pass is a hole, so each
 * one names the reason it must be refused with.
 *
 * VideoFormat is here too: it is the other half of "the bytes decide what a
 * file is", and just as free of the database.
 */
final class SvgSanitizerTest extends TestCase
{
    private const NS = 'xmlns="http://www.w3.org/2000/svg"';

    public function testAnOrdinaryDrawingIsKeptWithItsShapesAndItsSize(): void
    {
        $result = SvgSanitizer::sanitize(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg ' . self::NS . ' xmlns:xlink="http://www.w3.org/1999/xlink" width="120" height="40" viewBox="0 0 120 40">'
            . '<title>Logo</title>'
            . '<defs><linearGradient id="g"><stop offset="0" stop-color="#000"/></linearGradient></defs>'
            . '<rect width="120" height="40" fill="url(#g)"/>'
            . '<use xlink:href="#g"/>'
            . '<text x="4" y="30" style="font-weight:bold">Mygdala</text>'
            . '</svg>'
        );

        $this->assertSame(120, $result['width']);
        $this->assertSame(40, $result['height']);
        $this->assertStringContainsString('<rect', $result['svg']);
        $this->assertStringContainsString('fill="url(#g)"', $result['svg']);
        $this->assertStringContainsString('href="#g"', $result['svg']);
        $this->assertStringContainsString('Mygdala', $result['svg']);
        $this->assertStringContainsString('<title>Logo</title>', $result['svg']);
    }

    public function testTheSizeComesFromTheViewBoxWhenWidthAndHeightSayNothingUsable(): void
    {
        $result = SvgSanitizer::sanitize('<svg ' . self::NS . ' width="100%" viewBox="0 0 64.4 32"><path d="M0 0h10v10z"/></svg>');

        $this->assertSame(64, $result['width']);
        $this->assertSame(32, $result['height']);

        $none = SvgSanitizer::sanitize('<svg ' . self::NS . '><path d="M0 0h10v10z"/></svg>');
        $this->assertNull($none['width']);
        $this->assertNull($none['height']);
    }

    /** An editor's own bookkeeping goes without a word: it never changes the drawing. */
    public function testMetadataCommentsAndEditorNamespacesAreRemovedNotRefused(): void
    {
        $result = SvgSanitizer::sanitize(
            '<svg ' . self::NS . ' xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape"'
            . ' xmlns:sodipodi="http://sodipodi.sourceforge.net/DTD/sodipodi-0.dtd" inkscape:version="1.3">'
            . '<!-- made by hand -->'
            . '<?some-instruction ok?>'
            . '<metadata><rdf>who knows</rdf></metadata>'
            . '<sodipodi:namedview id="view"/>'
            . '<g inkscape:label="Laag 1"><circle r="4" cx="5" cy="5"/></g>'
            . '<blink>unknown</blink>'
            . '</svg>'
        );

        $svg = $result['svg'];
        $this->assertStringContainsString('<circle', $svg);
        foreach (['made by hand', 'some-instruction', 'metadata', 'namedview', 'inkscape:label', 'inkscape:version', 'blink', 'unknown'] as $gone) {
            $this->assertStringNotContainsString($gone, $svg, $gone . ' must be removed');
        }
    }

    /** A link does nothing inside an <img>; its letters stay, its href goes. */
    public function testALinkIsUnwrappedSoItsContentStays(): void
    {
        $svg = SvgSanitizer::sanitize('<svg ' . self::NS . '><a href="#top"><text>Naam</text></a></svg>')['svg'];

        $this->assertStringContainsString('<text>Naam</text>', $svg);
        $this->assertStringNotContainsString('<a', $svg);
    }

    public function testAnEmbeddedRasterPictureMayStay(): void
    {
        $svg = SvgSanitizer::sanitize('<svg ' . self::NS . '><image width="1" height="1" href="data:image/png;base64,iVBORw0KGgo="/></svg>')['svg'];

        $this->assertStringContainsString('data:image/png;base64', $svg);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function refusedDrawings(): array
    {
        $ns = self::NS;

        return [
            'script element' => ["<svg $ns><script>alert(1)</script></svg>", SvgSanitizer::REASON_SCRIPT],
            'script in XHTML namespace' => ["<svg $ns><x:script xmlns:x=\"http://www.w3.org/1999/xhtml\">alert(1)</x:script></svg>", SvgSanitizer::REASON_SCRIPT],
            'onload on the root' => ["<svg $ns onload=\"alert(1)\"><rect width=\"1\" height=\"1\"/></svg>", SvgSanitizer::REASON_EVENT],
            'onclick on a shape' => ["<svg $ns><rect onClick=\"alert(1)\" width=\"1\" height=\"1\"/></svg>", SvgSanitizer::REASON_EVENT],
            'javascript href' => ["<svg $ns><a href=\"javascript:alert(1)\"><text>x</text></a></svg>", SvgSanitizer::REASON_SCRIPT],
            'javascript href split by whitespace' => ["<svg $ns><a href=\"java&#10;script:alert(1)\"><text>x</text></a></svg>", SvgSanitizer::REASON_SCRIPT],
            'foreignObject with HTML' => ["<svg $ns><foreignObject><div xmlns=\"http://www.w3.org/1999/xhtml\">x</div></foreignObject></svg>", SvgSanitizer::REASON_EMBEDDED],
            'iframe' => ["<svg $ns><iframe src=\"https://example.com\"/></svg>", SvgSanitizer::REASON_EMBEDDED],
            'animation rewriting an href' => ["<svg $ns><a href=\"#x\"><set attributeName=\"href\" to=\"javascript:alert(1)\"/><text>x</text></a></svg>", SvgSanitizer::REASON_ANIMATION],
            'plain animation' => ["<svg $ns><rect width=\"1\" height=\"1\"><animate attributeName=\"x\" from=\"0\" to=\"5\" dur=\"1s\"/></rect></svg>", SvgSanitizer::REASON_ANIMATION],
            'external use' => ["<svg $ns xmlns:xlink=\"http://www.w3.org/1999/xlink\"><use xlink:href=\"https://evil.example/sprite.svg#a\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'external image' => ["<svg $ns><image href=\"https://evil.example/track.png\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'relative image path' => ["<svg $ns><image href=\"../../.env\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'nested svg as data uri' => ["<svg $ns><image href=\"data:image/svg+xml;base64,PHN2Zz4=\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'external url() in fill' => ["<svg $ns><rect fill=\"url(https://evil.example/x.svg#g)\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'external url() in style' => ["<svg $ns><rect style=\"fill:url('https://evil.example/x#g')\"/></svg>", SvgSanitizer::REASON_EXTERNAL],
            'css import' => ["<svg $ns><style>@import url(https://evil.example/x.css);</style></svg>", SvgSanitizer::REASON_EXTERNAL],
            'css background image' => ["<svg $ns><style>rect{background:url(//evil.example/x)}</style></svg>", SvgSanitizer::REASON_EXTERNAL],
            'xxe external entity' => ["<?xml version=\"1.0\"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM \"file:///etc/passwd\">]><svg $ns><text>&xxe;</text></svg>", SvgSanitizer::REASON_DOCTYPE],
            'billion laughs' => ["<?xml version=\"1.0\"?><!DOCTYPE lolz [<!ENTITY lol \"lol\"><!ENTITY lol2 \"&lol;&lol;&lol;&lol;\">]><svg $ns><text>&lol2;</text></svg>", SvgSanitizer::REASON_DOCTYPE],
            'plain doctype' => ["<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\"><svg $ns/>", SvgSanitizer::REASON_DOCTYPE],
            'not xml at all' => ['<svg><rect></svg', SvgSanitizer::REASON_UNREADABLE],
            'html pretending' => ["<html><body><svg $ns/></body></html>", SvgSanitizer::REASON_UNREADABLE],
            'svg without namespace' => ['<svg><rect width="1" height="1"/></svg>', SvgSanitizer::REASON_UNREADABLE],
        ];
    }

    #[DataProvider('refusedDrawings')]
    public function testActiveOrOutwardContentIsRefusedWithItsReason(string $source, string $reason): void
    {
        try {
            SvgSanitizer::sanitize($source);
            $this->fail('must be refused');
        } catch (SvgRefused $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertNotSame('', $e->getMessage(), 'the editor gets a sentence, not a code');
        }
    }

    /** Whatever is left after cleaning cannot run anything, also where nothing was refused. */
    public function testTheStoredDrawingNeverCarriesAnActivePart(): void
    {
        $svg = SvgSanitizer::sanitize('<svg ' . self::NS . '><g><rect width="1" height="1"/></g><desc>ok</desc></svg>')['svg'];

        $this->assertDoesNotMatchRegularExpression('/<script|\son\w+=|javascript:|<!DOCTYPE|<!ENTITY|foreignObject/i', $svg);
        $this->assertStringStartsWith('<?xml', $svg);
    }

    /** The spellings of a DOCTYPE and of text that is not UTF-8 are refused like the plain ones. */
    public function testEveryWayToSmuggleADoctypeOrAnotherEncodingIsRefused(): void
    {
        $ns = self::NS;
        $cases = [
            "<!doctype svg [<!entity x SYSTEM 'file:///etc/passwd'>]><svg $ns><text>&x;</text></svg>" => SvgSanitizer::REASON_DOCTYPE,
            "<!-- a comment first --><!DOCTYPE svg [<!ENTITY % p SYSTEM 'http://127.0.0.1:1/evil.dtd'> %p;]><svg $ns/>" => SvgSanitizer::REASON_DOCTYPE,
            "\xEF\xBB\xBF  <!DOCTYPE svg><svg $ns/>" => SvgSanitizer::REASON_DOCTYPE,
            mb_convert_encoding("<?xml version=\"1.0\" encoding=\"UTF-16\"?><svg $ns/>", 'UTF-16') => SvgSanitizer::REASON_UNREADABLE,
        ];

        foreach ($cases as $source => $reason) {
            try {
                SvgSanitizer::sanitize($source);
                $this->fail('must be refused: ' . substr(bin2hex($source), 0, 40));
            } catch (SvgRefused $e) {
                self::assertSame($reason, $e->reason);
            }
        }
    }

    /**
     * An XInclude is never processed: it is an element in a foreign namespace
     * and is removed, so nothing it names is ever read.
     */
    public function testAnXIncludeIsRemovedAndNeverRead(): void
    {
        $svg = SvgSanitizer::sanitize('<svg ' . self::NS . ' xmlns:xi="http://www.w3.org/2001/XInclude"><xi:include href="file:///etc/passwd" parse="text"/><rect width="1" height="1"/></svg>')['svg'];

        self::assertStringNotContainsString('include', $svg);
        self::assertStringNotContainsString('root:', $svg);
    }

    /**
     * The parser itself can reach nothing: no network (LIBXML_NONET), no
     * entity substitution and no DTD loading, whatever the input. Read from
     * the source, because the refusal above makes the flags unobservable.
     */
    public function testTheParserCanLoadNoExternalEntityOrNetworkResource(): void
    {
        // The code only: the docblock names the flags it deliberately leaves out.
        $source = '';
        foreach (token_get_all((string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/Media/SvgSanitizer.php')) as $token) {
            $source .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }

        preg_match_all('/->loadXML\((.*?)\);/s', $source, $calls);
        self::assertCount(1, $calls[1], 'one parse');
        self::assertStringContainsString('LIBXML_NONET', $calls[1][0]);
        foreach (['LIBXML_NOENT', 'LIBXML_DTDLOAD', 'LIBXML_DTDATTR', 'LIBXML_DTDVALID', 'LIBXML_XINCLUDE'] as $flag) {
            self::assertStringNotContainsString($flag, $source, $flag . ' would let the document reach outside itself');
        }
        self::assertStringNotContainsString('->xinclude(', $source);
        self::assertStringNotContainsString('simplexml_', $source);
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function videoHeaders(): array
    {
        return [
            'mp4 isom' => ["\x00\x00\x00\x20ftypisom\x00\x00\x02\x00", VideoFormat::MP4],
            'mp4 mp42' => ["\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00", VideoFormat::MP4],
            'webm' => ["\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\xF7\x81", VideoFormat::WEBM],
            'quicktime mov' => ["\x00\x00\x00\x14ftypqt  \x00\x00\x00\x00", null],
            'heic photo' => ["\x00\x00\x00\x18ftypheic\x00\x00\x00\x00", null],
            'avif photo' => ["\x00\x00\x00\x1Cftypavif\x00\x00\x00\x00", null],
            'png' => ["\x89PNG\r\n\x1a\n\x00\x00\x00\x0D", null],
            'php' => ["<?php echo 1; ", null],
            'too short' => ["\x00\x00\x00\x20ftyp", null],
        ];
    }

    #[DataProvider('videoHeaders')]
    public function testAVideoIsRecognisedByItsFirstBytesOnly(string $header, ?string $expected): void
    {
        $this->assertSame($expected, VideoFormat::detect($header));
    }
}

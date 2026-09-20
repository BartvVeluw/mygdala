<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\RequestPath;
use PHPUnit\Framework\TestCase;

/**
 * Taking a request URI apart (docs/multilingual/ROUTING.md).
 *
 * Half of this is routing and half of it is security: every path the
 * dispatcher acts on comes out of this class, so a traversal attempt, an
 * encoded separator or a control character has to die here and not three
 * lookups later.
 */
final class RequestPathTest extends TestCase
{
    // ------------------------------------------------------------ the normal form

    public function testTheRootHasNoSegments(): void
    {
        $path = RequestPath::fromRequestUri('/');

        self::assertNotNull($path);
        self::assertSame([], $path->segments);
        self::assertTrue($path->isRoot());
        self::assertSame('/', $path->path());
    }

    public function testASimplePathBecomesItsSegments(): void
    {
        $path = RequestPath::fromRequestUri('/blog/mijn-bericht');

        self::assertNotNull($path);
        self::assertSame(['blog', 'mijn-bericht'], $path->segments);
        self::assertSame('/blog/mijn-bericht', $path->path());
        self::assertFalse($path->hadTrailingSlash);
    }

    public function testATrailingSlashIsRememberedButNotKept(): void
    {
        $path = RequestPath::fromRequestUri('/over-ons/');

        self::assertNotNull($path);
        self::assertTrue($path->hadTrailingSlash);
        self::assertSame('/over-ons', $path->path());
    }

    public function testDoubledSlashesAreCollapsed(): void
    {
        $path = RequestPath::fromRequestUri('//collecties//hout');

        self::assertNotNull($path);
        self::assertSame(['collecties', 'hout'], $path->segments);
    }

    public function testTheQueryStringIsCarriedVerbatimAndNeverParsed(): void
    {
        $path = RequestPath::fromRequestUri('/blog?pagina=2&x=a%20b');

        self::assertNotNull($path);
        self::assertSame('pagina=2&x=a%20b', $path->query);
        self::assertSame('/blog', $path->path());
        self::assertSame('/blog?pagina=2&x=a%20b', $path->pathWithQuery());
    }

    public function testAQueryStringIsPutBackOnAnyOtherPath(): void
    {
        $path = RequestPath::fromRequestUri('/nl/over-ons?utm_source=x');

        self::assertNotNull($path);
        self::assertSame('/over-ons?utm_source=x', $path->withQuery('/over-ons'));
    }

    public function testAPathWithoutAQueryGetsNothingAppended(): void
    {
        $path = RequestPath::fromRequestUri('/nl/over-ons');

        self::assertNotNull($path);
        self::assertSame('/over-ons', $path->withQuery('/over-ons'));
    }

    public function testAFragmentIsNotPartOfThePath(): void
    {
        $path = RequestPath::fromRequestUri('/diensten.php#hout');

        self::assertNotNull($path);
        self::assertSame(['diensten.php'], $path->segments);
    }

    // ------------------------------------------------------- peeling a segment

    public function testTheFirstSegmentCanBePeeledOff(): void
    {
        $path = RequestPath::fromRequestUri('/en/blog/my-post?pagina=2');

        self::assertNotNull($path);
        self::assertSame('en', $path->firstSegment());

        $rest = $path->withoutFirstSegment();
        self::assertSame(['blog', 'my-post'], $rest->segments);
        self::assertSame('pagina=2', $rest->query);
    }

    public function testPeelingTheOnlySegmentLeavesTheRoot(): void
    {
        $path = RequestPath::fromRequestUri('/en/');

        self::assertNotNull($path);
        self::assertTrue($path->withoutFirstSegment()->isRoot());
        self::assertTrue($path->withoutFirstSegment()->hadTrailingSlash);
    }

    // ------------------------------------------------------------- decoding

    public function testASegmentIsDecodedExactlyOnce(): void
    {
        $path = RequestPath::fromRequestUri('/blog/caf%C3%A9');

        self::assertNotNull($path);
        self::assertSame(['blog', 'café'], $path->segments);
    }

    public function testAnEncodedSeparatorCanNeverGrowIntoOne(): void
    {
        // %2F decodes to "/", and a decoded separator inside a segment would
        // mean one segment silently becoming two.
        self::assertNull(RequestPath::fromRequestUri('/blog/a%2Fb'));
    }

    // -------------------------------------------------------------- refusals

    public function testTraversalIsRefusedRatherThanCollapsed(): void
    {
        self::assertNull(RequestPath::fromRequestUri('/../etc/passwd'));
        self::assertNull(RequestPath::fromRequestUri('/blog/../../admin'));
        self::assertNull(RequestPath::fromRequestUri('/blog/%2e%2e/admin'));
    }

    public function testASingleDotSegmentIsRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri('/./blog'));
    }

    public function testABackslashIsRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri('/blog\\admin'));
    }

    public function testAControlCharacterIsRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri("/blog\nLocation:%20https://evil.test"));
        self::assertNull(RequestPath::fromRequestUri("/blog\x00"));
        self::assertNull(RequestPath::fromRequestUri('/blog/%0d%0a'));
    }

    public function testAnEmptyUriIsRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri(''));
    }

    public function testAnAbsurdlyLongUriIsRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri('/' . str_repeat('a', RequestPath::MAX_LENGTH)));
    }

    public function testTooManySegmentsAreRefused(): void
    {
        self::assertNull(RequestPath::fromRequestUri(str_repeat('/a', RequestPath::MAX_SEGMENTS + 1)));
    }

    public function testAnAbsoluteUrlIsNotAPathThisApplicationRoutes(): void
    {
        // Apache never sends one for an origin-form request, but the parser
        // must not turn "https://evil.test/x" into the segments of a route.
        $path = RequestPath::fromRequestUri('https://evil.test/x');

        self::assertNotNull($path);
        // "https:" is a segment like any other, and matches no route — what
        // matters is that the host never becomes part of one.
        self::assertSame(['https:', 'evil.test', 'x'], $path->segments);
    }
}

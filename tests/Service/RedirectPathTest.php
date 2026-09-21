<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Module\ModuleRegistry;
use App\Service\Redirects\Redirect;
use App\Service\Redirects\RedirectPath;
use App\Service\Redirects\RedirectTarget;
use PHPUnit\Framework\TestCase;

/**
 * The pure half of the Redirect Manager: how a path is normalized, what a
 * destination may be, and what the two closed vocabularies contain. No
 * database and no web server — see REDIRECTS.md for the behaviour these rules
 * add up to.
 */
class RedirectPathTest extends TestCase
{
    protected function tearDown(): void
    {
        ModuleRegistry::overrideForTests(null);
    }

    public function testOneUrlIsOnePathHoweverItIsWritten(): void
    {
        foreach (['/foo', '/foo/', '/foo//', 'foo', '/foo?utm_source=x', '/foo#top'] as $written) {
            $this->assertSame('/foo', RedirectPath::normalize($written), $written . ' should be the same path as /foo');
        }
    }

    public function testCollapsesDuplicateSlashesInsideAPath(): void
    {
        $this->assertSame('/a/b/c', RedirectPath::normalize('/a//b///c/'));
    }

    /**
     * The root is a path like any other here; RedirectValidator is what
     * refuses it as a source, with a message about the homepage.
     */
    public function testTheSiteRootNormalizesToASingleSlash(): void
    {
        $this->assertSame('/', RedirectPath::normalize('/'));
    }

    public function testPercentEncodingIsDecodedSoARequestAndAStoredPathAgree(): void
    {
        $this->assertSame('/oude pagina', RedirectPath::normalize('/oude%20pagina'));
    }

    /**
     * This site's routing is case-sensitive — /Diensten.php 404s while
     * /diensten.php answers — so folding case here would make a redirect fire
     * for URLs this site never served.
     */
    public function testCaseIsPreserved(): void
    {
        $this->assertSame('/Oude-Pagina', RedirectPath::normalize('/Oude-Pagina'));
        $this->assertNotSame(
            RedirectPath::normalize('/oude-pagina'),
            RedirectPath::normalize('/Oude-Pagina')
        );
    }

    /**
     * @dataProvider unusableSourcePaths
     */
    public function testRejectsWhatCannotBeASourcePath(string $raw, string $why): void
    {
        $this->assertNull(RedirectPath::normalize($raw), $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableSourcePaths(): array
    {
        return [
            'empty' => ['', 'an empty path names nothing'],
            'whitespace only' => ['   ', 'an empty path names nothing'],
            'absolute url' => ['https://example.com/foo', 'a source path may not carry a scheme or a host'],
            'protocol relative' => ['//example.com/foo', 'a protocol-relative path is another host'],
            'parent segment' => ['/a/../b', 'a traversal segment is never rewritten silently'],
            'current segment' => ['/./a', 'a traversal segment is never rewritten silently'],
            'newline' => ["/foo\nLocation: https://evil.example", 'a control character must never reach a header'],
            'null byte' => ["/foo\0bar", 'a control character must never reach a header'],
            'backslash' => ['/foo' . chr(92) . 'bar', 'a backslash is rewritten into a slash by too many clients'],
            'too long' => ['/' . str_repeat('a', RedirectPath::MAX_LENGTH), 'the column cannot hold it'],
        ];
    }

    public function testSplitsARequestUriIntoPathAndQuery(): void
    {
        $this->assertSame('/oude-pagina', RedirectPath::fromRequestUri('/oude-pagina/?utm_source=nieuwsbrief'));
        $this->assertSame('utm_source=nieuwsbrief', RedirectPath::queryFromRequestUri('/oude-pagina/?utm_source=nieuwsbrief'));

        $this->assertSame('/oude-pagina', RedirectPath::fromRequestUri('/oude-pagina'));
        $this->assertSame('', RedirectPath::queryFromRequestUri('/oude-pagina'));
    }

    public function testAnInternalTargetIsNormalizedLikeASourceButMayKeepItsOwnQuery(): void
    {
        $this->assertSame('/nieuwe-pagina', RedirectTarget::normalizeInternal('/nieuwe-pagina/'));
        $this->assertSame(
            '/contact.php?onderwerp=offerte',
            RedirectTarget::normalizeInternal('/contact.php?onderwerp=offerte')
        );
        $this->assertSame('/contact.php', RedirectTarget::normalizeInternal('/contact.php#formulier'));
    }

    /**
     * @dataProvider unusableExternalTargets
     */
    public function testRejectsAnUnsafeExternalTarget(string $raw, string $why): void
    {
        $this->assertNull(RedirectTarget::normalizeExternal($raw), $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function unusableExternalTargets(): array
    {
        return [
            'javascript' => ['javascript:alert(1)', 'only http(s) is accepted, which refuses this as a class'],
            'data' => ['data:text/html,<script>alert(1)</script>', 'only http(s) is accepted'],
            'mailto' => ['mailto:info@example.com', 'a redirect destination is a page, not an address'],
            'credentials' => ['https://user:pass@example.com/', 'credentials in a URL are how a phishing link is dressed up'],
            'no host' => ['https://', 'there is nowhere to go'],
            'not a url' => ['zomaar wat tekst', 'not a URL at all'],
            'relative' => ['/nog-een-pad', 'a site-relative path is an internal target, not an external one'],
            'newline' => ["https://example.com/\nX: 1", 'a control character must never reach a header'],
        ];
    }

    public function testAcceptsAnOrdinaryExternalUrl(): void
    {
        $this->assertSame('https://voorbeeld.nl/pagina', RedirectTarget::normalizeExternal('https://voorbeeld.nl/pagina'));
        $this->assertSame('http://voorbeeld.nl/', RedirectTarget::normalizeExternal(' http://voorbeeld.nl/ '));
    }

    /**
     * An internal destination becomes an absolute URL against APP_URL, exactly
     * as every canonical tag on this site does — never against the request's
     * Host header. See SEO.md.
     */
    public function testAnInternalTargetBecomesAnAbsoluteApplicationUrl(): void
    {
        $absolute = RedirectTarget::absoluteUrl(RedirectTarget::TYPE_INTERNAL, '/nieuwe-pagina');

        $this->assertSame(\App\Service\AppUrl::canonical('nieuwe-pagina'), $absolute);
        $this->assertStringStartsWith('http', $absolute);
    }

    public function testAnExternalTargetIsUsedExactlyAsStored(): void
    {
        $this->assertSame(
            'https://voorbeeld.nl/pagina',
            RedirectTarget::absoluteUrl(RedirectTarget::TYPE_EXTERNAL, 'https://voorbeeld.nl/pagina')
        );
    }

    /**
     * A destination inside a switched-off module is reported as unavailable,
     * so the resolver can decline to send anyone there — the same thing
     * App\Service\LinkResolver already does with a menu item pointing at one.
     */
    public function testAnInternalTargetInsideADisabledModuleIsReportedAsUnavailable(): void
    {
        ModuleRegistry::overrideForTests(['shop' => false, 'personalization' => false, 'multilingual' => true]);

        $this->assertSame(
            'shop',
            RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/shop.php')
        );
        $this->assertNull(RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/contact.php'));
        $this->assertNull(
            RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_EXTERNAL, 'https://voorbeeld.nl/'),
            'an external destination is never this application\'s to switch off'
        );

        ModuleRegistry::overrideForTests(['shop' => true, 'personalization' => true, 'multilingual' => true]);

        $this->assertNull(RedirectTarget::disabledModuleFor(RedirectTarget::TYPE_INTERNAL, '/shop.php'));
    }

    public function testTheStatusCodesAreAClosedPairWithAPermanentDefault(): void
    {
        $this->assertSame([301, 302], Redirect::STATUS_CODES);
        $this->assertSame(301, Redirect::DEFAULT_STATUS);

        $this->assertTrue(Redirect::isValidStatusCode(301));
        $this->assertTrue(Redirect::isValidStatusCode(302));

        foreach ([0, 200, 303, 307, 308, 404, 500] as $refused) {
            $this->assertFalse(Redirect::isValidStatusCode($refused), $refused . ' is not one of this application\'s');
        }
    }

    public function testTheOriginsAreAClosedPairAndBothHaveALabel(): void
    {
        $this->assertSame(['manual', 'slug_change'], Redirect::ORIGINS);

        foreach (Redirect::ORIGINS as $origin) {
            $this->assertTrue(Redirect::isValidOrigin($origin));
            $this->assertNotSame($origin, Redirect::originLabel($origin), 'every origin needs a readable label');
        }

        $this->assertFalse(Redirect::isValidOrigin('import'));
    }

    public function testTheTargetTypesAreAClosedPairAndBothHaveALabel(): void
    {
        $this->assertSame(['internal', 'external'], RedirectTarget::TYPES);

        foreach (RedirectTarget::TYPES as $type) {
            $this->assertTrue(RedirectTarget::isValidType($type));
            $this->assertArrayHasKey($type, RedirectTarget::TYPE_LABELS);
        }

        $this->assertFalse(RedirectTarget::isValidType('page'));
        $this->assertNull(RedirectTarget::normalize('page', '/ergens'));
    }
}

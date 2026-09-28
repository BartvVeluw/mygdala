<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\LinkResolver;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\SafeUrl;
use App\Service\Routing\TypedLink;
use App\Service\SocialProfiles;
use App\Service\Redirects\RedirectTarget;
use PHPUnit\Framework\TestCase;

/**
 * The one rule for an address an editor types (App\Service\Routing\SafeUrl,
 * Pages & Destinations 3.0), as regression tests for the two holes the audit
 * found: typed-URL fields that checked nothing (Contactkaart, Detailsectie,
 * Galerij), and a scheme check a control character walked past
 * ("\x01javascript:", "java\tscript:").
 *
 * What it proves: every ordinary address an editor types passes; a script
 * scheme is refused however it is spelled; a control character is refused
 * wherever it sits, because a browser would drop it and see the scheme;
 * whitespace around an address is trimmed and whitespace inside it breaks a
 * scheme rather than making one; the web-only list refuses mail and phone;
 * the render side prints no link for an unsafe stored value; and every field
 * that takes a typed address goes through this one class.
 */
final class SafeUrlTest extends TestCase
{
    public function testTheAddressesAnEditorTypesPass(): void
    {
        foreach ([
            '/contact',
            '/over-ons?bron=menu#team',
            '#formulier',
            '?q=graveren',
            'contact.php',
            'https://example.com/a?b=c#d',
            'http://example.com',
            '//cdn.example.com/brochure.pdf',
            'mailto:info@example.com',
            'tel:+31612345678',
        ] as $url) {
            self::assertNull(SafeUrl::problem($url), $url);
            self::assertTrue(SafeUrl::isSafe($url), $url);
        }
    }

    public function testAScriptSchemeIsRefusedHoweverItIsSpelled(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'JAVASCRIPT:alert(document.cookie)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
            'ftp://example.com',
        ] as $url) {
            self::assertSame(SafeUrl::PROBLEM_SCHEME, SafeUrl::problem($url), $url);
            self::assertFalse(SafeUrl::isSafe($url), $url);
        }
    }

    /**
     * A browser drops C0 control characters around an address and TAB, LF
     * and CR inside it before it reads the scheme; refusing them all is the
     * only way not to have to guess what it will make of the rest.
     */
    public function testAControlCharacterIsRefusedWhereverItSits(): void
    {
        foreach ([
            "\x01javascript:alert(1)",
            "java\tscript:alert(1)",
            "java\nscript:alert(1)",
            "javascript\r:alert(1)",
            "/con\x00tact",
            "https://example.com/\x7F",
            "/over\x1Bons",
        ] as $url) {
            self::assertSame(SafeUrl::PROBLEM_CONTROL, SafeUrl::problem(SafeUrl::normalise($url)), bin2hex($url));
            self::assertFalse(SafeUrl::isSafe($url), bin2hex($url));
        }
    }

    public function testWhitespaceAroundAnAddressIsTrimmedAndInsideItMakesNoScheme(): void
    {
        self::assertSame('/contact', SafeUrl::normalise("  /contact \n"));
        self::assertTrue(SafeUrl::isSafe(" \thttps://example.com \n"));
        self::assertSame(SafeUrl::PROBLEM_SCHEME, SafeUrl::problem(SafeUrl::normalise(" \t javascript:alert(1)")), 'trimmed, then still refused');
        self::assertSame(SafeUrl::PROBLEM_EMPTY, SafeUrl::problem(SafeUrl::normalise("   \n")));

        // A space inside the word ends it: no scheme, so a relative address
        // a browser resolves under this site, never a script.
        self::assertNull(SafeUrl::problem('java script:alert(1)'));
        self::assertNull(SafeUrl::problem('javascript :alert(1)'));
    }

    public function testTheWebOnlyListRefusesMailAndPhone(): void
    {
        self::assertNull(SafeUrl::problem('https://example.com', SafeUrl::SCHEMES_WEB));
        self::assertSame(SafeUrl::PROBLEM_SCHEME, SafeUrl::problem('mailto:info@example.com', SafeUrl::SCHEMES_WEB));
        self::assertSame(SafeUrl::PROBLEM_SCHEME, SafeUrl::problem('tel:+31612345678', SafeUrl::SCHEMES_WEB));
    }

    public function testAnOptionalFieldSaysWhatIsWrongOrNothing(): void
    {
        self::assertNull(SafeUrl::optionalFieldMessage(''), 'an optional address may be empty');
        self::assertNull(SafeUrl::optionalFieldMessage('/contact'));
        self::assertNotNull(SafeUrl::optionalFieldMessage('javascript:alert(1)'));
        self::assertNotNull(SafeUrl::optionalFieldMessage("java\tscript:alert(1)"));
        self::assertNotSame(
            SafeUrl::optionalFieldMessage('javascript:alert(1)'),
            SafeUrl::optionalFieldMessage("\x01https://example.com"),
            'an invisible character is named as such, not as a wrong beginning'
        );
    }

    public function testABlockButtonsOwnAddressFollowsTheRule(): void
    {
        self::assertNull(LinkChoice::fromRequest('url', null, ' https://example.com ')['error']);
        self::assertNull(LinkChoice::fromRequest('url', null, '/contact')['error']);
        self::assertNotNull(LinkChoice::fromRequest('url', null, "\x01javascript:alert(1)")['error']);
        self::assertNotNull(LinkChoice::fromRequest('url', null, "java\tscript:alert(1)")['error']);
        self::assertNotNull(LinkChoice::fromRequest('url', null, 'JaVaScRiPt:alert(1)')['error']);
    }

    public function testAMenuLinkARedirectAndASocialProfileFollowItToo(): void
    {
        self::assertTrue(LinkResolver::isValidUrl('https://example.com'));
        self::assertTrue(LinkResolver::isValidUrl('/diensten.php#hout'));
        self::assertFalse(LinkResolver::isValidUrl('javascript:alert(1)'));
        self::assertFalse(LinkResolver::isValidUrl("/contact\tx"));
        self::assertFalse(LinkResolver::isValidUrl("\x01https://example.com"));

        self::assertNull(RedirectTarget::normalizeExternal("java\tscript:alert(1)"));
        self::assertNull(RedirectTarget::normalizeExternal('javascript:alert(1)'));
        self::assertSame('https://example.com/nieuw', RedirectTarget::normalizeExternal(' https://example.com/nieuw '));

        self::assertFalse(SocialProfiles::isValidProfileUrl('facebook', "javascript:alert(1)"));
        self::assertFalse(SocialProfiles::isValidProfileUrl('facebook', "https://facebook.com/x\x01"));
    }

    /** A value stored before every field checked it never becomes a link. */
    public function testTheRenderSidePrintsNoUnsafeLink(): void
    {
        self::assertSame('', TypedLink::href('javascript:alert(1)'));
        self::assertSame('', TypedLink::href("\x01javascript:alert(1)"));
        self::assertSame('', TypedLink::href("java\tscript:alert(1)"));
        self::assertSame('https://example.com', TypedLink::href('https://example.com'));
        self::assertSame('', LinkChoice::href('url', null, 'javascript:alert(1)'));
        self::assertNull(LinkResolver::resolve(['link_type' => 'external', 'external_url' => 'javascript:alert(1)']));
        self::assertNull(LinkResolver::resolve(['link_type' => 'external', 'external_url' => "java\tscript:alert(1)"]));
    }

    /** Every field that takes a typed address goes through the one rule. */
    public function testEveryTypedAddressFieldUsesTheOneRule(): void
    {
        $root = dirname(__DIR__, 3);
        $expect = [
            'src/Service/Routing/LinkChoice.php' => 'SafeUrl::problem(',
            'src/Service/Routing/TypedLink.php' => 'SafeUrl::isSafe(',
            'src/Service/LinkResolver.php' => 'SafeUrl::problem(',
            'src/Service/Redirects/RedirectTarget.php' => 'SafeUrl::problem(',
            'src/Service/SocialProfiles.php' => 'SafeUrl::problem(',
            'api/admin/update-contact-card.php' => 'SafeUrl::optionalFieldMessage(',
            'api/admin/update-detail-section.php' => 'SafeUrl::optionalFieldMessage(',
            'api/admin/update-item-gallery.php' => 'SafeUrl::optionalFieldMessage(',
        ];

        foreach ($expect as $file => $call) {
            self::assertStringContainsString($call, (string) file_get_contents($root . '/' . $file), $file);
        }

        self::assertStringNotContainsString('URL_SCHEMES', (string) file_get_contents($root . '/src/Service/Routing/LinkChoice.php'), 'no second list of schemes');
    }
}

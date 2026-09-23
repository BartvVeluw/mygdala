<?php

declare(strict_types=1);

namespace Tests\Service\Routing;

use App\Service\Routing\LinkChoice;
use App\Service\Routing\LinkTargets;
use PHPUnit\Framework\TestCase;

/**
 * The one rule every block button follows (App\Service\Routing\LinkChoice):
 * what a posted destination is stored as, what is refused, and what address a
 * stored one renders. The editors that use it (a carousel card, the Homepage
 * Hero) are covered over HTTP in CardCarouselEditorHttpTest and
 * BlockRowEditorsHttpTest; this is the rule on its own.
 */
final class LinkChoiceTest extends TestCase
{
    protected function tearDown(): void
    {
        LinkTargets::reset();
    }

    public function testARowFromBeforeTheTypeIsAnAddressWhenItHasOne(): void
    {
        self::assertSame(LinkChoice::URL, LinkChoice::storedType(null, '/contact'));
        self::assertSame(LinkChoice::URL, LinkChoice::storedType('', ' https://example.com '));
        self::assertSame(LinkChoice::NONE, LinkChoice::storedType(null, ''));
        self::assertSame('page', LinkChoice::storedType('page', '/contact'), 'a stored type wins over the kept address');
    }

    public function testAnOwnAddressIsKeptAndChecked(): void
    {
        self::assertSame(['link_type' => 'url', 'link_target_id' => null, 'error' => null], LinkChoice::fromRequest('url', null, ' /contact '));
        self::assertNull(LinkChoice::fromRequest('url', null, 'https://example.com/a?b#c')['error']);
        self::assertNull(LinkChoice::fromRequest('url', null, 'mailto:info@example.com')['error']);
        self::assertNull(LinkChoice::fromRequest('url', null, '#contact')['error']);

        self::assertNotNull(LinkChoice::fromRequest('url', null, '')['error'], 'an own address needs an address');
        foreach (['javascript:alert(1)', 'JavaScript:alert(1)', 'data:text/html,x', 'vbscript:x'] as $unsafe) {
            self::assertNotNull(LinkChoice::fromRequest('url', null, $unsafe)['error'], $unsafe);
        }
    }

    public function testNoButtonIsAnAnswerOnlyWhereItIsAllowed(): void
    {
        self::assertSame(['link_type' => null, 'link_target_id' => null, 'error' => null], LinkChoice::fromRequest('none', null, '/genegeerd'));
        self::assertNotNull(LinkChoice::fromRequest('none', null, '', allowNone: false)['error']);
        self::assertNotNull(LinkChoice::fromRequest('', null, '', allowNone: false)['error']);
    }

    public function testAnInternalTargetMustExistAndAnUnknownTypeIsRefused(): void
    {
        self::assertNotNull(LinkChoice::fromRequest('page', '0', '')['error']);
        self::assertNotNull(LinkChoice::fromRequest('page', '999999999', '')['error']);
        self::assertNotNull(LinkChoice::fromRequest('App\\Evil', '1', '')['error'], 'a type is never taken from the request');
    }

    /** A kind whose module is off, sent back unchanged, is kept exactly as stored. */
    public function testATypeWhoseModuleIsOffIsKeptWhenSentBackUnchanged(): void
    {
        $kept = LinkChoice::fromRequest('not_a_kind_here', '5', '', 'not_a_kind_here', 42);

        self::assertSame(['link_type' => 'not_a_kind_here', 'link_target_id' => 42, 'error' => null], $kept);
    }

    public function testAStoredChoiceRendersAnAddressOrNothing(): void
    {
        self::assertSame('https://example.com/x', LinkChoice::href('url', null, 'https://example.com/x'));
        self::assertSame('https://example.com/x', LinkChoice::href(null, null, 'https://example.com/x'), 'written before the type existed');
        self::assertSame('', LinkChoice::href(null, null, ''));
        self::assertSame('', LinkChoice::href('page', 999999999, '/contact'), 'a target that is gone renders no button, not the kept address');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Routing\RequestLanguage;
use PHPUnit\Framework\TestCase;
use Tests\Support\SiteLanguageFixture;

/**
 * The header's menu list (partials/main-nav-list.php) rendered from a
 * synthetic tree, without a database, plus the few rules in core.js and
 * core.css that keep its behaviour honest (HEADER-FOOTER.md, "Submenu's:
 * link en pijltje").
 *
 * THE CONTRACT
 *   - an item without a submenu is one link, exactly as before;
 *   - an item with a submenu and a destination is TWO controls: its own
 *     link, untouched, and a separate `<button type="button">` that names and
 *     controls its panel (aria-expanded, aria-controls);
 *   - a heading without a destination is not a fake link: its words sit in
 *     its one toggle;
 *   - three levels at most, whatever the tree holds;
 *   - one state: the submenu and its chevron follow `.is-open` only, a
 *     :hover rule exists only as the no-JavaScript fallback, and the script
 *     never cancels a click, so a link always navigates.
 *
 * The same markup over real HTTP, from rows the admin endpoints stored, is
 * Tests\Service\NavigationAdminHttpTest.
 */
final class MainNavMarkupTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private string $lastHtml = '';

    protected function setUp(): void
    {
        SiteLanguageFixture::useBilingual('nl');
    }

    protected function tearDown(): void
    {
        RequestLanguage::reset();
        SiteLanguageFixture::reset();
    }

    /**
     * @param list<array<string, mixed>> $children
     * @return array<string, mixed>
     */
    private function item(int $id, string $label, ?string $href, array $children = [], ?string $routeKey = null, bool $newTab = false): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'href' => $href,
            'open_in_new_tab' => $newTab,
            'rel' => $newTab ? 'noopener noreferrer' : null,
            'route_key' => $routeKey,
            'children' => $children,
        ];
    }

    /** @param list<array<string, mixed>> $navItems */
    private function render(array $navItems, ?string $activeNav = null, string $requestPath = '/'): \DOMXPath
    {
        $html = $this->lastHtml = (static function () use ($navItems, $activeNav, $requestPath): string {
            ob_start();
            require self::ROOT . '/partials/main-nav-list.php';

            return (string) ob_get_clean();
        })();

        $document = new \DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><nav>' . $html . '</nav>');
        libxml_clear_errors();

        return new \DOMXPath($document);
    }

    private function li(\DOMXPath $xpath, string $label): \DOMElement
    {
        $li = $xpath->query('//li[./div[@class="main-nav__row"][contains(., "' . $label . '")]]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $li, $label);

        return $li;
    }

    // --------------------------------------------------------------- markup

    public function testAMenuWithoutSubmenusIsOneLinkPerItem(): void
    {
        $xpath = $this->render([
            $this->item(1, 'Home', '/'),
            $this->item(2, 'Contact', '/contact'),
        ]);

        $this->assertSame(2, $xpath->query('//ul[@class="main-nav__list"]/li[@class="main-nav__item"]/a[@class="main-nav__link"]')->length);
        $this->assertSame(['/', '/contact'], array_map(
            static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xpath->query('//a'))
        ));
        $this->assertSame(0, $xpath->query('//button | //ul[contains(@class, "main-nav__submenu")]')->length);
    }

    public function testAParentWithADestinationIsALinkAndASeparateToggle(): void
    {
        $xpath = $this->render([
            $this->item(7, 'Diensten', '/diensten/graveren?x=1&y=2', [
                $this->item(8, 'Hout', '/hout'),
            ]),
        ]);

        $li = $this->li($xpath, 'Diensten');
        $this->assertSame('main-nav__item main-nav__item--has-children', $li->getAttribute('class'));

        $controls = $xpath->query('./div[@class="main-nav__row"]/*', $li);
        $this->assertSame(['a', 'button'], array_map(static fn (\DOMElement $el): string => $el->tagName, iterator_to_array($controls)), 'link first, then the toggle, as two siblings');

        $link = $controls->item(0);
        $this->assertSame('/diensten/graveren?x=1&y=2', $link->getAttribute('href'), 'the URL stays intact');
        $this->assertSame('Diensten', trim($link->textContent));
        $this->assertFalse($link->hasAttribute('role'), 'a plain link, not dressed up as a button');

        $toggle = $controls->item(1);
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertSame('main-nav-submenu-7', $toggle->getAttribute('aria-controls'));
        $this->assertSame('Submenu Diensten', $toggle->getAttribute('aria-label'));
        $this->assertSame('', trim($toggle->textContent), 'the toggle shows only the chevron');
        $this->assertSame('true', $xpath->query('.//*[local-name()="svg"]', $toggle)->item(0)?->getAttribute('aria-hidden'));
        $this->assertFalse($toggle->hasAttribute('aria-haspopup'), 'a disclosure, not a menu widget');

        $panel = $xpath->query('./ul[@id="main-nav-submenu-7"]', $li)->item(0);
        $this->assertSame('main-nav__submenu main-nav__submenu--level-2', $panel?->getAttribute('class'));
        $this->assertSame('/hout', $xpath->query('./li/a', $panel)->item(0)?->getAttribute('href'));
    }

    public function testTheToggleIsNamedInTheLanguageOfThePage(): void
    {
        RequestLanguage::set('en', true);
        $xpath = $this->render([$this->item(7, 'Services', '/en/services', [$this->item(8, 'Wood', '/en/wood')])]);

        $this->assertSame('Services submenu', $xpath->query('//button')->item(0)?->getAttribute('aria-label'));
    }

    public function testAHeadingWithoutADestinationIsOneToggleAndNoFakeLink(): void
    {
        $xpath = $this->render([
            $this->item(3, 'Werk', null, [$this->item(4, 'Galerij', '/galerij')]),
            $this->item(5, 'Leeg', null),
        ]);

        $li = $this->li($xpath, 'Werk');
        $this->assertSame(0, $xpath->query('./div[@class="main-nav__row"]/a', $li)->length);
        $toggle = $xpath->query('./div[@class="main-nav__row"]/button', $li)->item(0);
        $this->assertSame('Werk', trim((string) $toggle?->textContent), 'its own words name it');
        $this->assertFalse($toggle->hasAttribute('aria-label'));
        $this->assertSame('main-nav-submenu-3', $toggle->getAttribute('aria-controls'));
        $this->assertStringNotContainsString('Leeg', $xpath->document->saveHTML(), 'a heading with nothing under it renders nothing');
        $this->assertSame(0, $xpath->query('//a[@href="#" or @href=""]')->length);
    }

    public function testASubmenuItemWithItsOwnSubmenuIsTheSamePairOnLevelTwo(): void
    {
        $xpath = $this->render([
            $this->item(1, 'Diensten', '/diensten', [
                $this->item(2, 'Graveren', '/graveren', [
                    $this->item(3, 'Hout', '/graveren/hout'),
                    $this->item(4, 'Glas', 'https://example.com/glas', [], null, true),
                ]),
                $this->item(5, 'Snijden', '/snijden'),
            ]),
        ]);

        $graveren = $this->li($xpath, 'Graveren');
        $this->assertSame('/graveren', $xpath->query('./div[@class="main-nav__row"]/a', $graveren)->item(0)?->getAttribute('href'));
        $toggle = $xpath->query('./div[@class="main-nav__row"]/button[@type="button"]', $graveren)->item(0);
        $this->assertSame(['false', 'main-nav-submenu-2', 'Submenu Graveren'], [$toggle?->getAttribute('aria-expanded'), $toggle?->getAttribute('aria-controls'), $toggle?->getAttribute('aria-label')]);

        $flyout = $xpath->query('./ul[@id="main-nav-submenu-2"]', $graveren)->item(0);
        $this->assertSame('main-nav__submenu main-nav__submenu--level-3', $flyout?->getAttribute('class'));
        $glas = $xpath->query('./li/a[contains(., "Glas")]', $flyout)->item(0);
        $this->assertSame(['https://example.com/glas', '_blank', 'noopener noreferrer'], [$glas?->getAttribute('href'), $glas?->getAttribute('target'), $glas?->getAttribute('rel')]);

        $this->assertSame(0, $xpath->query('//li[./a[contains(., "Snijden")]]/*[self::button or self::ul]')->length, 'a level-2 item without a submenu is a plain link');
        $this->assertSame(2, $xpath->query('//button')->length, 'one toggle per submenu, no more');
    }

    public function testNoFourthLevelIsRenderedWhateverTheTreeHolds(): void
    {
        $xpath = $this->render([
            $this->item(1, 'Een', '/1', [
                $this->item(2, 'Twee', '/2', [
                    $this->item(3, 'Drie', '/3', [
                        $this->item(4, 'Vier', '/4'),
                    ]),
                ]),
            ]),
        ]);

        $this->assertSame(0, $xpath->query('//a[@href="/4"]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(@class, "main-nav__submenu--level-4")]')->length);
        $this->assertSame('/3', $xpath->query('//ul[contains(@class, "main-nav__submenu--level-3")]/li[@class="main-nav__item"]/a')->item(0)?->getAttribute('href'), 'level 3 is a plain link');
    }

    public function testOnlyATopLevelLinkIsMarkedAsTheCurrentPage(): void
    {
        $xpath = $this->render([
            $this->item(1, 'Diensten', '/diensten', [$this->item(2, 'Graveren', '/diensten', [], 'x')]),
            $this->item(3, 'Shop', '/shop.php', [], 'shop'),
        ], 'shop', '/diensten');

        $this->assertSame(['/diensten', '/shop.php'], array_map(
            static fn (\DOMElement $a): string => $a->getAttribute('href'),
            iterator_to_array($xpath->query('//a[@aria-current="page"]'))
        ), 'a parent with a submenu is marked like any top-level link; a submenu item never is');
    }

    public function testEveryLabelAndAddressIsEscaped(): void
    {
        $xpath = $this->render([$this->item(1, '<b>Tom & Co</b>', '/a"b', [$this->item(2, '<i>x</i>', '/x')])]);
        $this->assertStringNotContainsString('<b>', $this->lastHtml);
        $this->assertStringNotContainsString('<i>', $this->lastHtml);
        $this->assertSame('Submenu <b>Tom & Co</b>', $xpath->query('//button')->item(0)?->getAttribute('aria-label'));
        $this->assertSame('/a"b', $xpath->query('//a')->item(0)?->getAttribute('href'));
    }

    // ------------------------------------------------------ behaviour rules

    public function testTheScriptNeverCancelsAClickSoALinkAlwaysNavigates(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/assets/js/core.js');

        $this->assertStringNotContainsString('preventDefault', $script);
        $this->assertStringContainsString('toggle.addEventListener("click"', $script, 'only the toggle opens a submenu by click');
    }

    public function testOneFunctionSetsTheClassAndAriaExpandedTogether(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/assets/js/core.js');

        $this->assertMatchesRegularExpression('/function setOpen\(item, open, how\) \{.*classList\.add\("is-open"\).*classList\.remove\("is-open"\).*setAttribute\("aria-expanded", open \? "true" : "false"\)/s', $script);
        $this->assertStringContainsString('nav.classList.add("is-enhanced")', $script, 'the script switches the CSS-only hover off');
    }

    public function testHoverOpensOnlyForAMouseSoATapNeverNeedsASecondTap(): void
    {
        $script = (string) file_get_contents(self::ROOT . '/assets/js/core.js');

        $this->assertSame(2, substr_count($script, 'if (e.pointerType !== "mouse" || !desktop.matches) return;'), 'pointerenter and pointerleave both ignore touch and pen');
        $this->assertStringNotContainsString('mouseenter', $script);
        $this->assertStringNotContainsString('mouseover', $script);
    }

    /**
     * A :hover or :focus-within that opens a submenu or turns a chevron would
     * be a second state beside .is-open. It is allowed only as the fallback
     * for a page without JavaScript, which never has .is-enhanced.
     */
    public function testNoHoverRuleCompetesWithTheOpenState(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/assets/css/core.css');
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        $checked = 0;
        foreach (explode('}', $css) as $rule) {
            $brace = strrpos($rule, '{');
            if ($brace === false) {
                continue;
            }
            // Only the selector list of the innermost rule: an @media prelude
            // before it is cut off at its own brace.
            $selectors = explode(',', (string) preg_replace('/^.*\{/s', '', substr($rule, 0, $brace)));
            foreach ($selectors as $selector) {
                if (!preg_match('/:(hover|focus-within)[^,]*main-nav__(submenu|chevron)/', $selector)) {
                    continue;
                }
                $checked++;
                $this->assertStringStartsWith('.main-nav:not(.is-enhanced)', trim($selector), trim($selector));
            }
        }
        $this->assertGreaterThan(0, $checked, 'the no-JavaScript fallback is still there');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\NavigationPresentation;
use PHPUnit\Framework\TestCase;

/**
 * The closed lists that decide how a navigation item appears in the header,
 * and the rules that refuse a button where a button cannot be
 * (App\Service\NavigationPresentation, HEADER-FOOTER.md). No database: this is
 * the part both admin endpoints and the public header rely on.
 */
final class NavigationPresentationTest extends TestCase
{
    public function testAnUnknownOrMissingPresentationIsAMenuLink(): void
    {
        $this->assertSame('link', NavigationPresentation::of([]));
        $this->assertSame('link', NavigationPresentation::of(['presentation' => 'LINK']));
        $this->assertSame('link', NavigationPresentation::of(['presentation' => 'banner']));
        $this->assertSame('button', NavigationPresentation::of(['presentation' => 'button']));
    }

    public function testTheStyleIsOneOfTwoExistingButtonLooks(): void
    {
        $this->assertSame(['primary', 'ghost'], NavigationPresentation::variants());
        $this->assertSame('btn btn--sm', NavigationPresentation::buttonClass('primary'));
        $this->assertSame('btn btn--sm btn--ghost', NavigationPresentation::buttonClass('ghost'));
        $this->assertSame('btn btn--sm', NavigationPresentation::buttonClass('<script>'), 'nothing from a row becomes a class');
        $this->assertSame('primary', NavigationPresentation::variantOf(['button_variant' => 'btn--danger']));
    }

    public function testAMenuLinkHasNoPresentationRulesOfItsOwn(): void
    {
        $this->assertSame([], NavigationPresentation::errors('link', 'nonsense', 5, 'none', 3));
    }

    public function testATopLevelButtonWithADestinationIsFine(): void
    {
        foreach (['page', 'route', 'external'] as $linkType) {
            $this->assertSame([], NavigationPresentation::errors('button', 'ghost', null, $linkType, 0), $linkType);
        }
    }

    public function testWhatAButtonCannotBe(): void
    {
        $this->assertSame(['validation.navigation_presentation_invalid'], NavigationPresentation::errors('banner', 'primary', null, 'page', 0));
        $this->assertSame(['validation.navigation_button_variant_invalid'], NavigationPresentation::errors('button', 'huge', null, 'page', 0));
        $this->assertSame(['validation.navigation_button_in_submenu'], NavigationPresentation::errors('button', 'primary', 12, 'page', 0));
        $this->assertSame(['validation.navigation_button_needs_destination'], NavigationPresentation::errors('button', 'primary', null, 'none', 0));
        $this->assertSame(['validation.navigation_button_has_children'], NavigationPresentation::errors('button', 'primary', null, 'page', 2));
    }

    /** Every refusal has words for the editor, in both catalogs. */
    public function testEveryRefusalIsInTheCatalog(): void
    {
        $root = dirname(__DIR__, 2);
        $nl = require $root . '/src/Service/Language/messages/nl.php';
        $en = require $root . '/src/Service/Language/messages/en.php';

        $keys = array_merge(
            NavigationPresentation::errors('banner', 'primary', null, 'page', 0),
            NavigationPresentation::errors('button', 'huge', 1, 'none', 1)
        );

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $nl);
            $this->assertArrayHasKey($key, $en);
        }
    }
}

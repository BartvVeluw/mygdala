<?php

declare(strict_types=1);

namespace Tests\Service\Personalization;

use App\Service\Personalization\PersonalizationColors;
use PHPUnit\Framework\TestCase;

/**
 * The fixed text-colour palette.
 *
 * The point of these tests is not that five colours exist — it is that the
 * set is CLOSED. Every value that reaches a `color:` declaration on the
 * product page or in the CMS order screen has to be a literal this class
 * wrote, because the alternative is a style attribute built from browser
 * input.
 */
final class PersonalizationColorsTest extends TestCase
{
    public function testThePaletteIsASmallFixedSet(): void
    {
        $keys = PersonalizationColors::keys();

        $this->assertNotEmpty($keys);
        $this->assertLessThanOrEqual(8, count($keys), 'this is a palette, not a colour picker');
        $this->assertContains(PersonalizationColors::FALLBACK, $keys);
    }

    public function testEveryColourIsALiteralHexAndNothingElse(): void
    {
        foreach (PersonalizationColors::payload() as $color) {
            $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color['hex'], $color['key']);
            $this->assertNotSame('', $color['label']);
            $this->assertNotSame('', PersonalizationColors::label($color['key'], 'nl'));
            $this->assertNotSame('', PersonalizationColors::label($color['key'], 'en'));
            $this->assertArrayNotHasKey('label_en', $color, 'the payload carries one label, in the language of the request');
        }
    }

    /**
     * The whole safety argument in one test: anything that is not a key of
     * the palette becomes the fallback, so no browser value can ever reach a
     * style attribute.
     */
    public function testAnythingOutsideThePaletteResolvesToTheFallback(): void
    {
        $hostile = [
            'red; background:url(javascript:alert(1))',
            '#ff0000',
            'rgb(255,0,0)',
            'inherit',
            '',
            null,
            42,
            ['black'],
            true,
        ];

        foreach ($hostile as $value) {
            $this->assertSame(
                PersonalizationColors::FALLBACK,
                PersonalizationColors::resolveSubmitted($value),
                var_export($value, true) . ' must not survive'
            );
        }
    }

    public function testAValidKeySurvivesUnchanged(): void
    {
        foreach (PersonalizationColors::keys() as $key) {
            $this->assertSame($key, PersonalizationColors::resolveSubmitted($key));
            $this->assertTrue(PersonalizationColors::isValid($key));
        }
    }

    public function testAnUnknownKeyStillRendersInSomething(): void
    {
        // A text layer always has to have a colour, even if the stored key
        // somehow no longer exists.
        $this->assertSame(
            PersonalizationColors::hex(PersonalizationColors::FALLBACK),
            PersonalizationColors::hex('a_colour_that_never_existed')
        );
    }

    /**
     * The order's own copy: key, label and hex as they were at the moment of
     * purchase, so a later palette change cannot rewrite what a placed order
     * says the customer chose.
     */
    public function testTheOrderSnapshotCarriesLabelAndHexNotJustTheKey(): void
    {
        $snapshot = PersonalizationColors::snapshot('black');

        $this->assertSame('black', $snapshot['key']);
        $this->assertSame(PersonalizationColors::label('black'), $snapshot['label']);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $snapshot['hex']);
    }

    /**
     * White is the fallback on purpose: it is what every order placed before
     * the palette existed was actually previewed in, so those orders keep
     * looking the way they looked.
     */
    public function testTheFallbackMatchesWhatPrePaletteOrdersWereRenderedIn(): void
    {
        $this->assertSame('white', PersonalizationColors::FALLBACK);

        $style = (string) file_get_contents(dirname(__DIR__, 3) . '/assets/css/shop/personalization.css');
        $this->assertMatchesRegularExpression(
            '/\.personalizer__layer--text\{[^}]*color:\s*#F7F1E6/si',
            $style,
            'the pre-palette text layer colour and the fallback must be the same colour'
        );
    }
}

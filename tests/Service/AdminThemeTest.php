<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\AdminTheme;
use PHPUnit\Framework\TestCase;

/**
 * The closed set of dashboard themes, and what happens to anything outside
 * it. No database: this is the layer that decides what a value MEANS, and
 * it has to give the same answer when there is no database at all.
 *
 * Storage is Tests\Service\AdminThemePersistenceTest; where the choice ends
 * up in the markup is Tests\Service\AdminThemeContractTest.
 */
final class AdminThemeTest extends TestCase
{
    protected function tearDown(): void
    {
        AdminTheme::overrideForTests(null);

        parent::tearDown();
    }

    public function testTheRegistryIsTheFourFirstPartyThemesAndEigenKleuren(): void
    {
        $this->assertSame(['default', 'classic', 'ocean', 'black', 'custom'], AdminTheme::keys());
        $this->assertSame('custom', AdminTheme::CUSTOM_KEY);
    }

    public function testEveryThemeHasALabelAndADescription(): void
    {
        foreach (AdminTheme::all() as $key => $theme) {
            $this->assertNotSame('', trim($theme['label']), $key . ' has no label');
            $this->assertNotSame('', trim($theme['description']), $key . ' has no description');
        }
    }

    public function testDefaultIsTheFallbackKeyAndIsPartOfTheSet(): void
    {
        $this->assertSame('default', AdminTheme::DEFAULT_KEY);
        $this->assertTrue(AdminTheme::isValid(AdminTheme::DEFAULT_KEY));
    }

    public function testAThemeOutsideTheSetIsNotValid(): void
    {
        $this->assertFalse(AdminTheme::isValid('sunset'));
        $this->assertFalse(AdminTheme::isValid(''));
        $this->assertFalse(AdminTheme::isValid('DEFAULT'));
    }

    public function testNormaliseAcceptsTheClosedSetAndTrimsAndLowercases(): void
    {
        $this->assertSame('ocean', AdminTheme::normalise('ocean'));
        $this->assertSame('black', AdminTheme::normalise('  BLACK '));
        $this->assertSame('classic', AdminTheme::normalise('Classic'));
    }

    public function testNormaliseRejectsAnythingElse(): void
    {
        $this->assertNull(AdminTheme::normalise('sunset'));
        $this->assertNull(AdminTheme::normalise(''));
        $this->assertNull(AdminTheme::normalise(null));
        $this->assertNull(AdminTheme::normalise('ocean; --admin-bg: red'));
        $this->assertNull(AdminTheme::normalise('"><script>alert(1)</script>'));
    }

    public function testAnInvalidStoredThemeFallsBackToDefault(): void
    {
        AdminTheme::overrideForTests('sunset');

        $this->assertSame('default', AdminTheme::current());
        $this->assertTrue(AdminTheme::isDefault());
    }

    public function testTheBodyAttributeNamesTheSelectedTheme(): void
    {
        AdminTheme::overrideForTests('ocean');

        $this->assertSame(' data-admin-theme="ocean"', AdminTheme::bodyAttribute());
    }

    public function testTheBodyAttributeIsAlsoPrintedForTheDefaultTheme(): void
    {
        AdminTheme::overrideForTests('default');

        $this->assertSame(' data-admin-theme="default"', AdminTheme::bodyAttribute());
    }

    public function testTheBodyAttributeCanOnlyEverCarryAKeyFromTheSet(): void
    {
        foreach (['sunset', '" onload="x', "ocean' or 1=1"] as $hostile) {
            AdminTheme::overrideForTests($hostile);

            $this->assertSame(' data-admin-theme="default"', AdminTheme::bodyAttribute());
        }
    }

    public function testLabelFallsBackToTheDefaultThemesLabel(): void
    {
        $this->assertSame('Ocean', AdminTheme::label('ocean'));
        $this->assertSame(AdminTheme::label('default'), AdminTheme::label('sunset'));
    }

    // --- Eigen kleuren -------------------------------------------------------

    public function testEigenKleurenAsksForFiveColoursThatStartAsTheDefaultTheme(): void
    {
        $this->assertSame(['bg', 'sidebar', 'surface', 'text', 'accent'], array_keys(AdminTheme::COLORS));

        foreach (AdminTheme::COLORS as $name => $fallback) {
            $this->assertSame($fallback, AdminTheme::normaliseColor($fallback), $name . ' falls back to something that is not a stored colour');
        }
    }

    public function testAColourIsSixHexDigitsInTheShapesTheWebsiteColoursAccept(): void
    {
        $this->assertSame('#1e1a13', AdminTheme::normaliseColor('#1E1A13'));
        $this->assertSame('#abcdef', AdminTheme::normaliseColor('  abcdef '));
        $this->assertSame('#aabbcc', AdminTheme::normaliseColor('#abc'));
    }

    public function testAnythingElseIsNotAColour(): void
    {
        $hostile = [
            '', 'red', '#12345', '#1234567', '#12345g', 'url(x)', 'var(--admin-bg)',
            '#123456; --admin-bg: red', '#123456" onload="x', "#123456\n}", null, 123456, ['#123456'],
        ];

        foreach ($hostile as $value) {
            $this->assertNull(AdminTheme::normaliseColor($value), var_export($value, true));
        }
    }

    public function testASetOfColoursIsAcceptedWholeOrNotAtAll(): void
    {
        $colors = ['bg' => '#FFFFFF', 'sidebar' => '#f4f4f4', 'surface' => 'ffffff', 'text' => '#222', 'accent' => '#0a58ca', 'extra' => 'red'];

        $this->assertSame(
            ['bg' => '#ffffff', 'sidebar' => '#f4f4f4', 'surface' => '#ffffff', 'text' => '#222222', 'accent' => '#0a58ca'],
            AdminTheme::normaliseColors($colors)
        );

        $missing = $colors;
        unset($missing['text']);
        $this->assertNull(AdminTheme::normaliseColors($missing), 'one colour missing');
        $this->assertNull(AdminTheme::normaliseColors(['text' => 'red'] + $colors), 'one colour broken');
        $this->assertNull(AdminTheme::normaliseColors('#ffffff'));
        $this->assertNull(AdminTheme::normaliseColors(null));
    }

    public function testAMissingOrBrokenStoredColourIsTheDefaultThemesColour(): void
    {
        AdminTheme::overrideForTests('custom', ['bg' => '#000000', 'text' => 'red', 'accent' => 'url(x)']);

        $this->assertSame(
            ['bg' => '#000000', 'sidebar' => '#19160f', 'surface' => '#1e1a13', 'text' => '#f1ead9', 'accent' => '#cda34d'],
            AdminTheme::customColors()
        );
    }

    public function testTheBodyAttributeCarriesTheColoursOnlyForEigenKleuren(): void
    {
        AdminTheme::overrideForTests('custom', ['bg' => '#FFFFFF', 'sidebar' => '#f4f4f4', 'surface' => '#ffffff', 'text' => '#222222', 'accent' => '#0a58ca']);

        $this->assertSame(
            ' data-admin-theme="custom" style="--admin-custom-bg: #ffffff; --admin-custom-sidebar: #f4f4f4; '
                . '--admin-custom-surface: #ffffff; --admin-custom-text: #222222; --admin-custom-accent: #0a58ca"',
            AdminTheme::bodyAttribute()
        );

        // A fixed theme prints no colours, even when colours are stored.
        AdminTheme::overrideForTests('ocean', ['bg' => '#ffffff']);
        $this->assertSame(' data-admin-theme="ocean"', AdminTheme::bodyAttribute());
    }

    public function testNoStoredValueCanBreakOutOfTheStyleAttribute(): void
    {
        AdminTheme::overrideForTests('custom', [
            'bg' => '#000000" onload="alert(1)',
            'text' => '#fff; } body { display: none',
            'accent' => 'expression(alert(1))',
        ]);

        $attribute = AdminTheme::bodyAttribute();

        $this->assertStringNotContainsString('onload', $attribute);
        $this->assertStringNotContainsString('display', $attribute);
        $this->assertStringNotContainsString('expression', $attribute);
        $this->assertStringContainsString('--admin-custom-bg: #14120d', $attribute);
    }

    public function testThePrintedPropertiesLeaveOutWhatIsNotAColour(): void
    {
        $this->assertSame('--admin-custom-text: #222222', AdminTheme::customProperties(['text' => '#222', 'bg' => 'red']));
        $this->assertSame('', AdminTheme::customProperties([]));
    }
}

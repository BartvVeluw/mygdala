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

    public function testTheRegistryIsExactlyTheFourFirstPartyThemes(): void
    {
        $this->assertSame(['default', 'classic', 'ocean', 'black'], AdminTheme::keys());
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
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\ThemeDefinition;
use App\Service\Theme\ThemeRegistry;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The closed list of Global Themes and how the stored key resolves against
 * it: `legacy` always exists and is what every absent, empty or unknown key
 * becomes, and a stored value is only ever compared with the list — never
 * turned into a path.
 *
 * The stored key is faked with ThemeSettings::overrideForTests(), no
 * database. What the real row does is ThemePersistenceTest's.
 */
final class ThemeRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
        parent::tearDown();
    }

    public function testLegacyIsARegisteredThemeWithoutAStylesheet(): void
    {
        $legacy = ThemeRegistry::find('legacy');

        $this->assertInstanceOf(ThemeDefinition::class, $legacy);
        $this->assertSame('legacy', $legacy->key);
        $this->assertNull($legacy->stylesheet);
    }

    public function testTheFallbackIsLegacy(): void
    {
        $fallback = ThemeRegistry::fallback();

        $this->assertSame(ThemeRegistry::FALLBACK_KEY, $fallback->key);
        $this->assertSame('legacy', $fallback->key);
        $this->assertNull($fallback->stylesheet);
    }

    /**
     * Core ships legacy and minimal, in that order, and nothing else: no
     * second name for the same look (`default`, `light`), no proof or test
     * theme in the release. A new first-party theme adds itself here
     * deliberately.
     */
    public function testTheListHoldsExactlyLegacyAndMinimal(): void
    {
        $this->assertSame(['legacy', 'minimal'], array_keys(ThemeRegistry::all()));
    }

    public function testMinimalIsAFirstPartyThemeWithItsOwnStylesheet(): void
    {
        $minimal = ThemeRegistry::find('minimal');

        $this->assertInstanceOf(ThemeDefinition::class, $minimal);
        $this->assertSame('Minimal', $minimal->label);
        $this->assertSame('assets/css/themes/minimal.css', $minimal->stylesheet);
    }

    /**
     * Registering a theme activates nothing: every site without a stored
     * key, existing or fresh, stays on legacy.
     */
    public function testRegisteringMinimalDoesNotMakeItTheActiveTheme(): void
    {
        ThemeSettings::overrideForTests([]);
        $this->assertSame('legacy', ThemeRegistry::active()->key);

        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => '']);
        $this->assertSame('legacy', ThemeRegistry::active()->key);

        $this->assertNotSame('minimal', ThemeRegistry::fallback()->key);
        $this->assertArrayNotHasKey(ThemeSettings::ACTIVE_THEME_KEY, ThemeSettings::defaults());
    }

    public function testAStoredMinimalResolvesToMinimal(): void
    {
        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => 'minimal']);

        $this->assertSame(ThemeRegistry::find('minimal'), ThemeRegistry::active());
    }

    public function testTheListIsTheSameEveryTime(): void
    {
        $this->assertSame(array_keys(ThemeRegistry::all()), array_keys(ThemeRegistry::all()));
        foreach (ThemeRegistry::all() as $key => $definition) {
            $this->assertSame($key, $definition->key);
        }
    }

    public function testAnUnknownKeyFindsNothing(): void
    {
        $this->assertNull(ThemeRegistry::find('kobold'));
        $this->assertNull(ThemeRegistry::find(''));
        $this->assertNull(ThemeRegistry::find('LEGACY'));
    }

    public function testNoStoredKeyResolvesToLegacy(): void
    {
        ThemeSettings::overrideForTests([]);

        $this->assertSame('', ThemeSettings::activeThemeKey());
        $this->assertSame('legacy', ThemeRegistry::active()->key);
    }

    public function testAKnownStoredKeyResolvesToItsDefinition(): void
    {
        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => 'legacy']);

        $this->assertSame(ThemeRegistry::find('legacy'), ThemeRegistry::active());
    }

    /** @return array<string, array{string}> */
    public static function storedValuesThatAreNoTheme(): array
    {
        return [
            'empty' => [''],
            'unknown' => ['kobold'],
            'removed later' => ['light-minimal'],
            'uppercase' => ['LEGACY'],
            'capitalised label of a listed theme' => ['Minimal'],
            'whitespace around a listed theme' => ['minimal '],
            'the stylesheet of a listed theme' => ['assets/css/themes/minimal.css'],
            'its file name' => ['minimal.css'],
            'whitespace around' => [' legacy '],
            'traversal to a stylesheet' => ['../../evil.css'],
            'a stylesheet path' => ['assets/css/core.css'],
            'slash' => ['themes/legacy'],
            'backslash' => ['..\\..\\evil.css'],
            'url' => ['https://evil.example/theme.css'],
            'markup' => ['"><script>alert(1)</script>'],
        ];
    }

    /**
     * Whatever the row holds, the outcome is legacy or a listed theme —
     * never a definition made from the value.
     */
    #[DataProvider('storedValuesThatAreNoTheme')]
    public function testAStoredValueThatIsNoListedThemeResolvesToLegacy(string $stored): void
    {
        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => $stored]);

        $active = ThemeRegistry::active();

        $this->assertSame('legacy', $active->key);
        $this->assertNull($active->stylesheet);
        $this->assertSame($stored, ThemeSettings::activeThemeKey(), 'the stored value is read as it is, not repaired');
    }

    public function testADuplicateKeyIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate theme key: legacy');

        ThemeRegistry::index([ThemeRegistry::fallback(), new ThemeDefinition('legacy', 'Nog eens')]);
    }

    public function testAListWithoutLegacyIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        ThemeRegistry::index([new ThemeDefinition('proof', 'Proof')]);
    }

    public function testIndexKeepsTheDeclaredOrder(): void
    {
        $indexed = ThemeRegistry::index([
            new ThemeDefinition('zz-last', 'Z'),
            ThemeRegistry::fallback(),
            new ThemeDefinition('aa-first', 'A'),
        ]);

        $this->assertSame(['zz-last', 'legacy', 'aa-first'], array_keys($indexed));
    }

    /**
     * The registry is a list in code. No scan of a folder, no reflection or
     * class discovery, no definition from the database; and no blanket
     * catch that would turn a broken database into "no theme chosen".
     */
    public function testTheRegistryDiscoversNothingAndSwallowsNothingBroadly(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Service/Theme/ThemeRegistry.php');
        $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (['scandir', 'glob(', 'opendir', 'DirectoryIterator', 'Reflection', 'class_exists', 'get_declared_classes', 'Repository', 'Database', 'PDO', '$_GET', '$_POST', '$_REQUEST'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code, 'ThemeRegistry must not use ' . $forbidden);
        }

        $this->assertDoesNotMatchRegularExpression('/catch\s*\(\s*\\\\?(Throwable|Exception)\b/', $code);
    }

    /**
     * The Global Theme is not an appearance value: storing one changes
     * none of what "restore the default appearance" works on.
     */
    public function testAStoredThemeIsNoAppearanceSetting(): void
    {
        $keys = ThemeSettings::keys();
        $defaults = ThemeSettings::defaults();

        ThemeSettings::overrideForTests([ThemeSettings::ACTIVE_THEME_KEY => 'kobold']);

        $this->assertNotContains(ThemeSettings::ACTIVE_THEME_KEY, ThemeSettings::keys());
        $this->assertArrayNotHasKey(ThemeSettings::ACTIVE_THEME_KEY, ThemeSettings::defaults());
        $this->assertSame($keys, ThemeSettings::keys());
        $this->assertSame($defaults, ThemeSettings::defaults());
        $this->assertTrue(ThemeSettings::isDefault());
        $this->assertSame([], ThemeSettings::changedKeys());
        $this->assertArrayNotHasKey(ThemeSettings::ACTIVE_THEME_KEY, ThemeSettings::all());
    }

    /** The appearance keys, pinned: the theme key must not slip in later. */
    public function testTheAppearanceKeysAreUnchanged(): void
    {
        $this->assertSame([
            'primary_color',
            'on_primary_color',
            'background_color',
            'surface_color',
            'text_color',
            'font_pairing',
            'heading_font_family_id',
            'body_font_family_id',
            'button_shape',
        ], ThemeSettings::keys());
    }
}

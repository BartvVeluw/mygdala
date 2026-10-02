<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\ThemeDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shape a Global Theme definition may have: a technical key that can
 * never pass for a path, and a stylesheet that is null or a local .css path
 * PageAssets would print. Shape only — whether the file exists is runtime
 * availability (PageAssets::themeStylesheet(), ThemeRenderingTest).
 */
final class ThemeDefinitionTest extends TestCase
{
    public function testAValidThemeKeepsWhatItWasGiven(): void
    {
        $theme = new ThemeDefinition('light-minimal', 'Licht', 'assets/css/themes/light-minimal.css');

        $this->assertSame('light-minimal', $theme->key);
        $this->assertSame('Licht', $theme->label);
        $this->assertSame('assets/css/themes/light-minimal.css', $theme->stylesheet);
    }

    public function testAThemeMayHaveNoStylesheet(): void
    {
        $this->assertNull((new ThemeDefinition('legacy', 'Klassiek'))->stylesheet);
    }

    /**
     * The stylesheet is written out, not derived from the key: a theme may
     * keep it anywhere PageAssets can load from, so a later Client
     * Extension is not locked out of its own namespace.
     */
    public function testTheStylesheetIsNotTiedToTheThemesFolder(): void
    {
        $theme = new ThemeDefinition('kobold', 'Kobold', 'assets/css/extensions/kobold/theme.css');

        $this->assertSame('assets/css/extensions/kobold/theme.css', $theme->stylesheet);
    }

    /** @return array<string, array{string}> */
    public static function validKeys(): array
    {
        return [
            'shortest' => ['ab'],
            'longest' => ['a' . str_repeat('b', 39)],
            'digits' => ['theme2'],
            'hyphen' => ['light-minimal'],
        ];
    }

    #[DataProvider('validKeys')]
    public function testAValidKey(string $key): void
    {
        $this->assertTrue(ThemeDefinition::isValidKey($key));
        $this->assertSame($key, (new ThemeDefinition($key, 'Label'))->key);
    }

    /** @return array<string, array{string}> */
    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'one character' => ['a'],
            'too long' => ['a' . str_repeat('b', 40)],
            'leading digit' => ['2theme'],
            'leading hyphen' => ['-theme'],
            'uppercase' => ['Legacy'],
            'all uppercase' => ['LEGACY'],
            'dot' => ['legacy.css'],
            'slash' => ['themes/legacy'],
            'backslash' => ['themes\\legacy'],
            'traversal' => ['../../evil'],
            'space' => ['light minimal'],
            'trailing newline' => ["legacy\n"],
            'tab' => ["legacy\t"],
            'url' => ['https://evil.example/x'],
            'protocol only' => ['javascript:alert'],
            'underscore' => ['light_minimal'],
            'null byte' => ["legacy\0"],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRefused(string $key): void
    {
        $this->assertFalse(ThemeDefinition::isValidKey($key));

        $this->expectException(\InvalidArgumentException::class);
        new ThemeDefinition($key, 'Label');
    }

    public function testALabelIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ThemeDefinition('legacy', '  ');
    }

    /** @return array<string, array{string}> */
    public static function invalidStylesheets(): array
    {
        return [
            'empty' => [''],
            'not css' => ['assets/css/themes/x.js'],
            'no extension' => ['assets/css/themes/x'],
            'outside assets' => ['css/themes/x.css'],
            'absolute' => ['/assets/css/themes/x.css'],
            'windows absolute' => ['C:/assets/css/themes/x.css'],
            'traversal' => ['assets/css/../../config/x.css'],
            'traversal at start' => ['../assets/css/x.css'],
            'protocol' => ['https://cdn.example/x.css'],
            'protocol-relative' => ['//cdn.example/x.css'],
            'data url' => ['data:text/css,body{}'],
            'query' => ['assets/css/themes/x.css?v=1'],
            'fragment' => ['assets/css/themes/x.css#a'],
            'backslash' => ['assets\\css\\themes\\x.css'],
            'space' => ['assets/css/themes/x y.css'],
            'trailing newline' => ["assets/css/themes/x.css\n"],
            'quote' => ['assets/css/themes/x".css'],
            'angle bracket' => ['assets/css/themes/<x>.css'],
        ];
    }

    #[DataProvider('invalidStylesheets')]
    public function testAnUnsafeStylesheetIsRefused(string $path): void
    {
        $this->assertFalse(ThemeDefinition::isValidStylesheet($path));

        $this->expectException(\InvalidArgumentException::class);
        new ThemeDefinition('proof', 'Proof', $path);
    }

    /** A definition is data: building one never touches the disk. */
    public function testAValidStylesheetNeedNotExistYet(): void
    {
        $theme = new ThemeDefinition('proof', 'Proof', 'assets/css/themes/not-shipped.css');

        $this->assertSame('assets/css/themes/not-shipped.css', $theme->stylesheet);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\PageAppearance;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemePalette;
use App\Service\Theme\ThemeSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tint formulas as ONE recipe with two readers (Branding & Design 2.0):
 * ThemePalette::derive() in PHP, and the palette editor's live preview in
 * admin/assets/theme-admin.js (MygdalaTheme.tokens()), which evaluates the
 * recipe the page prints instead of carrying formulas of its own.
 *
 *   - derive() returns exactly what it returned before the recipe (values
 *     pinned from v0.1.13), so no existing site renders differently; the
 *     one later addition is --color-sheen-rgb (Themes 2.0 phase 1A), the
 *     text colour's channels, so a light theme's sheen stays visible;
 *   - dependencies() — which ThemeCss uses to emit only what changed — names
 *     exactly the roles each recipe entry reads, directly or through an
 *     earlier property;
 *   - the recipe is plain data for json_encode(), and every operation it
 *     uses is one the script implements;
 *   - paletteDeclarations() is the one complete token set, shared by a page
 *     theme and the preview.
 *
 * Pure: no database. Suites unit and fast.
 */
final class ThemePaletteRecipeTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, string>, 1: array<string, string>}> */
    public static function pinnedPalettes(): iterable
    {
        yield 'light blue' => [
            ['primary' => '#2B6CB0', 'background' => '#FFFFFF', 'surface' => '#F4F6F8', 'text' => '#1A202C'],
            [
                '--color-primary-rgb' => '43, 108, 176',
                '--color-primary-bright' => '#4F90D4',
                '--color-primary-bright-rgb' => '79, 144, 212',
                '--color-primary-deep' => '#183C62',
                '--color-primary-deep-rgb' => '24, 60, 98',
                '--color-text-rgb' => '26, 32, 44',
                '--color-bg-deep' => '#FAFAFA',
                '--color-bg-gradient-end' => '#FDFDFD',
                '--color-scrim-rgb' => '38, 38, 38',
                '--color-bg-veil-rgb' => '255, 255, 255',
                '--color-media-scrim-rgb' => '255, 255, 255',
                '--color-surface-veil-rgb' => '244, 246, 248',
                '--color-surface-hover' => '#EBF0F5',
                '--color-surface-2' => '#FAFBFC',
                '--color-sheen-rgb' => '26, 32, 44',
            ],
        ];
        yield 'dark orange' => [
            ['primary' => '#FF7518', 'background' => '#1A0F24', 'surface' => '#2A1838', 'text' => '#FDF4E3'],
            [
                '--color-primary-rgb' => '255, 117, 24',
                '--color-primary-bright' => '#FFA05F',
                '--color-primary-bright-rgb' => '255, 160, 95',
                '--color-primary-deep' => '#B64900',
                '--color-primary-deep-rgb' => '182, 73, 0',
                '--color-text-rgb' => '253, 244, 227',
                '--color-bg-deep' => '#150C1E',
                '--color-bg-gradient-end' => '#180E21',
                '--color-scrim-rgb' => '4, 2, 5',
                '--color-bg-veil-rgb' => '26, 15, 36',
                '--color-media-scrim-rgb' => '26, 15, 36',
                '--color-surface-veil-rgb' => '42, 24, 56',
                '--color-surface-hover' => '#341C37',
                '--color-surface-2' => '#22142E',
                '--color-sheen-rgb' => '253, 244, 227',
            ],
        ];
        yield 'greys at the clamps' => [
            ['primary' => '#000000', 'background' => '#000000', 'surface' => '#FFFFFF', 'text' => '#808080'],
            [
                '--color-primary-rgb' => '0, 0, 0',
                '--color-primary-bright' => '#242424',
                '--color-primary-bright-rgb' => '36, 36, 36',
                '--color-primary-deep' => '#000000',
                '--color-primary-deep-rgb' => '0, 0, 0',
                '--color-text-rgb' => '128, 128, 128',
                '--color-bg-deep' => '#000000',
                '--color-bg-gradient-end' => '#000000',
                '--color-scrim-rgb' => '0, 0, 0',
                '--color-bg-veil-rgb' => '0, 0, 0',
                '--color-media-scrim-rgb' => '0, 0, 0',
                '--color-surface-veil-rgb' => '255, 255, 255',
                '--color-surface-hover' => '#F4F4F4',
                '--color-surface-2' => '#808080',
                '--color-sheen-rgb' => '128, 128, 128',
            ],
        ];
    }

    /**
     * @param array{primary: string, background: string, surface: string, text: string} $colors
     * @param array<string, string> $expected
     */
    #[DataProvider('pinnedPalettes')]
    public function testDeriveReturnsExactlyWhatItReturnedBeforeTheRecipe(array $colors, array $expected): void
    {
        self::assertSame($expected, ThemePalette::derive($colors));
    }

    public function testEachDependencyListNamesExactlyTheRolesTheRecipeReads(): void
    {
        $readers = [];
        foreach (ThemePalette::recipe() as [$property, $expression]) {
            foreach (self::rolesIn($expression, $readers) as $role) {
                $readers[$property][] = $role;
            }
            $readers[$property] = array_values(array_unique($readers[$property] ?? []));
        }

        $expected = [];
        foreach ($readers as $property => $roles) {
            foreach ($roles as $role) {
                $expected[$role][] = $property;
            }
        }

        $actual = ThemePalette::dependencies();
        self::assertSame(ThemePalette::ROLES, array_keys($actual));
        foreach (ThemePalette::ROLES as $role) {
            $want = $expected[$role] ?? [];
            $have = $actual[$role];
            sort($want);
            sort($have);
            self::assertSame($want, $have, 'dependencies of ' . $role);
        }
    }

    public function testTheRecipeIsPlainDataAndUsesOnlyWhatTheScriptImplements(): void
    {
        $recipe = ThemePalette::recipe();
        self::assertSame($recipe, json_decode(json_encode($recipe, JSON_THROW_ON_ERROR), true));

        $ops = [];
        $walk = static function (mixed $expression) use (&$walk, &$ops): void {
            if (is_array($expression)) {
                $ops[$expression[0]] = true;
                foreach (array_slice($expression, 1) as $argument) {
                    $walk($argument);
                }
            }
        };
        foreach ($recipe as [, $expression]) {
            $walk($expression);
        }

        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/assets/theme-admin.js');
        self::assertSame(['channels', 'lighten', 'mix'], self::sorted(array_keys($ops)));
        foreach (array_keys($ops) as $op) {
            self::assertStringContainsString("case '" . $op . "':", $script, 'MygdalaTheme evaluates ' . $op);
        }
        self::assertStringContainsString('window.MygdalaTheme', $script);
    }

    public function testThePaletteTokenSetIsTheOneAPageThemeRestates(): void
    {
        $colors = [
            'primary_color' => '#FF7518',
            'on_primary_color' => '#111111',
            'background_color' => '#1A0F1F',
            'surface_color' => '#2A1A30',
            'text_color' => '#F7F1E8',
        ];

        $tokens = ThemeCss::paletteDeclarations($colors);
        self::assertSame(array_values(ThemeCss::DIRECT), array_slice(array_keys($tokens), 0, 5));
        self::assertSame('#FF7518', $tokens['--color-primary']);

        $appearance = PageAppearance::fromTheme('x', $colors, ThemeSettings::defaults()['font_pairing']);
        self::assertNotNull($appearance);
        self::assertSame($tokens, array_slice($appearance->declarations(), 0, count($tokens), true));
    }

    /**
     * @param array<string, list<string>> $readers roles read by earlier properties
     * @return list<string>
     */
    private static function rolesIn(mixed $expression, array $readers): array
    {
        if (is_string($expression)) {
            if (in_array($expression, ThemePalette::ROLES, true)) {
                return [$expression];
            }

            return $readers[$expression] ?? [];
        }

        $roles = [];
        foreach (array_slice($expression, 1) as $argument) {
            if (!is_float($argument) && !is_int($argument)) {
                $roles = array_merge($roles, self::rolesIn($argument, $readers));
            }
        }

        return $roles;
    }

    /**
     * @param list<string> $list
     * @return list<string>
     */
    private static function sorted(array $list): array
    {
        sort($list);

        return $list;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Service\Theme\FontLibrary;
use App\Service\Theme\FontVariant;
use App\Service\Theme\PageAppearance;
use App\Service\Theme\ThemeCss;
use App\Service\Theme\ThemeFonts;
use App\Service\Theme\ThemeSettings;
use App\Service\Theme\ThemeTypography;
use PHPUnit\Framework\TestCase;

/**
 * The Font Library as CSS, without a database (FontLibrary::overrideForTests()):
 *
 *   - one @font-face rule per variant: the generated family name, the
 *     generated file URL, the format, weight, style and font-display: swap;
 *     a hand-edited row (a name with CSS in it, an odd weight, a style that
 *     is not in the list, a format that does not match) is left out;
 *   - the stacks: a family's own name then the fallback of its kind, per
 *     role over the pairing; the pairing's Google stylesheet only while a
 *     role still uses it; an unusable family counts as the pairing;
 *   - ThemeSettings and ThemeCss: the website's roles as tokens, validated,
 *     nothing an administrator typed ever in them; the built-in pairings
 *     unchanged when no family is chosen;
 *   - a page theme's families, and the refusal of a theme whose family is
 *     not usable.
 */
final class FontLibraryCssTest extends TestCase
{
    private const EVIL = "Evil'; } body { display:none } </style><script>alert(1)</script>";

    protected function setUp(): void
    {
        FontLibrary::overrideForTests([
            7 => ['id' => 7, 'name' => self::EVIL, 'category' => 'serif', 'variant_count' => 2],
            9 => ['id' => 9, 'name' => 'Gewoon', 'category' => 'sans', 'variant_count' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        ThemeSettings::overrideForTests(null);
        FontLibrary::overrideForTests(null);
    }

    public function testARuleIsMadeOnlyOfGeneratedValues(): void
    {
        $rule = FontLibrary::fontFaceRule(self::file(['weight' => 700, 'style' => 'italic']));

        self::assertSame(
            '@font-face{font-family:"mygdala-font-7";src:url("/assets/fonts/library/0123456789abcdef0123456789abcdef.woff2") format("woff2");font-weight:700;font-style:italic;font-display:swap;}',
            $rule
        );
    }

    public function testEachFormatGetsItsCssFormatName(): void
    {
        foreach (['woff2' => 'woff2', 'woff' => 'woff', 'ttf' => 'truetype', 'otf' => 'opentype'] as $format => $hint) {
            $rule = FontLibrary::fontFaceRule(self::file(['format' => $format, 'file_name' => str_repeat('a', 32) . '.' . $format]));
            self::assertStringContainsString('format("' . $hint . '")', (string) $rule);
        }
    }

    public function testAHandEditedRowIsLeftOutRatherThanRepaired(): void
    {
        foreach ([
            ['file_name' => 'x") } body { color: red } .x { src: url("y.woff2'],
            ['file_name' => '../../.env'],
            ['file_name' => str_repeat('a', 32) . '.php'],
            ['file_name' => str_repeat('a', 32) . '.ttf'], // the format says woff2
            ['weight' => 450],
            ['weight' => 0],
            ['style' => 'oblique'],
            ['format' => 'eot'],
            ['font_family_id' => 0],
        ] as $broken) {
            self::assertNull(FontLibrary::fontFaceRule(self::file($broken)), json_encode($broken));
        }
    }

    public function testTheCssNameComesFromTheIdNeverFromTheName(): void
    {
        self::assertSame('mygdala-font-7', FontLibrary::cssFamilyName(7));
        self::assertSame("'mygdala-font-7', " . FontLibrary::CATEGORIES['serif'], FontLibrary::stack(7));
        self::assertSame("'mygdala-font-9', " . FontLibrary::CATEGORIES['sans'], FontLibrary::stack(9));
        self::assertNull(FontLibrary::stack(8), 'not a usable family');
        self::assertStringNotContainsString('Evil', (string) FontLibrary::stack(7));
    }

    public function testEachRoleUsesItsFamilyOverThePairing(): void
    {
        $pairing = ThemeFonts::pairing('lora-montserrat');

        self::assertSame(['heading' => $pairing['heading'], 'body' => $pairing['body']], ThemeTypography::stacks('lora-montserrat', null, null));
        self::assertSame(['heading' => FontLibrary::stack(7), 'body' => $pairing['body']], ThemeTypography::stacks('lora-montserrat', 7, null));
        self::assertSame(['heading' => $pairing['heading'], 'body' => FontLibrary::stack(9)], ThemeTypography::stacks('lora-montserrat', null, 9));
        self::assertSame(['heading' => $pairing['heading'], 'body' => $pairing['body']], ThemeTypography::stacks('lora-montserrat', 8, 8), 'an unusable family is the pairing');
    }

    public function testThePairingsStylesheetOnlyWhileARoleStillUsesIt(): void
    {
        $url = ThemeFonts::pairing('lora-montserrat')['url'];

        self::assertSame($url, ThemeTypography::pairingStylesheetUrl('lora-montserrat', null, null));
        self::assertSame($url, ThemeTypography::pairingStylesheetUrl('lora-montserrat', 7, null));
        self::assertNull(ThemeTypography::pairingStylesheetUrl('lora-montserrat', 7, 9), 'both roles self-hosted: no Google request at all');
        self::assertNull(ThemeTypography::pairingStylesheetUrl('system', null, null));
    }

    public function testTheFamiliesInUseAreEachListedOnce(): void
    {
        self::assertSame([7], ThemeTypography::familyIds(7, 7));
        self::assertSame([7, 9], ThemeTypography::familyIds(7, 9));
        self::assertSame([], ThemeTypography::familyIds(null, 8));
    }

    public function testTheWebsitesRolesBecomeTokensAndNothingTypedReachesThem(): void
    {
        ThemeSettings::overrideForTests(['heading_font_family_id' => '7', 'body_font_family_id' => '9']);

        $declarations = ThemeCss::declarations();

        self::assertSame(FontLibrary::stack(7), $declarations['--font-display']);
        self::assertSame(FontLibrary::stack(9), $declarations['--font-body']);
        self::assertSame([7, 9], ThemeCss::fontFamilyIds());
        self::assertNull(ThemeCss::fontStylesheetUrl());
        self::assertStringNotContainsString('Evil', ThemeCss::styleBlock());
        self::assertStringNotContainsString('script', ThemeCss::styleBlock());
    }

    public function testOneRoleKeepsThePairingForTheOther(): void
    {
        ThemeSettings::overrideForTests(['font_pairing' => 'cormorant-inter', 'body_font_family_id' => '9']);

        $declarations = ThemeCss::declarations();

        self::assertSame(ThemeFonts::pairing('cormorant-inter')['heading'], $declarations['--font-display']);
        self::assertSame(FontLibrary::stack(9), $declarations['--font-body']);
        self::assertSame(ThemeFonts::pairing('cormorant-inter')['url'], ThemeCss::fontStylesheetUrl());
        self::assertSame([9], ThemeCss::fontFamilyIds());
    }

    public function testTheBuiltInPairingsWorkExactlyAsBefore(): void
    {
        ThemeSettings::overrideForTests([]);
        self::assertSame([], ThemeCss::declarations());
        self::assertSame(ThemeFonts::pairing(ThemeFonts::DEFAULT_KEY)['url'], ThemeCss::fontStylesheetUrl());
        self::assertSame([], ThemeCss::fontFamilyIds());

        foreach (ThemeFonts::keys() as $key) {
            ThemeSettings::overrideForTests(['font_pairing' => $key]);
            $pairing = ThemeFonts::pairing($key);
            $declarations = ThemeCss::declarations();

            if ($key === ThemeFonts::DEFAULT_KEY) {
                self::assertArrayNotHasKey('--font-display', $declarations);
                continue;
            }

            self::assertSame($pairing['heading'], $declarations['--font-display'], $key);
            self::assertSame($pairing['body'], $declarations['--font-body'], $key);
            self::assertSame($pairing['url'], ThemeCss::fontStylesheetUrl(), $key);
        }
    }

    public function testARoleAcceptsOnlyAUsableFamilyOrNothing(): void
    {
        $valid = ThemeSettings::validate(['heading_font_family_id' => '7', 'body_font_family_id' => '']);
        self::assertSame(['heading_font_family_id' => '7', 'body_font_family_id' => ''], $valid['values']);
        self::assertSame([], $valid['errors']);

        foreach (['8', '0', '-7', '7abc', "7'; x", '7.0', 'mygdala-font-7'] as $bad) {
            $checked = ThemeSettings::validate(['heading_font_family_id' => $bad]);
            self::assertArrayHasKey('heading_font_family_id', $checked['errors'], $bad);
            self::assertSame([], $checked['values'], $bad);
        }
    }

    public function testAPreviewShowsTheSitesValueForAnythingRefused(): void
    {
        ThemeSettings::overrideForTests(['font_pairing' => 'lora-montserrat', 'heading_font_family_id' => '9']);

        self::assertSame(
            ['font_pairing' => 'poppins-inter', 'heading_font_family_id' => '7', 'body_font_family_id' => ''],
            ThemeSettings::previewFonts(['font_pairing' => 'poppins-inter', 'heading_font_family_id' => '7'])
        );
        self::assertSame(
            ['font_pairing' => 'lora-montserrat', 'heading_font_family_id' => '9', 'body_font_family_id' => ''],
            ThemeSettings::previewFonts(['font_pairing' => 'comic-sans', 'heading_font_family_id' => '8', 'body_font_family_id' => ['x']])
        );
    }

    public function testAPageThemeUsesItsOwnFamilies(): void
    {
        $colors = [
            'primary_color' => '#FF7518', 'on_primary_color' => '#111111', 'background_color' => '#1A0F1F',
            'surface_color' => '#2A1A30', 'text_color' => '#F7F1E8',
        ];

        $appearance = PageAppearance::fromTheme('actie', $colors, 'lora-montserrat', 9, null);
        self::assertNotNull($appearance);
        self::assertSame(FontLibrary::stack(9), $appearance->declarations()['--font-display']);
        self::assertSame(ThemeFonts::pairing('lora-montserrat')['body'], $appearance->declarations()['--font-body']);
        self::assertSame([9], $appearance->fontFamilyIds());
        self::assertSame(ThemeFonts::pairing('lora-montserrat')['url'], $appearance->fontStylesheetUrl());

        $both = PageAppearance::fromTheme('actie', $colors, 'lora-montserrat', 7, 9);
        self::assertNull($both->fontStylesheetUrl());
        self::assertSame([7, 9], $both->fontFamilyIds());

        self::assertNull(PageAppearance::fromTheme('actie', $colors, 'lora-montserrat', 8, null), 'a family without a file is refused, never swapped silently');
    }

    public function testTheVariantsAreAClosedListWithPlainNames(): void
    {
        self::assertCount(18, FontVariant::keys());
        self::assertSame(['weight' => 700, 'style' => 'italic'], FontVariant::parse('700-italic'));
        self::assertSame(['weight' => 400, 'style' => 'normal'], FontVariant::parse('400'));
        foreach (['450', '1000', '0', '700-oblique', '700italic', 'bold', ''] as $bad) {
            self::assertNull(FontVariant::parse($bad), $bad);
        }

        $t = static fn (string $key, array $replace): string => \App\Service\Language\AdminTranslator::trans($key, $replace, 'nl');
        self::assertSame('Normaal', FontVariant::label(400, 'normal', $t));
        self::assertSame('Vet', FontVariant::label(700, 'normal', $t));
        self::assertSame('Cursief', FontVariant::label(400, 'italic', $t));
        self::assertSame('Halfvet cursief', FontVariant::label(600, 'italic', $t));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function file(array $overrides = []): array
    {
        return $overrides + [
            'font_family_id' => 7,
            'weight' => 400,
            'style' => 'normal',
            'format' => 'woff2',
            'file_name' => '0123456789abcdef0123456789abcdef.woff2',
        ];
    }
}

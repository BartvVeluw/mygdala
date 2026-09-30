<?php

declare(strict_types=1);

namespace App\Service\Theme;

/**
 * The one rule for which fonts a look uses — the website's, or a page
 * theme's: a built-in font pairing (App\Service\Theme\ThemeFonts) as the
 * base, and for each of the two roles optionally a family from the Font
 * Library (App\Service\Theme\FontLibrary).
 *
 *   heading role  --font-display   the family's stack, else the pairing's heading
 *   body role     --font-body      the family's stack, else the pairing's body
 *
 * What gets downloaded follows from the same answer: the pairing's Google
 * stylesheet only while at least one role still uses the pairing (both
 * roles on library families = no external request at all), and the
 * `@font-face` rules of exactly the families in use.
 *
 * A family id that is not usable (no file left, a hand-edited row) counts as
 * "the pairing" here. The callers make sure that never happens silently: the
 * site reads only usable families (ThemeSettings::all()), and a page theme
 * with an unusable family is refused as a whole (PageAppearance::fromTheme()).
 */
final class ThemeTypography
{
    /**
     * @return array{heading: string, body: string}
     */
    public static function stacks(string $pairingKey, ?int $headingFamilyId, ?int $bodyFamilyId): array
    {
        $pairing = ThemeFonts::pairing($pairingKey);

        return [
            'heading' => ($headingFamilyId !== null ? FontLibrary::stack($headingFamilyId) : null) ?? $pairing['heading'],
            'body' => ($bodyFamilyId !== null ? FontLibrary::stack($bodyFamilyId) : null) ?? $pairing['body'],
        ];
    }

    /** The pairing's stylesheet, or null when no role uses the pairing (or it needs none). */
    public static function pairingStylesheetUrl(string $pairingKey, ?int $headingFamilyId, ?int $bodyFamilyId): ?string
    {
        $headingOwn = $headingFamilyId !== null && FontLibrary::isUsable($headingFamilyId);
        $bodyOwn = $bodyFamilyId !== null && FontLibrary::isUsable($bodyFamilyId);

        if ($headingOwn && $bodyOwn) {
            return null;
        }

        return ThemeFonts::pairing($pairingKey)['url'];
    }

    /**
     * The library families these roles use, each once.
     *
     * @return list<int>
     */
    public static function familyIds(?int $headingFamilyId, ?int $bodyFamilyId): array
    {
        $ids = [];
        foreach ([$headingFamilyId, $bodyFamilyId] as $id) {
            if ($id !== null && FontLibrary::isUsable($id) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

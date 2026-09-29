<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * The one shape of an anchor a block can be jumped to by (Detailsectie 2.0):
 * what an editor types becomes a safe `id` and the `#…` a link points at.
 *
 *   "hout"          -> hout
 *   "#hout"         -> hout      (the # is the link's, not the name's)
 *   " Hout & Metaal" -> hout-metaal
 *   "Éénmalig"      -> eenmalig
 *
 * Lowercase a-z, 0-9, "-" and "_", no leading or trailing dash, at most 100
 * characters: a name that is valid as an `id`, needs no escaping in a URL
 * and reads the same in every language (an anchor is not a word; the label
 * the navigation shows is). Something that leaves nothing ("###", "!!!") is
 * no anchor, and the editor says so rather than storing it.
 *
 * Applied on save (api/admin/update-detail-section.php) AND on read
 * (App\Service\DetailSectionContent), so an anchor stored before this rule
 * ("#hout") renders as the anchor it was meant to be, with no data migration.
 */
final class AnchorName
{
    public const MAX_LENGTH = 100;

    public static function normalise(string $typed): string
    {
        $value = trim($typed);
        $value = ltrim($value, '#');

        $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : false;
        $value = strtolower((string) ($ascii !== false ? $ascii : $value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value) ?? '';
        $value = preg_replace('/-{2,}/', '-', $value) ?? '';
        $value = trim($value, '-');

        return substr($value, 0, self::MAX_LENGTH);
    }

    /** Whether something was typed that leaves no anchor. */
    public static function isUnusable(string $typed): bool
    {
        return trim($typed) !== '' && self::normalise($typed) === '';
    }
}

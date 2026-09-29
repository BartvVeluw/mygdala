<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * The text rules of the site search, in one place: how text is compared
 * (fold), how rich text becomes searchable plain text (plain), how a match
 * is scored (score) and how an excerpt is cut (excerpt). SEARCH.md
 * describes the ranking; SearchService applies it.
 *
 * FOLDING is lower case plus the accents of the Latin letters a Dutch,
 * English, German or French text uses, each to ONE letter (é → e, ü → u,
 * ç → c): "cafe" finds "Café", as the database's own case- and
 * accent-insensitive collation already does for the prefilter. One letter
 * for one letter keeps positions in the folded text equal to positions in
 * the original, which is what lets excerpt() cut the original around a
 * folded match. No intl extension is assumed (shared hosting).
 */
final class SearchText
{
    /** Exact title. */
    public const SCORE_EXACT = 400;

    /** The title starts with the query. */
    public const SCORE_PREFIX = 300;

    /** A word in the title starts with the query. */
    public const SCORE_WORD = 250;

    /** The title contains the query. */
    public const SCORE_TITLE = 200;

    /** Only the description, intro or excerpt contains it. */
    public const SCORE_TEXT = 100;

    public const EXCERPT_LENGTH = 160;

    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'ç' => 'c', 'č' => 'c', 'ć' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ñ' => 'n', 'ń' => 'n', 'ň' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u', 'ů' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'š' => 's', 'ś' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l', 'ř' => 'r',
    ];

    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
    }

    /**
     * Rich text (sanitized HTML from the editor) as one line of plain text:
     * tags out, entities decoded, white space collapsed. A block-level tag
     * becomes a space first, so "<p>a</p><p>b</p>" does not read "ab".
     */
    public static function plain(string $html): string
    {
        $spaced = (string) preg_replace('#<(?:/?(?:p|div|li|ul|ol|h[1-6]|br|tr|td|th|blockquote)\b)[^>]*>#i', ' ', $html);
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * How well a document matches: one of the SCORE_* levels, 0 for no
     * match. Higher is better; nothing else counts (no word frequency, no
     * AI) — the order is predictable from the words alone.
     */
    public static function score(string $foldedQuery, string $title, string $text): int
    {
        if ($foldedQuery === '') {
            return 0;
        }

        $foldedTitle = self::fold(trim($title));

        if ($foldedTitle === $foldedQuery) {
            return self::SCORE_EXACT;
        }

        if ($foldedTitle !== '' && str_starts_with($foldedTitle, $foldedQuery)) {
            return self::SCORE_PREFIX;
        }

        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($foldedQuery, '/') . '/u', $foldedTitle) === 1) {
            return self::SCORE_WORD;
        }

        if (str_contains($foldedTitle, $foldedQuery)) {
            return self::SCORE_TITLE;
        }

        if ($text !== '' && str_contains(self::fold($text), $foldedQuery)) {
            return self::SCORE_TEXT;
        }

        return 0;
    }

    /**
     * A short plain-text excerpt: around the first match when the text has
     * one, else its beginning. Plain text in, plain text out — the caller
     * escapes it like any other string.
     */
    public static function excerpt(string $text, string $foldedQuery, int $length = self::EXCERPT_LENGTH): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $total = mb_strlen($text);
        if ($total <= $length) {
            return $text;
        }

        $start = 0;
        $folded = self::fold($text);
        if ($foldedQuery !== '' && mb_strlen($folded) === $total) {
            $at = mb_strpos($folded, $foldedQuery);
            if ($at !== false) {
                $start = max(0, min($at - (int) floor($length / 3), $total - $length));
            }
        }

        $cut = mb_substr($text, $start, $length);

        // Never end or begin in the middle of a word when a space is near.
        if ($start + $length < $total) {
            $space = mb_strrpos($cut, ' ');
            if ($space !== false && $space > $length * 0.6) {
                $cut = mb_substr($cut, 0, $space);
            }
            $cut = rtrim($cut, " ,.;:") . '…';
        }
        if ($start > 0) {
            $space = mb_strpos($cut, ' ');
            if ($space !== false && $space < $length * 0.3) {
                $cut = mb_substr($cut, $space + 1);
            }
            $cut = '…' . $cut;
        }

        return $cut;
    }
}

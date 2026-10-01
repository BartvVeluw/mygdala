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

    /** Only a heading in the content (a block heading) contains it (Search 2.0). */
    public const SCORE_CONTENT_HEADING = 90;

    /** Only the content (block text, a classic post's body) contains it. */
    public const SCORE_CONTENT = 80;

    /**
     * Not the phrase, but every separate term somewhere (a query of more
     * words): from SCORE_TERMS up to SCORE_TERMS + SCORE_TERMS_TITLE, more
     * the more terms stand in the title. Always below SCORE_CONTENT, so the
     * whole phrase, anywhere, outranks words that are merely all present.
     */
    public const SCORE_TERMS = 10;

    public const SCORE_TERMS_TITLE = 60;

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
     *
     * The phrase levels come first. Only when the phrase is nowhere does the
     * term rule apply: every term in title or text, in any order, split over
     * both if need be (SearchQuery::terms()), scored below every phrase level
     * and higher the more terms stand in the title. One word is one term, so
     * a query of one word scores exactly as before.
     *
     * Search 2.0 adds the content below the owner's own words: the phrase
     * in a content heading (SCORE_CONTENT_HEADING), then in the content
     * (SCORE_CONTENT), both under SCORE_TEXT and above the term rule; the
     * term rule looks in the content too.
     *
     * @param list<string> $foldedTerms SearchQuery::foldedTerms()
     */
    public static function score(string $foldedQuery, string $title, string $text, array $foldedTerms = [], string $contentHeadings = '', string $content = ''): int
    {
        $phrase = self::phraseScore($foldedQuery, $title, $text);

        if ($phrase === 0 && $foldedQuery !== '') {
            if ($contentHeadings !== '' && str_contains(self::fold($contentHeadings), $foldedQuery)) {
                $phrase = self::SCORE_CONTENT_HEADING;
            } elseif ($content !== '' && str_contains(self::fold($content), $foldedQuery)) {
                $phrase = self::SCORE_CONTENT;
            }
        }
        // A query of one word IS its one term: the phrase levels alone, as
        // before. ("laser a" has one term that is not the phrase: it does
        // get the term rule.)
        if ($phrase > 0 || $foldedTerms === [] || $foldedTerms === [$foldedQuery]) {
            return $phrase;
        }

        $foldedTitle = self::fold($title);
        $all = $foldedTitle . ' ' . self::fold($text) . ' ' . self::fold($content);
        $inTitle = 0;
        foreach ($foldedTerms as $term) {
            if (!str_contains($all, $term)) {
                return 0;
            }
            if (str_contains($foldedTitle, $term)) {
                $inTitle++;
            }
        }

        return self::SCORE_TERMS + intdiv(self::SCORE_TERMS_TITLE * $inTitle, count($foldedTerms));
    }

    private static function phraseScore(string $foldedQuery, string $title, string $text): int
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
     * The excerpt of a result: from the first of $texts (the owner's own
     * text first, then its content) that has the phrase, else the first that
     * has a term, else the first that has anything. So a match found only
     * in a block shows the words around it there, not the start of the page.
     *
     * @param list<string> $texts
     * @param list<string> $foldedTerms
     */
    public static function bestExcerpt(array $texts, string $foldedQuery, int $length = self::EXCERPT_LENGTH, array $foldedTerms = []): string
    {
        $texts = array_values(array_filter($texts, static fn (string $text): bool => trim($text) !== ''));

        foreach ([[$foldedQuery], $foldedTerms] as $needles) {
            foreach ($texts as $text) {
                $folded = self::fold($text);
                foreach ($needles as $needle) {
                    if ($needle !== '' && str_contains($folded, $needle)) {
                        return self::excerpt($text, $foldedQuery, $length, $foldedTerms);
                    }
                }
            }
        }

        return $texts === [] ? '' : self::excerpt($texts[0], $foldedQuery, $length, $foldedTerms);
    }

    /**
     * A short plain-text excerpt: around the phrase when the text has it,
     * else around the first term it has, else its beginning. Plain text in,
     * plain text out — the caller escapes it like any other string.
     *
     * @param list<string> $foldedTerms
     */
    public static function excerpt(string $text, string $foldedQuery, int $length = self::EXCERPT_LENGTH, array $foldedTerms = []): string
    {
        $folded = self::fold($text);
        if ($foldedQuery === '' || !str_contains($folded, $foldedQuery)) {
            foreach ($foldedTerms as $term) {
                if ($term !== '' && str_contains($folded, $term)) {
                    $foldedQuery = $term;
                    break;
                }
            }
        }

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

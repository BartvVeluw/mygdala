<?php

declare(strict_types=1);

namespace App\Service\Search;

/**
 * What a visitor typed into the site search, normalised once on the server
 * (SEARCH.md, "De zoekvraag"). Every provider and every screen works with
 * this object, never with the raw `?q=`:
 *
 *   - not a string, or not valid UTF-8 → an empty query (nothing to search,
 *     nothing to echo back);
 *   - control and invisible format characters become spaces, runs of white
 *     space one space, and the ends are trimmed;
 *   - at most MAX_LENGTH characters (Unicode-aware); the rest is dropped;
 *   - fewer than MIN_LENGTH characters is "too short": searched for nothing.
 *
 * TERMS. Besides the whole query (the phrase, which ranks highest), a query
 * of more words is searched as its separate terms (terms()): every term must
 * occur somewhere in a result's searchable text, in any order, split over
 * title and text if need be (SearchText::score()). A term is a word of at
 * least MIN_TERM_LENGTH characters — a lone "a" or "&" would match nearly
 * everything — each folded term once, at most MAX_TERMS of them.
 *
 * LIKE wildcards are NOT special here: a `%` or `_` is a character like any
 * other, escaped by the repository that puts it in a LIKE
 * (App\Repository\EntityTranslationRepository::ownersMatching()).
 */
final class SearchQuery
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

    /** The most separate terms one query is searched as; the rest is ignored. */
    public const MAX_TERMS = 8;

    /** Shorter words still count in the phrase, but are no term of their own. */
    public const MIN_TERM_LENGTH = 2;

    private function __construct(public readonly string $text)
    {
    }

    public static function fromInput(mixed $raw): self
    {
        if (!is_string($raw) || $raw === '' || !mb_check_encoding($raw, 'UTF-8')) {
            return new self('');
        }

        // Read at most a few times the limit before any regex runs on it.
        $raw = mb_substr($raw, 0, self::MAX_LENGTH * 4);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $raw);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return new self(trim(mb_substr($text, 0, self::MAX_LENGTH)));
    }

    public function isEmpty(): bool
    {
        return $this->text === '';
    }

    public function isTooShort(): bool
    {
        return $this->text !== '' && mb_strlen($this->text) < self::MIN_LENGTH;
    }

    public function isSearchable(): bool
    {
        return mb_strlen($this->text) >= self::MIN_LENGTH;
    }

    /** Lower case, common accents folded (SearchText::fold()). */
    public function folded(): string
    {
        return SearchText::fold($this->text);
    }

    /**
     * The separate terms, as typed (for a LIKE, whose collation is case- and
     * accent-insensitive itself): split on white space, each folded form
     * once, at least MIN_TERM_LENGTH characters, at most MAX_TERMS. One word
     * gives one term, the query itself.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        $terms = [];
        foreach (explode(' ', $this->text) as $word) {
            $folded = SearchText::fold($word);
            if (mb_strlen($word) < self::MIN_TERM_LENGTH || isset($terms[$folded])) {
                continue;
            }
            $terms[$folded] = $word;
            if (count($terms) === self::MAX_TERMS) {
                break;
            }
        }

        return array_values($terms);
    }

    /**
     * terms(), folded: what SearchText compares with.
     *
     * @return list<string>
     */
    public function foldedTerms(): array
    {
        return array_map([SearchText::class, 'fold'], $this->terms());
    }

    /**
     * What a prefilter looks for: the terms, or the phrase itself when it
     * has none (a query of short words only, "a b"). The rule
     * SearchCandidates::ids() and the block text index share.
     *
     * @return list<string>
     */
    public function needles(): array
    {
        $terms = $this->terms();

        return $terms === [] ? [$this->text] : $terms;
    }
}

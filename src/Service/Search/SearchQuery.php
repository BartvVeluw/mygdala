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
 * LIKE wildcards are NOT special here: a `%` or `_` is a character like any
 * other, escaped by the repository that puts it in a LIKE
 * (App\Repository\EntityTranslationRepository::ownersMatching()).
 */
final class SearchQuery
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 100;

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
}

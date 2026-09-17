<?php

declare(strict_types=1);

namespace App\Service\Forms;

use App\Service\Language\LanguageFallback;
use App\Service\Language\LanguageRegistry;
use App\Service\Language\LocalizedValue;

/**
 * One piece of editor- or application-supplied text in the two languages the
 * public V1 language switch still offers.
 *
 * TWO SOURCES, one shape. The fixed sentences of Core Forms itself (an error,
 * the generic submit label) are written here as a Dutch/English pair with
 * of(), where an empty English value means "the same as Dutch". The words an
 * EDITOR stores for a form, a field or an option are stored per website
 * language since Multilingual 2.0 phase 4 (App\Service\Forms\FormLocalization)
 * and arrive through fromWords(), each half already resolved by THE fallback
 * (App\Service\Language\LanguageFallback: the asked-for language, the
 * default language, ''), with no second fallback of this class on top.
 *
 * Templates print `nl` as the visible text and put both in `data-nl` /
 * `data-en`; assets/js/core.js swaps them when the visitor picks a language.
 * Nothing is translated server-side.
 */
final class FormText
{
    private function __construct(
        public readonly string $nl,
        public readonly string $en,
    ) {
    }

    /**
     * A fixed Dutch/English pair of this code: an empty (or whitespace-only)
     * English value falls back to the Dutch one.
     */
    public static function of(?string $nl, ?string $en = null): self
    {
        $dutch = trim((string) $nl);
        $english = trim((string) $en);

        return new self($dutch, $english !== '' ? $english : $dutch);
    }

    /**
     * An editor's words for one field, stored per website language: the V1
     * pair of LanguageFallback::bilingual(), taken as it is.
     *
     * @param array<string, array<string, string>> $translations language code => field => words
     */
    public static function fromWords(array $translations, string $field): self
    {
        $column = [];
        foreach ($translations as $code => $fields) {
            $words = trim((string) ($fields[$field] ?? ''));
            if ($words !== '') {
                $column[(string) $code] = $words;
            }
        }

        return self::fromLocalized(LanguageFallback::bilingual($column));
    }

    /** The two halves of a LocalizedValue whose fallback has already been applied. */
    public static function fromLocalized(LocalizedValue $value): self
    {
        return new self($value->in(LanguageRegistry::DUTCH), $value->in(LanguageRegistry::ENGLISH));
    }

    public function isEmpty(): bool
    {
        return $this->nl === '' && $this->en === '';
    }

    /** The text in one language; anything that is not 'en' means Dutch. */
    public function in(string $language): string
    {
        return $language === 'en' ? $this->en : $this->nl;
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * One piece of editor-supplied text in every language the site publishes.
 *
 * THE fallback rule of this CMS used to be written as "Dutch is the content,
 * English is optional, empty English means the same as Dutch", and it was
 * applied inline in roughly 280 places across src/. That was fine while
 * Dutch was the only possible primary language. It is not fine now that a
 * site may be English-primary, because then the fallback runs the other way.
 *
 * So the rule is stated once, here, in terms of the PRIMARY language rather
 * than of Dutch:
 *
 *     asked-for language -> its own value
 *     empty              -> the primary language's value
 *
 * A missing translation therefore renders the primary language's words, never
 * a blank page. That is the behaviour visitors already had; only the name of
 * the language it falls back to can now differ.
 *
 * App\Service\Forms\FormText is the same idea, one domain earlier, and stays
 * as it is: it is a fixed NL/EN pair inside Core Forms and converting it
 * would be a Forms refactor rather than a multilingual one. This class is
 * where new code goes.
 */
final class LocalizedValue
{
    /**
     * @param array<string, string> $values resolved value per language code,
     *        every enabled language present and the fallback already applied
     */
    private function __construct(
        private readonly array $values,
        private readonly string $primary,
        /** @var array<string, string> the values BEFORE the fallback ran */
        private readonly array $raw,
    ) {
    }

    /**
     * Build from whatever a repository row holds.
     *
     * $values is keyed by language code — ['nl' => ..., 'en' => ...] — and may
     * be partial, hold nulls, or carry a language this build does not know.
     * Anything unusable is dropped; the primary language always ends up
     * present, empty string at worst.
     *
     * @param array<string, string|null> $values
     */
    public static function of(array $values, ?string $primary = null): self
    {
        $primaryCode = $primary ?? ContentLanguages::primary();
        if (!LanguageRegistry::has($primaryCode)) {
            $primaryCode = LanguageRegistry::DEFAULT_LANGUAGE;
        }

        // Which codes may be carried at all: the closed V1 registry plus the
        // languages this site actually publishes. Identical lists on a Dutch
        // and English site, which is every installation today; on a site with
        // a third language it is what lets that language's words survive as
        // far as the page (App\Service\Language\LanguageFallback).
        $renderable = LanguageFallback::renderableLanguages();

        $raw = [];
        foreach ($values as $code => $value) {
            if (is_string($code) && in_array($code, $renderable, true)) {
                $raw[$code] = trim((string) $value);
            }
        }

        $raw[$primaryCode] ??= '';

        $primaryValue = $raw[$primaryCode];

        $resolved = [];
        foreach ($renderable as $code) {
            if (!array_key_exists($code, $raw)) {
                continue;
            }

            $resolved[$code] = $raw[$code] !== '' ? $raw[$code] : $primaryValue;
        }

        return new self($resolved, $primaryCode, $raw);
    }

    /**
     * Convenience for the shape almost every existing table has: a Dutch
     * column and an English one. Which of the two is the primary language is
     * NOT decided here — it comes from the site's settings, so the exact same
     * row reads differently on a Dutch site and on an English one, which is
     * the whole point of Part F.
     */
    public static function ofDutchEnglish(?string $nl, ?string $en, ?string $primary = null): self
    {
        return self::of([
            LanguageRegistry::DUTCH => $nl,
            LanguageRegistry::ENGLISH => $en,
        ], $primary);
    }

    /**
     * The text to SHOW in $code: its own value, or the primary language's
     * words when this language has none. An unknown code gets the primary
     * language rather than an empty string.
     */
    public function in(string $code): string
    {
        return $this->values[$code] ?? $this->primaryValue();
    }

    /** The primary language's own text — what a visitor sees by default. */
    public function primaryValue(): string
    {
        return $this->values[$this->primary] ?? '';
    }

    public function primaryLanguage(): string
    {
        return $this->primary;
    }

    /**
     * The value EXACTLY as stored, with no fallback applied.
     *
     * This is what an editor form must show: a translation field that
     * silently displays the primary language's words would be saved back as a
     * real translation the moment somebody pressed Save, and the site would
     * lose the distinction between "translated" and "not translated yet".
     */
    public function raw(string $code): string
    {
        return $this->raw[$code] ?? '';
    }

    /** Has this language its own words, rather than the primary's? */
    public function isTranslated(string $code): bool
    {
        return $code === $this->primary || ($this->raw[$code] ?? '') !== '';
    }

    /** Nothing to show in any language. */
    public function isEmpty(): bool
    {
        foreach ($this->values as $value) {
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The `data-nl` / `data-en` pair a public partial prints, ready to be
     * escaped by the caller.
     *
     * The attribute NAMES are still the language codes, unchanged — that is
     * the contract assets/js/core.js has always read and this step does not
     * touch it (MULTILINGUAL.md, "URLs and hreflang are deferred"). What
     * changed is which of them holds the visible text: the primary one.
     *
     * @return array<string, string> code => text, every registered language
     */
    public function attributeValues(): array
    {
        $attributes = [];
        foreach (LanguageRegistry::codes() as $code) {
            $attributes[$code] = $this->in($code);
        }

        return $attributes;
    }
}

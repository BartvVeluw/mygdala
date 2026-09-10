<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * What came back from one translate action.
 *
 * Carries the skipped fields as loudly as the translated ones. An editor who
 * presses "translate missing fields" and sees three of five change needs to
 * know that the other two were left alone on purpose — one was empty, one
 * they had written themselves — rather than concluding the feature is
 * unreliable.
 */
final class TranslationResult
{
    /** A field was skipped because there was nothing to translate. */
    public const SKIPPED_EMPTY = 'empty';

    /** A field was skipped because a person wrote the existing translation. */
    public const SKIPPED_MANUAL = 'manual';

    public function __construct(
        /** @var array<string, string> field => translated text */
        public readonly array $translations,
        /** @var array<string, string> field => one of the SKIPPED_* reasons */
        public readonly array $skipped,
        public readonly string $provider,
        public readonly string $language,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->translations === [];
    }

    /** @return string[] the fields a provider actually wrote */
    public function translatedFields(): array
    {
        return array_keys($this->translations);
    }

    /** @return string[] fields left alone because a person had written them */
    public function protectedFields(): array
    {
        return array_keys(array_filter(
            $this->skipped,
            static fn (string $reason): bool => $reason === self::SKIPPED_MANUAL,
        ));
    }

    /**
     * The shape the admin JavaScript reads.
     *
     * Deliberately explicit rather than a cast of the whole object: what
     * crosses into the browser is a decision, not a side effect of adding a
     * property here later.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'translations' => $this->translations,
            'skipped' => $this->skipped,
            'language' => $this->language,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * One language this CMS knows about.
 *
 * Deliberately a value object with public readonly fields and no behaviour:
 * everything that decides something about languages lives in
 * App\Service\Language\LanguageRegistry (which languages exist),
 * App\Service\Language\ContentLanguages (which the website uses) or
 * App\Service\Language\AdminLocale (which the CMS interface uses). A
 * definition only describes.
 *
 * See MULTILINGUAL.md.
 */
final class LanguageDefinition
{
    public function __construct(
        /** ISO 639-1 code, lowercase. Also the column suffix: title_nl / title_en. */
        public readonly string $code,
        /** The language's name in its own language — what a speaker recognises. */
        public readonly string $nativeLabel,
        /** The language's name in Dutch, for a Dutch CMS interface. */
        public readonly string $dutchLabel,
        /** The language's name in English, for an English CMS interface. */
        public readonly string $englishLabel,
        /** DeepL source language code, or null when DeepL cannot read it. */
        public readonly ?string $deeplSource,
        /** DeepL target language code, or null when DeepL cannot write it. */
        public readonly ?string $deeplTarget,
        /** Is there a curated CMS interface translation for this language? */
        public readonly bool $availableAsAdminLocale,
        /** May a site store website content in this language? */
        public readonly bool $availableAsContentLanguage,
    ) {
    }

    /** The label to show inside a CMS interface running in $locale. */
    public function labelIn(string $locale): string
    {
        return $locale === 'en' ? $this->englishLabel : $this->dutchLabel;
    }

    /** Can this language be the target of an automatic translation? */
    public function isMachineTranslatable(): bool
    {
        return $this->deeplSource !== null && $this->deeplTarget !== null;
    }
}

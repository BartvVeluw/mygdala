<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * One field an editor has asked to have translated.
 *
 * A value object rather than a loose array, because the two booleans on it
 * decide security-relevant behaviour and a typo'd array key would silently
 * pick the wrong default:
 *
 *   $isHtml   send this to the provider as markup, and sanitise what comes
 *             back — the difference between a preserved link and a mangled
 *             one, and between trusting a third party's HTML and not.
 *   $force    the editor has confirmed they want to replace a translation
 *             a person wrote. False is the safe default and every caller
 *             gets it unless the request explicitly said otherwise.
 *
 * $field is the base column name without its language suffix ('title' for
 * title_nl/title_en). It is used to look up translation state and to name a
 * field in a response, and it is validated against the field list the
 * SERVER built for that entity — never used to reach a column directly.
 */
final class TranslationRequest
{
    public function __construct(
        public readonly string $field,
        public readonly string $sourceText,
        public readonly string $existingTranslation = '',
        public readonly bool $isHtml = false,
        public readonly bool $force = false,
    ) {
    }

    /** Nothing to send: an empty source cannot be translated into anything. */
    public function isEmpty(): bool
    {
        return trim($this->sourceText) === '';
    }
}

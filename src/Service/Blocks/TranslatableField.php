<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\RichTextSanitizer;

/**
 * One field of a content block whose words are stored per website language
 * (Multilingual 2.0, docs/multilingual/ARCHITECTURE.md): its key, whether it
 * is plain text or rich text, how long it may be and whether the default
 * language must have it.
 *
 * A block declares its fields in BlockDefinition::translatableFields(), and
 * that declaration is the ONLY place this is written down: the store
 * (App\Service\Blocks\BlockLocalization) refuses a key a block does not
 * declare, the validator reads the length and the required flag from here,
 * and the kind decides whether a value goes through RichTextSanitizer. No
 * endpoint decides that by the name of its screen any more.
 *
 * Deliberately small. There is no label, no widget and no help text: an
 * editor screen stays a hand-written form, and this describes what is stored,
 * not how it is typed.
 */
final class TranslatableField
{
    public const PLAIN = 'plain';
    public const RICH = 'rich';

    /** A problem validate() reports: the default language has no words. */
    public const MISSING = 'missing';

    /** A problem validate() reports: more characters than maxLength. */
    public const TOO_LONG = 'too_long';

    /** A field key: lowercase words joined by underscores, never a language suffix. */
    private const KEY_SHAPE = '/\A[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

    private function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly int $maxLength,
        public readonly bool $required,
    ) {
        if (preg_match(self::KEY_SHAPE, $key) !== 1 || strlen($key) > 64 || preg_match('/_[a-z]{2}\z/', $key) === 1) {
            throw new \InvalidArgumentException('"' . $key . '" is not a translatable field key.');
        }

        if ($maxLength < 1) {
            throw new \InvalidArgumentException('A translatable field needs a positive maximum length.');
        }
    }

    /** Plain text: trimmed, stored as typed, escaped wherever it is printed. */
    public static function plain(string $key, int $maxLength): self
    {
        return new self($key, self::PLAIN, $maxLength, false);
    }

    /**
     * Rich text: stored and read only as RichTextSanitizer output. The length
     * is counted on what the editor sent, before sanitizing, as the rich-text
     * endpoints always did.
     */
    public static function rich(string $key, int $maxLength): self
    {
        return new self($key, self::RICH, $maxLength, false);
    }

    /**
     * Required in the website's DEFAULT language only. A translation is
     * optional by definition: an empty one falls back to the default
     * language's words.
     */
    public function required(): self
    {
        return new self($this->key, $this->kind, $this->maxLength, true);
    }

    public function isRich(): bool
    {
        return $this->kind === self::RICH;
    }

    /**
     * What is stored for a submitted value: trimmed plain text, or sanitized
     * HTML. '' means "no words", and no row is stored for it.
     */
    public function normalise(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        return $this->isRich() ? (string) RichTextSanitizer::sanitize($value) : $value;
    }

    /**
     * What is wrong with a submitted value in one language, or null.
     * MISSING only in the default language; TOO_LONG in every language.
     */
    public function problem(?string $value, bool $inDefaultLanguage): ?string
    {
        $value = trim((string) $value);

        if (mb_strlen($value) > $this->maxLength) {
            return self::TOO_LONG;
        }

        if ($this->required && $inDefaultLanguage && $this->normalise($value) === '') {
            return self::MISSING;
        }

        return null;
    }
}

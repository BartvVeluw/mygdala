<?php

declare(strict_types=1);

namespace App\Service\Forms;

/**
 * One piece of editor- or application-supplied text in both site languages.
 *
 * The whole CMS follows the same rule (PROJECT-MAP.md): Dutch is the
 * content, English is optional, and an empty English value means "the same
 * as Dutch". That fallback is applied HERE, once, when the text is built —
 * so no template, no validator and no e-mail builder has to remember it, and
 * a stored submission can never end up with an empty English label.
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
     * The one constructor: an empty (or whitespace-only) English value falls
     * back to the Dutch one.
     */
    public static function of(?string $nl, ?string $en = null): self
    {
        $dutch = trim((string) $nl);
        $english = trim((string) $en);

        return new self($dutch, $english !== '' ? $english : $dutch);
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

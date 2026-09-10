<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * Something that can turn text in one language into text in another.
 *
 * THE POINT OF THIS INTERFACE is that no page editor, no block editor and no
 * form editor ever mentions DeepL. They call App\Service\Translation\
 * TranslationService, which calls whatever provider this deployment has
 * configured. Swapping DeepL for another API — or for an LLM — is a new class
 * plus one line in TranslationProviderFactory, and not a single editor
 * changes.
 *
 * Deliberately narrow. There is no batching method, no glossary, no
 * formality setting and no usage-quota call: every one of those is a DeepL
 * feature rather than a translation concept, and putting it here would make
 * the interface a description of one vendor. A provider that wants to batch
 * internally is free to; ::translateAll() exists so it can.
 *
 * Implementations MUST be safe to construct without credentials and MUST NOT
 * perform any network call until translate() is actually invoked — the CMS
 * builds this object on screens that may never translate anything.
 */
interface TranslationProvider
{
    /** A stable key for this provider, stored alongside a translation. */
    public function key(): string;

    /**
     * Is this provider ready to be used? False when it has no credentials,
     * which is the normal state of a fresh installation: automatic
     * translation is optional and the CMS works fully without it.
     */
    public function isConfigured(): bool;

    /**
     * Can this provider translate between these two languages?
     *
     * $source and $target are language codes from
     * App\Service\Language\LanguageRegistry, never raw request input.
     */
    public function supports(string $source, string $target): bool;

    /**
     * Translate one piece of text.
     *
     * $html says whether $text is a fragment of markup. A provider that can
     * handle markup must preserve tags, attributes and links and translate
     * only the text between them — never strip the markup, translate the
     * plain text and rebuild it, which loses every link and every formatting
     * choice an editor made.
     *
     * @throws TranslationException when no translation could be produced
     */
    public function translate(string $text, string $source, string $target, bool $html = false): string;

    /**
     * Translate several pieces at once, keys preserved.
     *
     * The default implementation a provider may fall back to is a loop; a
     * provider whose API accepts several strings per call should override it,
     * because that is the difference between one HTTP round trip per field
     * and one per section.
     *
     * @param array<string, string> $texts
     * @return array<string, string> the same keys, translated
     * @throws TranslationException
     */
    public function translateAll(array $texts, string $source, string $target, bool $html = false): array;
}

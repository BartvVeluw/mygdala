<?php

declare(strict_types=1);

namespace App\Service\Translation;

/**
 * The provider a deployment has when it has configured none.
 *
 * This class is why automatic translation is genuinely optional. A fresh
 * Mygdala installation boots with no translation credentials, every editor
 * works, manual translations save normally, and the only difference is that
 * the "Translate" buttons are not offered. There is no branch anywhere else
 * in the CMS that asks "is translation configured"; callers ask this object,
 * and it answers no.
 *
 * It refuses rather than returning the source text unchanged. Silently
 * copying Dutch into the English field would look like a successful
 * translation and would poison the translation state with a hash saying the
 * English text is up to date.
 */
final class NullTranslationProvider implements TranslationProvider
{
    public function key(): string
    {
        return 'none';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function supports(string $source, string $target): bool
    {
        return false;
    }

    public function translate(string $text, string $source, string $target, bool $html = false): string
    {
        throw new TranslationException('No translation provider is configured on this installation.');
    }

    public function translateAll(array $texts, string $source, string $target, bool $html = false): array
    {
        throw new TranslationException('No translation provider is configured on this installation.');
    }
}

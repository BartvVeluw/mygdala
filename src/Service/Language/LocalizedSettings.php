<?php

declare(strict_types=1);

namespace App\Service\Language;

use App\Repository\SiteSettingTranslationRepository;

/**
 * The words of ONE closed settings catalogue, in every website language
 * (Multilingual 2.0 phase 5, docs/multilingual/ARCHITECTURE.md): the
 * key/value half of what App\Service\Language\EntityTranslations is for the
 * typed translation tables.
 *
 * IT KNOWS NO DOMAIN. A domain holds one instance, hands it the keys it owns
 * and gives it a typed face: App\Service\LocalizedSiteSettings for Core's own
 * website text, App\Service\Blog\BlogLocalizedSettings for the Blog's title
 * and introduction. Nothing else reads or writes those rows.
 *
 * ONE PHYSICAL TABLE, `site_setting_translations`, and as many catalogues as
 * there are domains that need one. The table is a row per key per language
 * and nothing more — which keys exist is the catalogue's business. A
 * catalogue only ever sees its own keys, so Core never learns that a blog
 * exists (MODULES.md) and the Blog never learns what Core keeps beside it.
 *
 * THE CATALOGUE IS CLOSED. Every read and every write asserts the key, so no
 * request, row or module can invent a localized setting, and a key that
 * belongs to another catalogue is not this one's to touch.
 *
 * THE FALLBACK is App\Service\Language\LanguageFallback's: the asked-for
 * language, the default language, ''. value() is that rule for a visitor,
 * raw() the stored words with no fallback for an editor. There is no second
 * fallback here and none in a domain on top of this.
 *
 * READS NEVER THROW: a lookup that fails is logged and reads as "no words",
 * so a public request degrades rather than dies. Writes do throw, because an
 * editor must hear that a save did not happen. One query per catalogue per
 * request.
 */
final class LocalizedSettings
{
    /** @var array<string, array<string, string>>|null key => language code => words (non-empty only) */
    private ?array $cache = null;

    /** @param array<string, int> $catalogue key => maximum length in characters */
    public function __construct(private readonly array $catalogue)
    {
    }

    /** @return array<string, int> the whole catalogue: key => maximum length */
    public function catalogue(): array
    {
        return $this->catalogue;
    }

    public function has(string $key): bool
    {
        return isset($this->catalogue[$key]);
    }

    /**
     * Every language one key has words in.
     *
     * @return array<string, string> language code => words
     */
    public function words(string $key): array
    {
        $this->assertKey($key);

        return $this->all()[$key] ?? [];
    }

    /** The stored words in one language, no fallback: what an editor sees. */
    public function raw(string $key, string $languageCode): string
    {
        return $this->words($key)[$languageCode] ?? '';
    }

    /** The words a visitor gets in one language, with the fallback. */
    public function value(string $key, string $languageCode): string
    {
        return LanguageFallback::resolve($this->words($key), $languageCode);
    }

    /** Has the key words in the website's default language? */
    public function hasDefault(string $key): bool
    {
        return $this->raw($key, LanguageFallback::defaultLanguage()) !== '';
    }

    /** The temporary V1 `data-nl`/`data-en` pair of one key. Plain text. */
    public function bilingual(string $key): LocalizedValue
    {
        return LanguageFallback::bilingual($this->words($key));
    }

    /**
     * Which of the given values are too long, by key. No localized setting is
     * required: each is optional in every language, and an empty one either
     * falls back or is simply left out.
     *
     * @param array<string, string|null> $values
     * @return array<string, string> key => 'too_long'
     */
    public function problems(array $values): array
    {
        $problems = [];
        foreach ($values as $key => $value) {
            $this->assertKey((string) $key);

            if (mb_strlen(trim((string) $value)) > $this->catalogue[$key]) {
                $problems[(string) $key] = 'too_long';
            }
        }

        return $problems;
    }

    /**
     * Store one language's words for the keys given. Every other language,
     * and every key not given, stays as it is. An empty value removes that
     * language's row: "not translated" and "translated as nothing" are one
     * state.
     *
     * Takes part in a transaction already open on the shared connection.
     *
     * @param array<string, string|null> $values key => words
     *
     * @throws \InvalidArgumentException for a key outside the catalogue, an unregistered language or a value over its length
     */
    public function save(string $languageCode, array $values): void
    {
        $code = LanguageCode::normalise($languageCode);

        if ($code === null || !SiteLanguages::exists($code)) {
            throw new \InvalidArgumentException('A localized setting can only be stored in a registered website language.');
        }

        foreach ($values as $key => $value) {
            $this->assertKey((string) $key);

            if (mb_strlen(trim((string) $value)) > $this->catalogue[$key]) {
                throw new \InvalidArgumentException('The setting "' . $key . '" is longer than ' . $this->catalogue[$key] . ' characters.');
            }
        }

        $repository = new SiteSettingTranslationRepository();
        foreach ($values as $key => $value) {
            $words = trim((string) $value);

            if ($words === '') {
                $repository->delete((string) $key, $code);
            } else {
                $repository->save((string) $key, $code, $words);
            }
        }

        $this->cache = null;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Test seam: pretend the stored words are exactly $words, without a
     * database. null goes back to reading the database.
     *
     * @param array<string, array<string, string>>|null $words key => language code => words
     */
    public function overrideForTests(?array $words): void
    {
        $this->cache = $words;
    }

    /** @return array<string, array<string, string>> */
    private function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $rows = (new SiteSettingTranslationRepository())->findByKeys(array_keys($this->catalogue));
        } catch (\Throwable $e) {
            error_log('[LocalizedSettings] the localized settings could not be read: ' . $e->getMessage());
            $rows = [];
        }

        $words = [];
        foreach ($rows as $row) {
            $key = (string) $row['setting_key'];
            $value = trim((string) $row['value']);

            // A key the catalogue no longer names is left alone in the
            // database and ignored here, like App\Service\SiteSettings::all()
            // ignores a row it does not list.
            if ($value !== '' && isset($this->catalogue[$key])) {
                $words[$key][(string) $row['language_code']] = $value;
            }
        }

        return $this->cache = $words;
    }

    private function assertKey(string $key): void
    {
        if (!isset($this->catalogue[$key])) {
            throw new \InvalidArgumentException('"' . $key . '" is not a localized setting of this catalogue.');
        }
    }
}

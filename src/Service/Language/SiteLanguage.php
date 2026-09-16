<?php

declare(strict_types=1);

namespace App\Service\Language;

/**
 * One row of the website language registry (`site_languages`).
 *
 * A value object with public readonly fields and no behaviour, like
 * App\Service\Language\LanguageDefinition: what the site does with its
 * languages is decided by App\Service\Language\SiteLanguages.
 *
 * The code is the identity. Content that is stored per language later refers
 * to the code, never to the row id, so the id is left out on purpose.
 */
final class SiteLanguage
{
    public function __construct(
        /** Lowercase language code, see App\Service\Language\LanguageCode. */
        public readonly string $code,
        /** The language's English name. */
        public readonly string $name,
        /** The language's name in the language itself. */
        public readonly string $nativeName,
        public readonly bool $isDefault,
        public readonly bool $isActive,
        public readonly int $sortOrder,
    ) {
    }

    /**
     * Build from a `site_languages` row, or null when the row holds a code
     * that is not valid. Such a row was not written by this application and
     * is skipped rather than trusted.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $code = LanguageCode::normalise((string) ($row['code'] ?? ''));
        if ($code === null || $code !== ($row['code'] ?? null)) {
            return null;
        }

        return new self(
            code: $code,
            name: (string) ($row['name'] ?? ''),
            nativeName: (string) ($row['native_name'] ?? ''),
            isDefault: (int) ($row['is_default'] ?? 0) === 1,
            isActive: (int) ($row['is_active'] ?? 0) === 1,
            sortOrder: (int) ($row['sort_order'] ?? 0),
        );
    }
}

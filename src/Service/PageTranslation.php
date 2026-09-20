<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\LanguageCode;

/**
 * One page's text in one website language: a `page_translations` row.
 *
 * A value object with public readonly fields, like
 * App\Service\Language\SiteLanguage. Which language a visitor or an editor
 * gets, and what happens when a field is empty, is decided by
 * App\Service\PageLocalization and nowhere else.
 *
 * NULL is "no words for this field in this language", never the empty
 * string: the fallback has to be able to tell the two apart, and an editor
 * form must show a missing translation as an empty field.
 */
final class PageTranslation
{
    public const SLUG = 'slug';
    public const TITLE = 'title';
    public const META_TITLE = 'meta_title';
    public const META_DESCRIPTION = 'meta_description';

    /**
     * Every localized field a page has. A closed list: a field name reaches
     * SQL only as one of these keys, never from a request.
     *
     * @var list<string>
     */
    public const FIELDS = [self::TITLE, self::META_TITLE, self::META_DESCRIPTION];

    public function __construct(
        public readonly int $pageId,
        /** Lowercase language code, see App\Service\Language\LanguageCode. */
        public readonly string $languageCode,
        public readonly ?string $title,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        /**
         * This language's public address, or NULL when this language has no
         * public route at all (Multilingual 2.0 phase 6,
         * docs/multilingual/ROUTING.md).
         *
         * DELIBERATELY NOT ONE OF ::FIELDS. Those are words, and words fall
         * back to the default language when a translation is missing. A slug
         * does not and must not: a URL that falls back would publish one
         * language's content under another language's address. NULL here is
         * "there is no version of this page in this language", full stop —
         * see App\Service\PageLocalization::slug(), which has no fallback
         * where every other reader there has one.
         */
        public readonly ?string $slug = null,
    ) {
    }

    /**
     * Build from a `page_translations` row, or null when the row names a
     * language code this application would never have written.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): ?self
    {
        $code = LanguageCode::normalise((string) ($row['language_code'] ?? ''));
        if ($code === null || $code !== ($row['language_code'] ?? null)) {
            return null;
        }

        return new self(
            pageId: (int) ($row['page_id'] ?? 0),
            languageCode: $code,
            title: self::textOrNull($row[self::TITLE] ?? null),
            metaTitle: self::textOrNull($row[self::META_TITLE] ?? null),
            metaDescription: self::textOrNull($row[self::META_DESCRIPTION] ?? null),
            slug: self::textOrNull($row[self::SLUG] ?? null),
        );
    }

    /**
     * The field's words, or '' when this language has none.
     *
     * @throws \InvalidArgumentException for a name that is not in ::FIELDS
     */
    public function value(string $field): string
    {
        return match ($field) {
            self::TITLE => (string) $this->title,
            self::META_TITLE => (string) $this->metaTitle,
            self::META_DESCRIPTION => (string) $this->metaDescription,
            default => throw new \InvalidArgumentException('A page has no localized field "' . $field . '".'),
        };
    }

    /**
     * Nothing at all in this language: no words AND no address, so the row
     * carries nothing and is not stored.
     *
     * The slug counts. A language that has only an address is still a
     * language this page is routable in, and deleting that row would take a
     * live URL away as a side effect of an empty title.
     */
    public function isEmpty(): bool
    {
        return $this->title === null
            && $this->metaTitle === null
            && $this->metaDescription === null
            && $this->slug === null;
    }

    /**
     * What a stored text column holds: the words, trimmed, or NULL when there
     * are none. Shared by the reader and App\Service\PageLocalization::save(),
     * so a value reads back exactly as it was written.
     */
    public static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}

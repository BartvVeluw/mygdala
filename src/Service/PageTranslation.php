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

    /** No words in any field: a row like this is not stored at all. */
    public function isEmpty(): bool
    {
        return $this->title === null && $this->metaTitle === null && $this->metaDescription === null;
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

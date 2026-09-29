<?php

declare(strict_types=1);

namespace App\Update;

/**
 * One published release as the Updates screen's history shows it: a
 * version, the day it was published, a title and its notes (Markdown, as
 * written on the release page). Nothing else — no package URL, no checksum,
 * no signature: a ReleaseNote is only ever READ by a person, never used to
 * install anything (ReleaseHistory's docblock says why).
 */
final class ReleaseNote
{
    /** Longer notes are cut, as ReleaseManifest cuts its own. */
    public const MAX_NOTES_LENGTH = 20000;

    public const MAX_TITLE_LENGTH = 200;

    public function __construct(
        public readonly string $version,
        public readonly string $publishedAt,
        public readonly string $title,
        public readonly string $notes
    ) {
    }

    public function semver(): SemVer
    {
        return SemVer::parse($this->version);
    }

    /** @return array{version: string, published_at: string, title: string, notes: string} */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'published_at' => $this->publishedAt,
            'title' => $this->title,
            'notes' => $this->notes,
        ];
    }

    /**
     * Back from the cache file. Anything that does not have the shape
     * toArray() writes is null, never an exception: a damaged cache is
     * "no history", not a broken Updates screen.
     *
     * @param mixed $data
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data)) {
            return null;
        }

        $version = $data['version'] ?? null;
        $publishedAt = $data['published_at'] ?? null;
        $title = $data['title'] ?? '';
        $notes = $data['notes'] ?? '';

        if (!is_string($version) || !SemVer::isValid($version) || !is_string($publishedAt) || !is_string($title) || !is_string($notes)) {
            return null;
        }

        return new self(
            $version,
            self::date($publishedAt),
            mb_substr($title, 0, self::MAX_TITLE_LENGTH),
            mb_substr($notes, 0, self::MAX_NOTES_LENGTH)
        );
    }

    /** '' for anything that is not a date strtotime() reads. */
    public static function date(string $value): string
    {
        return $value !== '' && strtotime($value) !== false ? $value : '';
    }
}

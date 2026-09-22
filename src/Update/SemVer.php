<?php

declare(strict_types=1);

namespace App\Update;

/**
 * A Mygdala release version: MAJOR.MINOR.PATCH, and nothing else.
 *
 * Deliberately stricter than semver.org. No pre-release suffix and no build
 * metadata: V1 has no beta channel (docs/updates/RELEASES.md), and a version
 * string that can only mean one thing is one that two installations can
 * never disagree about when they compare it. The build identity of a release
 * lives next to the version, in release.json's `build_id`, where it is
 * informative and never ordered.
 *
 * Release versions and migration versions are independent: 0.2.0 may carry
 * no migration at all, or five. See AppVersion.
 */
final class SemVer
{
    private const PATTERN = '/^(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})\.(0|[1-9]\d{0,5})$/';

    private function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch
    ) {
    }

    public static function parse(string $version): self
    {
        $parsed = self::tryParse($version);

        if ($parsed === null) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a MAJOR.MINOR.PATCH version', $version));
        }

        return $parsed;
    }

    public static function tryParse(string $version): ?self
    {
        if (preg_match(self::PATTERN, $version, $match) !== 1) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2], (int) $match[3]);
    }

    public static function isValid(string $version): bool
    {
        return self::tryParse($version) !== null;
    }

    /** Negative, zero or positive, like the spaceship operator. */
    public function compare(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function isNewerThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor . '.' . $this->patch;
    }
}

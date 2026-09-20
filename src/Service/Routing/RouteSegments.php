<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Module\ModuleRegistry;

/**
 * The handful of FIXED URL words that are not a slug and not a file name, per
 * language (docs/multilingual/ROUTING.md).
 *
 * Most of this project's fixed path segments are technical and stay the same
 * in every language: `blog`, `tag`, `portfolio`, `feed.xml`, every
 * `<name>.php`. Exactly two are Dutch words a visitor reads as language —
 * `categorie` in /blog/categorie/… and `collecties` in /collecties/… — and
 * those are what this catalogue exists for.
 *
 * A CLOSED LIST IN CODE, like App\Service\Blocks\BlockDefinitions and
 * App\Service\RouteRegistry: Core's entries plus the ones every REGISTERED
 * module declares (App\Module\ModuleDefinition::routeSegments(), read whether
 * or not that module is enabled, for the reason
 * ModuleDefinition::reservedSlugs() gives). A request can only ever hit a key
 * of this list or miss it; nothing here is ever built from a request, a
 * database row or a class name.
 *
 * THE SHAPE of one entry:
 *
 *     'blog.category' => ['default' => 'categorie', 'en' => 'category'],
 *
 * `default` is the word any language without an entry of its own uses — a
 * site that adds German gets /de/blog/categorie/… and a working URL, not a
 * 404, which is what "a third language needs no code change" has to mean for
 * a catalogue that is fixed per release. `default` can never collide with a
 * language code, because a code is exactly two letters
 * (App\Service\Language\LanguageCode).
 *
 * A SEGMENT IS PART OF THE DEFAULT LANGUAGE'S URL. Make English the site's
 * default and /collections/… becomes the unprefixed URL while /nl/collecties/…
 * gains a prefix. That is the same rule the slugs follow, and
 * App\Service\Routing\RouteResolver keeps the old address working by matching
 * every variant of a segment and sending one permanent redirect to the
 * canonical one.
 */
final class RouteSegments
{
    /** Core owns no localized segment today; every one of them is a module's. */
    private const CORE_SEGMENTS = [];

    /** @var array<string, array<string, string>>|null */
    private static ?array $segments = null;

    /** Forgets the merged catalogue; App\Module\ModuleRegistry::reset() calls this. */
    public static function reset(): void
    {
        self::$segments = null;
    }

    /**
     * The word this key spells in this language.
     *
     * An unknown key is a programming error rather than a request problem —
     * keys are written in route patterns, never received — so it throws
     * instead of silently producing an empty path segment.
     */
    public static function value(string $key, string $language): string
    {
        $entry = self::all()[$key] ?? null;

        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('Unknown route segment "%s".', $key));
        }

        return $entry[$language] ?? $entry['default'];
    }

    /**
     * Every distinct word this key has in any language, the canonical one for
     * $language first.
     *
     * This is what lets a URL written in another language's words still be
     * recognised — /en/blog/categorie/… is understood and permanently
     * redirected to /en/blog/category/… rather than 404'ing a link that used
     * to work.
     *
     * @return list<string>
     */
    public static function variants(string $key, string $language): array
    {
        $entry = self::all()[$key] ?? null;

        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('Unknown route segment "%s".', $key));
        }

        $canonical = $entry[$language] ?? $entry['default'];

        return array_values(array_unique(array_merge([$canonical], array_values($entry))));
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Every word ONE key can spell, in no particular language — what a slug
     * check asks when it wants to keep a row away from a namespace it owns
     * (App\Service\Blog\BlogSlug::reservedSegments()).
     *
     * variants() needs a language because it puts that language's word first;
     * this does not, and asking it nothing about languages keeps it usable
     * where there is no request and no registry to read.
     *
     * @return list<string>
     */
    public static function words(string $key): array
    {
        $entry = self::all()[$key] ?? null;

        if ($entry === null) {
            throw new \InvalidArgumentException(sprintf('Unknown route segment "%s".', $key));
        }

        return array_values(array_unique(array_values($entry)));
    }

    /**
     * Every word any segment can spell, in any language — what
     * App\Service\Routing\ReservedPaths keeps a page slug away from, so
     * `collections` can no more become a page than `collecties` already
     * could.
     *
     * @return list<string>
     */
    public static function allWords(): array
    {
        $words = [];
        foreach (self::all() as $entry) {
            foreach ($entry as $word) {
                $words[] = $word;
            }
        }

        return array_values(array_unique($words));
    }

    /** @return array<string, array<string, string>> */
    public static function all(): array
    {
        if (self::$segments !== null) {
            return self::$segments;
        }

        $segments = self::CORE_SEGMENTS;

        foreach (ModuleRegistry::all() as $moduleKey => $module) {
            foreach ($module->routeSegments() as $key => $entry) {
                if (array_key_exists($key, $segments)) {
                    error_log(sprintf(
                        '[RouteSegments] module "%s" tried to contribute "%s", which is already taken',
                        $moduleKey,
                        (string) $key
                    ));

                    continue;
                }

                if (!isset($entry['default']) || !is_string($entry['default']) || $entry['default'] === '') {
                    error_log(sprintf('[RouteSegments] segment "%s" has no default word', (string) $key));

                    continue;
                }

                $segments[(string) $key] = $entry;
            }
        }

        return self::$segments = $segments;
    }
}

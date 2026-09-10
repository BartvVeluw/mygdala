<?php

declare(strict_types=1);

namespace App\Service\Blog;

use App\Repository\BlogSettingRepository;

/**
 * The Blog's own settings: how it introduces itself, how many posts a page
 * shows, and which four optional pieces it prints.
 *
 * DELIBERATELY SMALL, and it will stay small. This is not a layout editor and
 * not a theme: what the Blog LOOKS like comes from the public Theme's tokens
 * (THEMING.md), exactly like every other public page. The settings below are
 * the handful of decisions that are genuinely editorial — a title, an
 * introduction, a page size, and whether a byline, a date, related posts and
 * a feed belong on this particular site.
 *
 * A MISSING ROW MEANS THE CODE DEFAULT (`blog_settings` is seeded empty), so
 * switching the module on gives a coherent Blog before anybody opens the
 * settings screen, and a stored value that has become nonsense falls back the
 * same way. The three booleans default to ON and the feed to ON because that
 * is what a blog normally is; posts per page defaults to 9, which fills the
 * three-column listing grid exactly.
 *
 * WHY ITS OWN TABLE rather than `site_settings`: a module owns its own
 * storage (MODULES.md), and Core's identity settings must not carry a key
 * only the Blog understands. See the migration for the four tables this now
 * sits beside and why they are all separate.
 */
final class BlogSettings
{
    public const TITLE = 'blog_title';
    public const TITLE_EN = 'blog_title_en';
    public const INTRO = 'blog_intro';
    public const INTRO_EN = 'blog_intro_en';
    public const POSTS_PER_PAGE = 'blog_posts_per_page';
    public const SHOW_AUTHOR = 'blog_show_author';
    public const SHOW_DATE = 'blog_show_date';
    public const RELATED_POSTS = 'blog_related_posts';
    public const RSS_ENABLED = 'blog_rss_enabled';

    /** What the listing is called when nobody has renamed it. */
    public const DEFAULT_TITLE = 'Blog';
    public const DEFAULT_TITLE_EN = 'Blog';

    public const DEFAULT_POSTS_PER_PAGE = 9;
    public const MIN_POSTS_PER_PAGE = 3;
    public const MAX_POSTS_PER_PAGE = 48;

    /** How many related posts a detail page shows at most. Not a setting: a row is a row. */
    public const RELATED_POSTS_LIMIT = 3;

    public const MAX_TITLE_LENGTH = 150;
    public const MAX_INTRO_LENGTH = 1000;

    /** @var array<string, string>|null resolved once per request */
    private static ?array $cache = null;

    /** @var array<string, string>|null test seam; see overrideForTests() */
    private static ?array $override = null;

    /* ---------------------------------------------------------------- */
    /* The individual answers                                            */
    /* ---------------------------------------------------------------- */

    /** The listing's heading, in one language, with the usual NL fallback. */
    public static function title(string $lang = 'nl'): string
    {
        $nl = self::value(self::TITLE, self::DEFAULT_TITLE);
        $nl = $nl === '' ? self::DEFAULT_TITLE : $nl;

        if ($lang !== 'en') {
            return $nl;
        }

        $en = self::value(self::TITLE_EN, '');

        return $en !== '' ? $en : $nl;
    }

    /** The optional paragraph under that heading. '' means: print none. */
    public static function intro(string $lang = 'nl'): string
    {
        $nl = self::value(self::INTRO, '');

        if ($lang !== 'en') {
            return $nl;
        }

        $en = self::value(self::INTRO_EN, '');

        return $en !== '' ? $en : $nl;
    }

    /**
     * How many posts one listing page shows. Clamped to a sane range: a
     * stored 0 would make an endless listing and a stored 5000 would make the
     * page that "does not load every post at once" load every post at once.
     */
    public static function postsPerPage(): int
    {
        $stored = (int) self::value(self::POSTS_PER_PAGE, (string) self::DEFAULT_POSTS_PER_PAGE);

        if ($stored < self::MIN_POSTS_PER_PAGE || $stored > self::MAX_POSTS_PER_PAGE) {
            return self::DEFAULT_POSTS_PER_PAGE;
        }

        return $stored;
    }

    public static function showAuthor(): bool
    {
        return self::flag(self::SHOW_AUTHOR);
    }

    public static function showDate(): bool
    {
        return self::flag(self::SHOW_DATE);
    }

    public static function relatedPostsEnabled(): bool
    {
        return self::flag(self::RELATED_POSTS);
    }

    public static function rssEnabled(): bool
    {
        return self::flag(self::RSS_ENABLED);
    }

    /* ---------------------------------------------------------------- */
    /* Storage                                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Everything stored, for the settings screen. Only keys this class knows
     * are returned; anything else in the table is ignored rather than shown.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = [];

        try {
            $stored = (new BlogSettingRepository())->findAll();
        } catch (\Throwable $e) {
            // No table yet, or no database at all: both mean "nothing was
            // ever chosen", which is exactly what the code defaults are for.
            error_log('[BlogSettings] falling back to defaults: ' . $e->getMessage());
        }

        $clean = [];
        foreach (self::keys() as $key) {
            if (array_key_exists($key, $stored)) {
                $clean[$key] = (string) $stored[$key];
            }
        }

        return self::$cache = $clean;
    }

    /**
     * Writes the given settings. Unknown keys are dropped rather than
     * rejected, the same rule App\Module\ModuleSettings applies: a stale
     * field from an older form configures nothing instead of failing a save.
     *
     * @param array<string, string> $values
     */
    public static function save(array $values): void
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && in_array($key, self::keys(), true)) {
                $clean[$key] = (string) $value;
            }
        }

        if ($clean === []) {
            return;
        }

        (new BlogSettingRepository())->upsertMany($clean);
        self::clearCache();
    }

    /** @return list<string> every key this class owns */
    public static function keys(): array
    {
        return [
            self::TITLE,
            self::TITLE_EN,
            self::INTRO,
            self::INTRO_EN,
            self::POSTS_PER_PAGE,
            self::SHOW_AUTHOR,
            self::SHOW_DATE,
            self::RELATED_POSTS,
            self::RSS_ENABLED,
        ];
    }

    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Test seam: pretend these are the stored settings, without a database.
     * Pass null to go back to reading storage; always reset in tearDown().
     *
     * @param array<string, string>|null $values
     */
    public static function overrideForTests(?array $values): void
    {
        self::$override = $values;
        self::$cache = null;
    }

    /* ---------------------------------------------------------------- */

    private static function value(string $key, string $default): string
    {
        return trim((string) (self::all()[$key] ?? $default));
    }

    /**
     * A stored boolean. ON unless it is explicitly off, the same direction
     * App\Module\ModuleConfig and the SEO indexing switch take: a missing or
     * unreadable value must not silently remove something the site had.
     */
    private static function flag(string $key): bool
    {
        $raw = strtolower(trim((string) (self::all()[$key] ?? '')));

        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }
}

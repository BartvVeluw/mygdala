<?php

namespace App\Service;

use App\Repository\HomepageHeroRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Routing\RequestLanguage;
use App\Service\Routing\TypedLink;

/**
 * Content for the dedicated "Homepage Hero" section (`.hero` on index.php) —
 * a deliberately SEPARATE type from the generic "Page hero" pattern
 * (App\Service\PageHeroContent / `page_heroes`) used on the other pages. The
 * Homepage Hero's shape (image + badge, two CTAs, an inline title highlight,
 * a small stats repeater) does not fit the generic Page hero's
 * eyebrow/H1/lead/breadcrumb shape, so it gets its own table, its own
 * Content/Repository pair, and its own admin editor
 * (`admin/homepage-hero.php`). The generic Page hero system is untouched.
 *
 * A page-bound singleton keyed by page_slug (currently only 'index' —
 * PAGE_SLUG below), same state-model conventions as every other section type
 * here: STATE_FALLBACK (no row / DB unreachable — nothing to render),
 * STATE_ACTIVE (row is active — render its own content) and STATE_HIDDEN
 * (row exists and is_active = false — render nothing). The admin editor does
 * NOT expose a checkbox for is_active — this state model exists for
 * consistency with the rest of the CMS, not because the owner can toggle the
 * whole Homepage Hero off from the UI.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3B). The eyebrow, title, title
 * highlight, lead, both button labels, the image's alt text and the badge are
 * stored per website language in block_translations, and so are the two
 * texts of every stat, on the stat's own row (HomepageHeroBlock::childTables()).
 * They come out of App\Service\Blocks\BlockLocalization as one string per
 * field, in the language of the request and the fallback already applied
 * (the result is cached per language); the URLs, the media, the layout,
 * the highlight size, is_active and the order of the stats stay in
 * homepage_hero and homepage_hero_stats, the same in every language. This
 * class decides no language itself.
 *
 * NOTHING STORED MEANS NOTHING RENDERED. There is no hardcoded fallback copy:
 * a missing row, or a lookup that fails, renders no Hero at all, and an active
 * row renders exactly what it stores — text, media and stats alike, even when
 * one of them is empty. Both used to fall back on the copy and the photograph
 * of the site this CMS grew out of, which is how a fresh installation, whose
 * bootstrap row deliberately stores an empty image, showed that photograph.
 * An empty image is an answer (this Hero has no image); {@see hasMedia()} is
 * what a renderer asks. The editor requires eyebrow, title and the primary
 * button in the default language, so an empty one only comes from data
 * written outside it.
 *
 * startingValues() and startingWords() answer a different question: what a
 * Hero row that does not exist yet is created with — by
 * HomepageHeroBlock::create(), which admin/homepage-hero.php also uses the
 * first time it is opened. Generic, editable copy, and never rendered in
 * place of a row that is missing or unreadable.
 *
 * Title highlight: the highlight is plain text, never HTML — the exact
 * substring of the title in the same language that should be wrapped in
 * `<em>`. isHighlightValid() is the save-time check (must occur verbatim in
 * the title, or be empty); renderTitleFragment() is the render-time,
 * XSS-safe HTML builder: it escapes the title's three pieces (before/match/
 * after) independently and only ever wraps the match in a literal, hardcoded
 * `<em>...</em>` — user-entered text can never itself introduce a tag.
 * It runs on the request language's title and highlight. If invalid/legacy
 * data somehow reaches the renderer (highlight no longer found in the
 * title), it fails safe: the complete escaped title, no highlight, rather
 * than breaking the Hero.
 *
 * Highlight size: `title_highlight_size` is a PERCENTAGE of the headline's
 * own font size (HIGHLIGHT_SIZE_MIN..HIGHLIGHT_SIZE_MAX, default
 * HIGHLIGHT_SIZE_DEFAULT = 100 = unchanged), never an absolute size — the
 * frontend renders it as a `--hero-highlight-size` custom property that
 * `.hero h1 em` resolves with `font-size: var(--hero-highlight-size, 100%)`,
 * so the highlight keeps scaling with the existing `clamp()`-based
 * responsive H1 on every breakpoint. Deliberately ONE shared visual setting
 * rather than one per language, even though the highlight TEXT is stored
 * per language: the size is a layout choice for the block, and a
 * per-language size would be a second setting to keep in step for no
 * editorial gain.
 * isHighlightSizeValid() is the save-time check (see
 * api/admin/update-homepage-hero.php, which rejects anything else);
 * clampHighlightSize() is the render-time belt-and-braces layer that maps a
 * legacy NULL — a row written before this column existed — and any
 * out-of-range/non-numeric value back onto a valid percentage, so such a
 * Hero renders exactly as it did before the setting existed.
 *
 * Secondary CTA and badge are both optional and all-or-nothing at render
 * time, same convention as CtaBandContent's secondary button: a half-filled
 * one is treated as "not set", never rendered broken. The words that decide
 * are the default language's, which every other language falls back to.
 *
 * Stats: up to 3 rows in `homepage_hero_stats`, same repeater
 * architecture/conventions as StatStripContent (own table, not a reuse of
 * stat_strips — see the migration docblocks). Once the Hero row is active,
 * its active stats are authoritative, even an empty list. A stat without
 * both texts in the default language is not there either: the default
 * language decides whether a stat shows, as it does for the Hero.
 */
class HomepageHeroContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /** The only page slug this type is currently used on. */
    public const PAGE_SLUG = 'index';

    /** The Hero layout is visually tuned for at most this many stats. */
    public const MAX_STATS = 3;

    /**
     * Bounds for `title_highlight_size`, as a percentage of the H1's own
     * responsive font size. The range is deliberately narrow: below ~60% the
     * highlight stops reading as part of the same sentence, above ~120% it
     * starts breaking the headline's line rhythm. DEFAULT = 100 means "same
     * size as the rest of the headline", i.e. exactly how every Hero
     * rendered before this setting existed.
     */
    public const HIGHLIGHT_SIZE_MIN = 60;
    public const HIGHLIGHT_SIZE_MAX = 120;
    public const HIGHLIGHT_SIZE_DEFAULT = 100;

    /**
     * Granularity of the admin slider only. Save-time validation
     * deliberately does NOT enforce it — any whole percentage inside the
     * bounds above is a perfectly valid size, and rejecting e.g. 83 would
     * only make hand-set/imported values fail for no visual reason.
     */
    public const HIGHLIGHT_SIZE_STEP = 5;

    /** Hero media types — which of image_path/video_path is rendered. */
    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_VIDEO = 'video';

    /** All valid media_type values, for save-time/render-time validation. */
    public const MEDIA_TYPES = [self::MEDIA_TYPE_IMAGE, self::MEDIA_TYPE_VIDEO];

    /**
     * Hero layouts: content-left/media-right (the original, default Hero
     * layout, unchanged), content-right/media-left, and a full-bleed
     * background with the content rendered on top.
     */
    public const LAYOUT_MEDIA_RIGHT = 'media_right';
    public const LAYOUT_MEDIA_LEFT = 'media_left';
    public const LAYOUT_BACKGROUND = 'background';

    /** All valid layout values, for save-time/render-time validation. */
    public const LAYOUTS = [self::LAYOUT_MEDIA_RIGHT, self::LAYOUT_MEDIA_LEFT, self::LAYOUT_BACKGROUND];

    /** The owner tables of this block's words (HomepageHeroBlock::translatableFields()). */
    private const TABLE = 'homepage_hero';
    private const STATS = 'homepage_hero_stats';

    /**
     * What a new Hero row is created with, the same in every language — see
     * startingValues(). The words are STARTING_WORDS.
     */
    private const STARTING_VALUES = [
        'title_highlight_size' => self::HIGHLIGHT_SIZE_DEFAULT,
        'primary_url' => '/',
        'secondary_url' => '',
        'image_path' => '',
        'media_type' => self::MEDIA_TYPE_IMAGE,
        'video_path' => '',
        'layout' => self::LAYOUT_MEDIA_RIGHT,
    ];

    /**
     * The starting words of a new Hero — see startingWords(). The same
     * placeholder copy the fresh-install bootstrap writes
     * (db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php):
     * it fills every field the text form requires, and nothing else.
     */
    private const STARTING_WORDS = [
        'eyebrow' => 'Welkom',
        'title' => 'Nieuwe website — pas deze titel aan',
        'lead' => 'Vertel hier in een paar zinnen wat je doet. Pas deze tekst aan in de paginabouwer van de homepage.',
        'primary_label' => 'Meer informatie',
    ];

    /** @var array<string, array<string, mixed>> per request language */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), a string
     *                                per translatable field, the language-
     *                                neutral fields as strings (the highlight
     *                                size as an int), plus 'stats': a list of
     *                                primary_text and secondary_text (a
     *                                string each). Templates must only
     *                                render the section when 'state' ===
     *                                STATE_ACTIVE; the content fields are
     *                                still present (empty) otherwise, purely
     *                                so a template that forgets the check
     *                                fails safe instead of erroring on a
     *                                missing key.
     */
    public static function current(): array
    {
        $language = RequestLanguage::current();

        return self::$cache[$language] ??= self::build();
    }

    /** @return array<string, mixed> see current() */
    private static function build(): array
    {
        try {
            $repository = new HomepageHeroRepository();
            $row = $repository->findBySlug(self::PAGE_SLUG);
        } catch (\Throwable $e) {
            error_log('[HomepageHeroContent] lookup failed: ' . $e->getMessage());

            return self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $heroId = (int) $row['id'];

        try {
            $stats = $repository->findStatsByHeroId($heroId, true);
        } catch (\Throwable $e) {
            error_log('[HomepageHeroContent] stats lookup failed: ' . $e->getMessage());

            return self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // The words of the Hero and all of its stats at once; nothing when
        // the page already loaded them (SectionRegistry::renderPage()).
        BlockLocalization::preloadBlocks([self::TABLE => [$heroId]]);

        $content = BlockLocalization::words(self::TABLE, $heroId) + [
            'title_highlight_size' => self::clampHighlightSize($row['title_highlight_size'] ?? null),
            // Typed by an editor, printed in the language being read
            // (App\Service\Routing\TypedLink).
            'primary_url' => TypedLink::href((string) ($row['primary_url'] ?? '')),
            'secondary_url' => TypedLink::href((string) ($row['secondary_url'] ?? '')),
            // Once a row exists, its media is authoritative: an empty
            // image_path means "this Hero has no image" — see hasMedia().
            'image_path' => (string) ($row['image_path'] ?? ''),
            // An empty or unknown media_type/layout is coerced onto a valid
            // one below: a structural value, never copy.
            'media_type' => (string) ($row['media_type'] ?? ''),
            'video_path' => (string) ($row['video_path'] ?? ''),
            'layout' => (string) ($row['layout'] ?? ''),
        ];

        // A secondary button only renders when it has both a label and a
        // URL — a half-filled optional button would be broken/dead. The
        // default language's label decides, whatever language is being read.
        if (!BlockLocalization::hasDefaultWords(self::TABLE, $heroId, 'secondary_label') || $content['secondary_url'] === '') {
            $content['secondary_label'] = '';
            $content['secondary_url'] = '';
        }

        // The badge only renders when it has both a title and a body text —
        // a half-filled badge would look broken.
        if (!BlockLocalization::hasDefaultWords(self::TABLE, $heroId, 'badge_title')
            || !BlockLocalization::hasDefaultWords(self::TABLE, $heroId, 'badge_text')) {
            $content['badge_title'] = '';
            $content['badge_text'] = '';
        }

        // Defensive fallbacks against stale/invalid data reaching the
        // renderer — never let a half-configured video (media_type=video
        // saved before any file was ever uploaded) or an unrecognised layout
        // value break the Hero. isHighlightValid()-style save-time
        // validation (see api/admin/update-homepage-hero-media.php and
        // api/admin/update-homepage-hero-video.php) is the primary guard;
        // this is the belt-and-braces second layer.
        if (!in_array($content['media_type'], self::MEDIA_TYPES, true)
            || ($content['media_type'] === self::MEDIA_TYPE_VIDEO && $content['video_path'] === '')
        ) {
            $content['media_type'] = self::MEDIA_TYPE_IMAGE;
        }
        if (!in_array($content['layout'], self::LAYOUTS, true)) {
            $content['layout'] = self::LAYOUT_MEDIA_RIGHT;
        }

        // Whatever comes back — including an empty list — is authoritative
        // once the Hero row exists and is active: the admin has deliberately
        // curated these stats, so an empty result means "all stats
        // hidden/deleted", not "missing data".
        $content['stats'] = [];
        foreach ($stats as $stat) {
            $statId = (int) $stat['id'];

            if (BlockLocalization::hasRequiredWords(self::STATS, $statId)) {
                $content['stats'][] = BlockLocalization::words(self::STATS, $statId);
            }
        }

        $content['state'] = self::STATE_ACTIVE;

        return $content;
    }

    /**
     * Whether this Hero has anything to put in its media column.
     *
     * A Hero with no image and no video renders no media column at all,
     * rather than an `<img src="">` that resolves to the page itself and
     * shows the browser's broken-image icon. That is the normal state of a
     * brand-new installation: the bootstrap row stores an empty image on
     * purpose, and the owner picks one in the page builder.
     *
     * @param array<string, mixed> $hero see {@see current()}
     */
    public static function hasMedia(array $hero): bool
    {
        if (($hero['media_type'] ?? self::MEDIA_TYPE_IMAGE) === self::MEDIA_TYPE_VIDEO) {
            return trim((string) ($hero['video_path'] ?? '')) !== '';
        }

        return trim((string) ($hero['image_path'] ?? '')) !== '';
    }

    /**
     * What a Homepage Hero row that does not exist yet is created with, by
     * HomepageHeroBlock::create(): what is the same in every language. The
     * words are startingWords(). Section-level fields only: a new Hero has
     * no stats.
     *
     * Not a frontend fallback. current() never renders these in place of a
     * row that is missing or unreadable; it renders nothing.
     *
     * @return array<string, string|int> every value is a string except
     *                                   'title_highlight_size' (int percent)
     */
    public static function startingValues(): array
    {
        return self::STARTING_VALUES;
    }

    /**
     * The starting words of a Hero that does not exist yet, written in the
     * website's default language: generic, editable copy in the fields the
     * editor requires, and a lead that says where to change it.
     *
     * @return array<string, string> field => words
     */
    public static function startingWords(): array
    {
        return self::STARTING_WORDS;
    }

    /**
     * The language-neutral values of a stored Hero row, in the shape
     * HomepageHeroRepository::upsert() takes them (without is_active, which
     * every editor save sets itself): what each editor endpoint carries
     * forward unchanged while it changes its own part, since upsert() always
     * writes the complete row. The highlight size is clamped exactly as the
     * renderer reads it; an empty media_type or layout gets the one a new
     * Hero starts with.
     *
     * @param array<string, mixed> $row a homepage_hero row
     * @return array{title_highlight_size: int, primary_url: string, secondary_url: string, image_path: string, media_type: string, video_path: string, layout: string}
     */
    public static function settingsOf(array $row): array
    {
        return [
            'title_highlight_size' => self::clampHighlightSize($row['title_highlight_size'] ?? null),
            'primary_url' => (string) ($row['primary_url'] ?? ''),
            'secondary_url' => (string) ($row['secondary_url'] ?? ''),
            'image_path' => (string) ($row['image_path'] ?? ''),
            'media_type' => (string) ($row['media_type'] ?? self::STARTING_VALUES['media_type']),
            'video_path' => (string) ($row['video_path'] ?? ''),
            'layout' => (string) ($row['layout'] ?? self::STARTING_VALUES['layout']),
        ];
    }

    /**
     * Save-time validation for a title highlight: empty is always valid (no
     * emphasis); otherwise it must occur verbatim (case-sensitive, exact
     * substring) in the given title. Used by
     * api/admin/update-homepage-hero.php — never silently save an impossible
     * highlight.
     */
    public static function isHighlightValid(string $title, string $highlight): bool
    {
        return $highlight === '' || mb_strpos($title, $highlight) !== false;
    }

    /**
     * Save-time validation for the highlight size: must be a whole number of
     * percent inside HIGHLIGHT_SIZE_MIN..HIGHLIGHT_SIZE_MAX. Used by
     * api/admin/update-homepage-hero.php, which rejects the save rather than
     * silently storing something the admin did not choose — the slider can
     * only ever produce a valid value, so anything else is a tampered or
     * hand-crafted POST. Note this REJECTS, where clampHighlightSize()
     * (render time, for data already in the database) COERCES; the two are
     * complementary on purpose, same two-layer convention as media_type and
     * layout.
     *
     * @param mixed $value raw POST value
     */
    public static function isHighlightSizeValid($value): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        $raw = trim((string) $value);
        // Must round-trip through int unchanged, which rules out everything
        // the bounds check below would otherwise wave through: "85.5", "1e2",
        // "90px", "007" and "+90" all differ from (string) (int) themselves.
        if ($raw === '' || (string) (int) $raw !== $raw) {
            return false;
        }

        $size = (int) $raw;

        return $size >= self::HIGHLIGHT_SIZE_MIN && $size <= self::HIGHLIGHT_SIZE_MAX;
    }

    /**
     * Render-time coercion of whatever is actually stored for the highlight
     * size onto a usable percentage. NULL (a row written before the column
     * existed) and any non-numeric value become HIGHLIGHT_SIZE_DEFAULT, so
     * such a Hero renders exactly as it did before this setting existed; a
     * numeric value outside the bounds is clamped rather than discarded, so
     * a hand-edited row still renders something close to what was intended.
     *
     * @param mixed $value raw database value
     */
    public static function clampHighlightSize($value): int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return self::HIGHLIGHT_SIZE_DEFAULT;
        }

        return max(self::HIGHLIGHT_SIZE_MIN, min(self::HIGHLIGHT_SIZE_MAX, (int) $value));
    }

    /**
     * Renders a title with its highlight as a single-escaped, safe HTML
     * fragment: the title's three pieces (before/match/after the highlight)
     * are each independently escaped via htmlspecialchars, and only the
     * match is wrapped in a literal, hardcoded `<em>...</em>` — so
     * user-entered title/highlight text can never itself introduce markup.
     *
     * The partial echoes it as the <h1>'s content, for the request language's
     * title and highlight (both already resolved by the fallback, so a
     * translation without a highlight of its own highlights the default
     * language's words wherever they occur in its title). It is the one
     * element of this block printed as markup, and it never holds an
     * editor's markup: only escaped text and the hardcoded `<em>`.
     *
     * If the highlight is empty, or (invalid/legacy data) no longer occurs
     * in the title, this fails safe: the complete escaped title, no
     * highlight — never a broken or partial render.
     */
    public static function renderTitleFragment(string $title, string $highlight): string
    {
        if ($highlight === '') {
            return htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        }

        $position = mb_strpos($title, $highlight);
        if ($position === false) {
            return htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        }

        $before = mb_substr($title, 0, $position);
        $match = mb_substr($title, $position, mb_strlen($highlight));
        $after = mb_substr($title, $position + mb_strlen($highlight));

        return htmlspecialchars($before, ENT_QUOTES, 'UTF-8')
            . '<em>' . htmlspecialchars($match, ENT_QUOTES, 'UTF-8') . '</em>'
            . htmlspecialchars($after, ENT_QUOTES, 'UTF-8');
    }


    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handlers right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * Every field current() returns, empty — what there is when nothing is
     * stored. media_type, layout and the highlight size keep their
     * structural values, so a template reading them still gets a valid one.
     *
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        return BlockLocalization::words(self::TABLE, 0) + [
            'title_highlight_size' => self::HIGHLIGHT_SIZE_DEFAULT,
            'primary_url' => '',
            'secondary_url' => '',
            'image_path' => '',
            'media_type' => self::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => self::LAYOUT_MEDIA_RIGHT,
            'stats' => [],
        ];
    }
}

<?php

namespace App\Service;

use App\Repository\HomepageHeroRepository;

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
 * NOTHING STORED MEANS NOTHING RENDERED. There is no hardcoded fallback copy:
 * a missing row, or a lookup that fails, renders no Hero at all, and an active
 * row renders exactly what it stores — text, media and stats alike, even when
 * one of them is empty. Both used to fall back on the copy and the photograph
 * of the site this CMS grew out of, which is how a fresh installation, whose
 * bootstrap row deliberately stores an empty image, showed that photograph.
 * An empty image is an answer (this Hero has no image); {@see hasMedia()} is
 * what a renderer asks. The editor requires eyebrow, title and the primary
 * button, so an empty one only comes from data written outside it.
 *
 * startingValues() answers a different question: what a Hero row that does
 * not exist yet is created with — by admin/homepage-hero.php, by the
 * api/admin/update-homepage-hero*.php endpoints and by
 * HomepageHeroBlock::create(). Generic, editable copy, and never rendered in
 * place of a row that is missing or unreadable.
 *
 * Title highlight: `title_highlight_nl`/`_en` are plain text, never HTML —
 * the exact substring of the corresponding title that should be wrapped in
 * `<em>`. isHighlightValid() is the save-time check (must occur verbatim in
 * the title, or be empty); renderTitleFragment() is the render-time,
 * XSS-safe HTML builder: it escapes the title's three pieces (before/match/
 * after) independently and only ever wraps the match in a literal, hardcoded
 * `<em>...</em>` — user-entered text can never itself introduce a tag. If
 * invalid/legacy data somehow reaches the renderer (highlight no longer
 * found in the title), it fails safe: the complete escaped title, no
 * highlight, rather than breaking the Hero.
 *
 * Highlight size: `title_highlight_size` is a PERCENTAGE of the headline's
 * own font size (HIGHLIGHT_SIZE_MIN..HIGHLIGHT_SIZE_MAX, default
 * HIGHLIGHT_SIZE_DEFAULT = 100 = unchanged), never an absolute size — the
 * frontend renders it as a `--hero-highlight-size` custom property that
 * `.hero h1 em` resolves with `font-size: var(--hero-highlight-size, 100%)`,
 * so the highlight keeps scaling with the existing `clamp()`-based
 * responsive H1 on every breakpoint. Deliberately ONE shared visual setting
 * rather than a per-language pair, even though the highlight TEXT is
 * bilingual: the language switch (assets/js/core.js's applyLang) only swaps
 * innerHTML/attribute values from data-nl/data-en and never touches CSS
 * custom properties, so a per-language size would need a new switching
 * mechanism outside that convention for no editorial gain.
 * isHighlightSizeValid() is the save-time check (see
 * api/admin/update-homepage-hero.php, which rejects anything else);
 * clampHighlightSize() is the render-time belt-and-braces layer that maps a
 * legacy NULL — a row written before this column existed — and any
 * out-of-range/non-numeric value back onto a valid percentage, so such a
 * Hero renders exactly as it did before the setting existed.
 *
 * Secondary CTA and badge are both optional and all-or-nothing at render
 * time, same convention as CtaBandContent's secondary button: a half-filled
 * one is treated as "not set", never rendered broken.
 *
 * Stats: up to 3 rows in `homepage_hero_stats`, same repeater
 * architecture/conventions as StatStripContent (own table, not a reuse of
 * stat_strips — see the migration docblocks). Once the Hero row is active,
 * its active stats are authoritative, even an empty list.
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

    /**
     * What a new Hero row is created with — see startingValues(). The same
     * placeholder copy the fresh-install bootstrap writes
     * (db/migrations/20260909400000_bootstrap_a_generic_fresh_install.php):
     * it fills every field the editor requires, and nothing else.
     */
    private const STARTING_VALUES = [
        'eyebrow_nl' => 'Welkom',
        'eyebrow_en' => 'Welcome',
        'title_nl' => 'Nieuwe website — pas deze titel aan',
        'title_en' => 'New website — edit this title',
        'title_highlight_nl' => '',
        'title_highlight_en' => '',
        'title_highlight_size' => self::HIGHLIGHT_SIZE_DEFAULT,
        'lead_nl' => 'Vertel hier in een paar zinnen wat je doet. Pas deze tekst aan in de paginabouwer van de homepage.',
        'lead_en' => 'Say in a few sentences what you do. Edit this text in the homepage page builder.',
        'primary_label_nl' => 'Meer informatie',
        'primary_label_en' => 'Learn more',
        'primary_url' => '/',
        'secondary_label_nl' => '',
        'secondary_label_en' => '',
        'secondary_url' => '',
        'image_path' => '',
        'image_alt_nl' => '',
        'image_alt_en' => '',
        'badge_title_nl' => '',
        'badge_title_en' => '',
        'badge_text_nl' => '',
        'badge_text_en' => '',
        'media_type' => self::MEDIA_TYPE_IMAGE,
        'video_path' => '',
        'layout' => self::LAYOUT_MEDIA_RIGHT,
    ];

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), all scalar
     *                                fields, plus 'stats': list of
     *                                primary_text_nl/en, secondary_text_nl/en.
     *                                Templates must only render the section
     *                                when 'state' === STATE_ACTIVE; the
     *                                content fields are still present
     *                                (empty) otherwise, purely so a template
     *                                that forgets the check fails safe
     *                                instead of erroring on a missing key.
     */
    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $row = null;
        try {
            $row = (new HomepageHeroRepository())->findBySlug(self::PAGE_SLUG);
        } catch (\Throwable $e) {
            error_log('[HomepageHeroContent] lookup failed: ' . $e->getMessage());

            return self::$cache = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::$cache = self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $content = [
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'title_highlight_nl' => (string) ($row['title_highlight_nl'] ?? ''),
            'title_highlight_size' => self::clampHighlightSize($row['title_highlight_size'] ?? null),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'primary_label_nl' => (string) ($row['primary_label_nl'] ?? ''),
            'primary_url' => (string) ($row['primary_url'] ?? ''),
            'secondary_label_nl' => (string) ($row['secondary_label_nl'] ?? ''),
            'secondary_url' => (string) ($row['secondary_url'] ?? ''),
            // Once a row exists, its media is authoritative: an empty
            // image_path means "this Hero has no image" — see hasMedia().
            'image_path' => (string) ($row['image_path'] ?? ''),
            'image_alt_nl' => (string) ($row['image_alt_nl'] ?? ''),
            'badge_title_nl' => (string) ($row['badge_title_nl'] ?? ''),
            'badge_text_nl' => (string) ($row['badge_text_nl'] ?? ''),
            // An empty or unknown media_type/layout is coerced onto a valid
            // one below: a structural value, never copy.
            'media_type' => (string) ($row['media_type'] ?? ''),
            'video_path' => (string) ($row['video_path'] ?? ''),
            'layout' => (string) ($row['layout'] ?? ''),
        ];

        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['title_highlight_en'] = self::valueOrDefault($row['title_highlight_en'] ?? null, $content['title_highlight_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);
        $content['primary_label_en'] = self::valueOrDefault($row['primary_label_en'] ?? null, $content['primary_label_nl']);
        $content['secondary_label_en'] = self::valueOrDefault($row['secondary_label_en'] ?? null, $content['secondary_label_nl']);
        // Still the ordinary bilingual rule — an empty EN value means "same
        // as NL" — but the NL value it lands on is now the stored one, so an
        // empty pair stays an empty pair instead of becoming somebody's
        // photo caption.
        $content['image_alt_en'] = self::valueOrDefault($row['image_alt_en'] ?? null, $content['image_alt_nl']);
        $content['badge_title_en'] = self::valueOrDefault($row['badge_title_en'] ?? null, $content['badge_title_nl']);
        $content['badge_text_en'] = self::valueOrDefault($row['badge_text_en'] ?? null, $content['badge_text_nl']);

        // A secondary button only renders when it has both a label and a
        // URL — a half-filled optional button would be broken/dead.
        if ($content['secondary_label_nl'] === '' || $content['secondary_url'] === '') {
            $content['secondary_label_nl'] = '';
            $content['secondary_label_en'] = '';
            $content['secondary_url'] = '';
        }

        // The badge only renders when it has both a title and a body text —
        // a half-filled badge would look broken.
        if ($content['badge_title_nl'] === '' || $content['badge_text_nl'] === '') {
            $content['badge_title_nl'] = '';
            $content['badge_title_en'] = '';
            $content['badge_text_nl'] = '';
            $content['badge_text_en'] = '';
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

        try {
            $stats = (new HomepageHeroRepository())->findStatsByHeroId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[HomepageHeroContent] stats lookup failed: ' . $e->getMessage());

            return self::$cache = self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        // Whatever comes back — including an empty list — is authoritative
        // once the Hero row exists and is active: the admin has deliberately
        // curated these stats, so an empty result means "all stats
        // hidden/deleted", not "missing data".
        $content['stats'] = array_map(static function (array $stat): array {
            $primaryNl = (string) $stat['primary_text_nl'];
            $secondaryNl = (string) $stat['secondary_text_nl'];

            return [
                'primary_text_nl' => $primaryNl,
                'primary_text_en' => self::valueOrDefault($stat['primary_text_en'] ?? null, $primaryNl),
                'secondary_text_nl' => $secondaryNl,
                'secondary_text_en' => self::valueOrDefault($stat['secondary_text_en'] ?? null, $secondaryNl),
            ];
        }, $stats);

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache = $content;
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
     * What a Homepage Hero row that does not exist yet is created with — by
     * admin/homepage-hero.php the first time it is opened, by the
     * api/admin/update-homepage-hero*.php endpoints when they write the first
     * row, and by HomepageHeroBlock::create(). Section-level fields only: a
     * new Hero has no stats.
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
     * This fragment is meant to be echoed directly as element content (the
     * initial NL render) AND, after one more htmlspecialchars() pass at the
     * call site, embedded as a `data-nl`/`data-en` attribute value for the
     * language switch — see index.php and assets/js/core.js's applyLang(),
     * which sets `el.innerHTML` from that attribute. That second escaping
     * pass is what makes the round-trip through innerHTML safe: the browser
     * decodes the attribute back to this exact fragment, which still only
     * ever contains the hardcoded `<em>`/`</em>` tags plus escaped text.
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
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = null;
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
        return [
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'title_highlight_nl' => '', 'title_highlight_en' => '',
            'title_highlight_size' => self::HIGHLIGHT_SIZE_DEFAULT,
            'lead_nl' => '', 'lead_en' => '',
            'primary_label_nl' => '', 'primary_label_en' => '', 'primary_url' => '',
            'secondary_label_nl' => '', 'secondary_label_en' => '', 'secondary_url' => '',
            'image_path' => '', 'image_alt_nl' => '', 'image_alt_en' => '',
            'badge_title_nl' => '', 'badge_title_en' => '', 'badge_text_nl' => '', 'badge_text_en' => '',
            'media_type' => self::MEDIA_TYPE_IMAGE,
            'video_path' => '',
            'layout' => self::LAYOUT_MEDIA_RIGHT,
            'stats' => [],
        ];
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }
}

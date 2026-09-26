<?php

namespace App\Service;

use App\Repository\CtaBandRepository;
use App\Service\Blocks\BlockLocalization;
use App\Service\Media\ImageFocus;
use App\Service\Media\MediaService;
use App\Service\Routing\LinkChoice;
use App\Service\Routing\RequestLanguage;

/**
 * Content for the "CTA band" block (`.cta-band`) — the eyebrow/H2/lead/
 * button(s) block that closes several pages.
 *
 * ONE STANDARD REUSABLE BLOCK TYPE (phase 2 of
 * docs/content-blocks/ROADMAP.md): every instance is addressed by
 * (page_slug, section_key) and owns its own content, so two CTA bands on the
 * same page are two independent blocks rather than two views of one row —
 * see db/migrations/20260908270000_make_cta_bands_repeatable_per_instance.php.
 * There is no list of "known" CTA pages any more, and no hardcoded per-page
 * copy: the content of every instance lives in the database, where the
 * migration that first created this table already put it.
 *
 * WORDS PER LANGUAGE (Multilingual 2.0 phase 3A). The eyebrow, title, lead and
 * both button labels are stored per website language in block_translations and
 * come out of App\Service\Blocks\BlockLocalization as one string each, in the
 * language of the request, the fallback already applied; the destinations, the
 * presentation and is_active stay in cta_bands, the same in every language.
 * This class decides no language itself.
 *
 * CTA 2.0 (CONTENT-BLOCKS.md, "Oproep met knop"): the band also has a layout
 * and an optional background, each a word from a closed list below, the same
 * in every language; a stored value that is not on its list reads as the
 * list's first entry, which is the presentation every band had before. The
 * layers, from the back: the band's own theme colour, the picture, the overlay
 * over the picture, the text panel, the words.
 *
 * THE BUTTONS. Each button's destination is the shape every block button has
 * (App\Service\Routing\LinkChoice), so a page is followed by id through a
 * slug change, a nesting change and every website language. A button renders
 * only with a destination AND its label in the default language: an address
 * alone never switches a button on, which is what keeps a stale address from
 * an old row (or from before "Geen knop" emptied it) off the page. The second
 * button renders only next to the first: without a first button there is no
 * second one, whatever is stored for it. A button that does not render has an
 * empty label AND href here.
 *
 * `is_active = false` on an *existing* row is a deliberate hide, and a
 * different case from a missing row. forSection()'s returned `state` field
 * is how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — there is nothing to render), STATE_ACTIVE (row is active —
 * render its content) and STATE_HIDDEN (row exists and is_active = false —
 * render nothing for this block).
 */
class CtaBandContent
{
    /** No row exists (or the row lookup failed) — nothing to render. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * The section_key every CTA band that predates the repeatable schema
     * uses. Instances added afterwards get a random custom-xxxxxxxx key like
     * every other repeater type (App\Service\SectionRegistry::create()).
     * Must stay in sync with the migration named above.
     */
    public const MIGRATED_SECTION_KEY = 'main';

    /** The translatable fields of this block (CtaBandBlock::translatableFields()). */
    public const WORDS = ['eyebrow', 'title', 'lead', 'primary_label', 'secondary_label'];

    /**
     * How the words and the buttons are aligned. The first entry is the
     * presentation every band had before CTA 2.0, and what an unknown stored
     * value reads as; the same holds for every list below.
     */
    public const ALIGNMENTS = ['center', 'left', 'right'];

    /**
     * How wide the lead may run: 'narrow' is the 46ch every lead has,
     * 'medium' the site's reading column, 'wide' wider still, 'full' the whole
     * width of the band's text. The lengths live in assets/css/blocks/cta-band.css.
     */
    public const LEAD_WIDTHS = ['narrow', 'medium', 'wide', 'full'];

    /**
     * How strongly the overlay covers the background picture. It is the
     * theme's scrim colour (--color-media-scrim-rgb), so it darkens in a dark
     * theme and lightens in a light one, and the theme's text stays readable.
     * Only drawn over a picture.
     */
    public const OVERLAYS = ['medium', 'none', 'light', 'dark'];

    /** How opaque the text panel is: the theme's surface, from see-through to solid. */
    public const PANEL_OPACITIES = ['strong', 'subtle', 'medium', 'solid'];

    private const TABLE = 'cta_bands';

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), a string per
     *                              field in WORDS, the strings primary_url
     *                              and secondary_url (the buttons' hrefs, ''
     *                              for no button), and the presentation of
     *                              presentation(). lead and either button
     *                              may be empty. Templates must check
     *                              'state' !== STATE_HIDDEN before rendering
     *                              the block at all.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':' . $sectionKey;
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new CtaBandRepository())->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[CtaBandContent] lookup failed for "' . $cacheKey . '": ' . $e->getMessage());
            $row = null;
        }

        return self::$cache[$cacheKey] = self::fromRow($row);
    }

    /**
     * This page's FIRST CTA band, whichever instance that is — for a
     * non-CMS template that deliberately borrows a page's CTA band
     * (portfolio-detail.php reuses Portfolio's) and must not hardcode a
     * section_key to do it.
     *
     * @return array<string, mixed> same shape as forSection()
     */
    public static function firstOnPage(string $pageSlug): array
    {
        $cacheKey = RequestLanguage::current() . '|' . $pageSlug . ':#first';
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        try {
            $row = (new CtaBandRepository())->findFirstBySlug($pageSlug);
        } catch (\Throwable $e) {
            error_log('[CtaBandContent] first-instance lookup failed for "' . $pageSlug . '": ' . $e->getMessage());
            $row = null;
        }

        return self::$cache[$cacheKey] = self::fromRow($row);
    }

    /**
     * The layout and the background of a stored row, every word checked
     * against its closed list; for the page and for the editor alike.
     *
     * The picture is decorative: the words say everything, so it has no alt
     * text of its own, and an item that is gone (or is a video) is simply no
     * picture.
     *
     * @param array<string, mixed> $row
     *
     * @return array{align: string, lead_width: string, full_width: bool, background: array{image_path: string, width: int|null, height: int|null}|null, background_focus: string, overlay: string, panel: string}
     *         panel is '' for no text panel, else a word of PANEL_OPACITIES
     */
    public static function presentation(array $row): array
    {
        $media = MediaService::findImage(isset($row['background_media_id']) ? (int) $row['background_media_id'] : null);

        return [
            'align' => self::choice(self::ALIGNMENTS, $row['content_align'] ?? null),
            'lead_width' => self::choice(self::LEAD_WIDTHS, $row['lead_width'] ?? null),
            'full_width' => (bool) ($row['full_width'] ?? false),
            'background' => $media === null ? null : [
                'image_path' => $media->publicPath(),
                'width' => $media->hasDimensions() ? $media->width : null,
                'height' => $media->hasDimensions() ? $media->height : null,
            ],
            'background_focus' => ImageFocus::normalise($row['background_focus'] ?? null),
            'overlay' => self::choice(self::OVERLAYS, $row['background_overlay'] ?? null),
            'panel' => (bool) ($row['text_panel'] ?? false) ? self::choice(self::PANEL_OPACITIES, $row['text_panel_opacity'] ?? null) : '',
        ];
    }

    /**
     * A stored or posted word as a word of its list: itself when it is on it,
     * else the list's first entry.
     *
     * @param list<string> $list one of the closed lists above
     */
    public static function choice(array $list, mixed $value): string
    {
        return is_string($value) && in_array($value, $list, true) ? $value : $list[0];
    }

    /**
     * Clears the in-process cache, and the block words BlockLocalization
     * holds — used by the admin save handler right after writing a new
     * value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
        BlockLocalization::clearCache();
    }

    /**
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>
     */
    private static function fromRow(?array $row): array
    {
        if ($row === null) {
            return self::emptyContent() + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: the content fields are still filled in
            // (empty) purely so a template that forgets to check 'state'
            // fails safe instead of erroring on a missing key.
            return self::emptyContent() + ['state' => self::STATE_HIDDEN];
        }

        $bandId = (int) $row['id'];

        // THE DEFAULT LANGUAGE DECIDES WHETHER THE BAND SAYS ANYTHING
        // (docs/multilingual/ARCHITECTURE.md): without a title and without a
        // button label in the default language there is no band in any
        // language, however much a translation holds. Its words then stay
        // empty, and the partial renders nothing.
        $hasWords = BlockLocalization::hasDefaultWords(self::TABLE, $bandId, 'title')
            || BlockLocalization::hasDefaultWords(self::TABLE, $bandId, 'primary_label');

        $content = [];
        foreach (self::WORDS as $field) {
            $content[$field] = $hasWords ? BlockLocalization::text(self::TABLE, $bandId, $field) : '';
        }

        // Where each button goes, in the language being read. A button needs
        // its destination and its label in the default language, whatever
        // language is being read; the second one also needs the first.
        $primaryHref = LinkChoice::href($row['primary_link_type'] ?? null, $row['primary_link_target_id'] ?? 0, (string) ($row['primary_url'] ?? ''));
        $hasPrimary = $hasWords && $primaryHref !== '' && BlockLocalization::hasDefaultWords(self::TABLE, $bandId, 'primary_label');

        $secondaryHref = LinkChoice::href($row['secondary_link_type'] ?? null, $row['secondary_link_target_id'] ?? 0, (string) ($row['secondary_url'] ?? ''));
        $hasSecondary = $hasPrimary && $secondaryHref !== '' && BlockLocalization::hasDefaultWords(self::TABLE, $bandId, 'secondary_label');

        $content['primary_url'] = $hasPrimary ? $primaryHref : '';
        $content['secondary_url'] = $hasSecondary ? $secondaryHref : '';
        if (!$hasPrimary) {
            $content['primary_label'] = '';
        }
        if (!$hasSecondary) {
            $content['secondary_label'] = '';
        }

        $content += self::presentation($row);
        $content['state'] = self::STATE_ACTIVE;

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyContent(): array
    {
        $content = [];
        foreach (self::WORDS as $field) {
            $content[$field] = '';
        }

        return $content + ['primary_url' => '', 'secondary_url' => ''] + self::presentation([]);
    }
}

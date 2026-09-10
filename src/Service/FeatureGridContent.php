<?php

namespace App\Service;

use App\Repository\FeatureGridRepository;

/**
 * Content for the "Feature grid" section (`.feature-grid` > `.feature-card`)
 * — the site's first repeater-based CMS section type. See
 * docs/CMS_CONTENT_AUDIT.md, proposed type #3, and MAIN.MD for the exact
 * differences found between the two current usages (homepage value props,
 * over-mij "Mijn stijl") before this schema was chosen.
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) grids that currently exist on the site — verified
 * by inspecting every usage before writing this schema. Adding a new grid to
 * an existing or new page only ever needs a new SECTIONS entry + DEFAULTS
 * entry here, plus the matching PHP loop on that page's template — never a
 * schema change (this is exactly why section_key exists alongside page_slug:
 * a page can have more than one grid without a schema rewrite).
 *
 * ICON_KEYS is the complete, closed set of icons a card may use. The CMS
 * only ever stores one of these keys, never markup — partials/feature-icons.php
 * is the only place that maps a key to its (theme-owned, hand-authored) SVG.
 *
 * DEFAULTS is the fallback used whenever a section's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the exact cards that used to
 * be hardcoded.
 *
 * `is_active = false` on an *existing* grid row is a different, deliberate
 * case: it means the site owner has intentionally hidden the whole section
 * (heading + cards), and must NOT fall back to the defaults — that would
 * make the "Actief" checkbox unable to actually hide anything. forSection()'s
 * returned `state` field is how a template tells the three cases apart:
 * STATE_FALLBACK (no row / DB unreachable — render the default heading +
 * cards), STATE_ACTIVE (row is active — render its own heading + cards) and
 * STATE_HIDDEN (row exists and is_active = false — render nothing for this
 * section).
 *
 * Once a grid's row exists and is active, its *items* come strictly from the
 * database (only is_active = 1 cards), even if that list is empty — an
 * individually hidden/deleted card must stay hidden, not fall back to the
 * defaults. Defaults only apply when the whole section is missing/
 * unreachable, never per missing/hidden card.
 */
class FeatureGridContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + cards. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) grids and their admin-facing labels —
     * the "Pages" list in admin/pages.php. Keyed by "page_slug:section_key".
     * has_heading controls whether the admin editor shows the section-level
     * eyebrow/title/lead inputs and whether the frontend has any heading
     * markup to fill — the homepage grid has none in the current design.
     */
    public const SECTIONS = [
        'index:value-props' => [
            'page_slug' => 'index',
            'section_key' => 'value-props',
            'page_label' => 'Homepage',
            'section_label' => 'Feature grid: Waardeproposities',
            'has_heading' => false,
        ],
        'over-mij:mijn-stijl' => [
            'page_slug' => 'over-mij',
            'section_key' => 'mijn-stijl',
            'page_label' => 'Over mij',
            'section_label' => 'Feature grid: Mijn stijl',
            'has_heading' => true,
        ],
    ];

    /**
     * Controlled icon identifiers a card may use, mapped to an admin-facing
     * label. The frontend/theme (partials/feature-icons.php) maps each key
     * to its fixed SVG markup — never store raw SVG/HTML in the database.
     */
    public const ICON_KEYS = [
        'precision' => 'Precisie (kompas)',
        'heart' => 'Hart',
        'diamond' => 'Diamant',
        'location' => 'Locatie (pin)',
    ];

    private const DEFAULTS = [
        'index:value-props' => [
            'eyebrow_nl' => '',
            'eyebrow_en' => '',
            'title_nl' => '',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            'items' => [
                [
                    'icon_key' => 'precision',
                    'title_nl' => 'Ontwerp op maat',
                    'title_en' => 'Custom design',
                    'body_nl' => 'Heb je nog geen kant-en-klaar bestand? Ik denk mee over vorm, materiaal en plaatsing tot het ontwerp klopt.',
                    'body_en' => "No ready-made file yet? I'll help shape the design, material and placement until it feels right.",
                ],
                [
                    'icon_key' => 'heart',
                    'title_nl' => 'Persoonlijk contact',
                    'title_en' => 'Personal contact',
                    'body_nl' => 'Direct contact met de maker, geen tussenpersoon. Je weet altijd bij wie je aanvraag terechtkomt.',
                    'body_en' => "Direct contact with the maker, no middleman. You always know who's handling your request.",
                ],
                [
                    'icon_key' => 'diamond',
                    'title_nl' => 'Blijvende gravure',
                    'title_en' => 'A lasting engraving',
                    'body_nl' => 'Een lasergravure slijt niet zoals een sticker of opdruk — hij wordt echt in het materiaal aangebracht.',
                    'body_en' => "A laser engraving doesn't wear off like a sticker or print — it's etched right into the material.",
                ],
            ],
        ],
        'over-mij:mijn-stijl' => [
            'eyebrow_nl' => 'Mijn stijl',
            'eyebrow_en' => 'My style',
            'title_nl' => 'Warm, rustig en persoonlijk',
            'title_en' => 'Warm, calm and personal',
            'lead_nl' => 'Ik houd van ontwerpen die mooi zijn in hun eenvoud, maar toch karakter hebben — geen massawerk, maar producten die passen bij mijn eigen stijl én bij die van jou.',
            'lead_en' => "I love designs that are beautiful in their simplicity but still have character — not mass production, but products that suit my own style and yours.",
            'items' => [
                [
                    'icon_key' => 'diamond',
                    'title_nl' => 'Ontwerp én ambacht',
                    'title_en' => 'Design and craft',
                    'body_nl' => 'Ik denk niet alleen mee over de gravure, maar ook over de vorm en het materiaal van het product zelf.',
                    'body_en' => "I don't just think about the engraving, but also the shape and material of the product itself.",
                ],
                [
                    'icon_key' => 'heart',
                    'title_nl' => 'Warme, persoonlijke stijl',
                    'title_en' => 'A warm, personal style',
                    'body_nl' => 'Eenvoud met karakter — geen massaproductie, maar werk dat aandacht heeft gekregen.',
                    'body_en' => "Simplicity with character — not mass production, but work that's been given real attention.",
                ],
                [
                    'icon_key' => 'precision',
                    'title_nl' => 'CO₂ & MOPA laser',
                    'title_en' => 'CO₂ & MOPA laser',
                    'body_nl' => 'Met twee lasertechnieken kan ik uiteenlopende materialen aan, van hout tot staal.',
                    'body_en' => 'With two laser technologies, I can work with a wide range of materials, from wood to steel.',
                ],
                [
                    'icon_key' => 'location',
                    'title_nl' => 'Gevestigd in Nijmegen',
                    'title_en' => 'Based in Nijmegen',
                    'body_nl' => 'Mijn werkplaats staat in Nijmegen; ophalen is altijd mogelijk, verzenden ook.',
                    'body_en' => 'My workshop is in Nijmegen; pickup is always possible, and so is shipping.',
                ],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en
     *                                (may be '' when the section has no
     *                                heading), and 'items': list of
     *                                icon_key/title_nl/en/body_nl/en.
     *                                Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering the
     *                                section at all; the content fields are
     *                                still populated (with DEFAULTS) even
     *                                when hidden, purely so a template that
     *                                forgets the check fails safe instead of
     *                                emitting empty markup.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // A (page_slug, section_key) pair not in DEFAULTS is a page-builder-
        // attached instance, not one of the originally hardcoded sections —
        // it has no hardcoded fallback copy, so an unreachable database
        // degrades to an empty grid rather than throwing.
        $defaults = self::DEFAULTS[$cacheKey] ?? ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'lead_nl' => '', 'lead_en' => '', 'items' => []];

        try {
            $repository = new FeatureGridRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FeatureGridContent] falling back to defaults for "' . $cacheKey . '": ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        if ($row === null) {
            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: NOT a fallback case. The content fields
            // are filled with defaults only as a defensive fallback for a
            // template that forgets to check 'state' — see forSection() docblock.
            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_HIDDEN];
        }

        $content = [
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);

        try {
            $items = $repository->findItemsByGridId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[FeatureGridContent] falling back to defaults for "' . $cacheKey . '" (items lookup failed): ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active cards are queried above, and whatever comes back —
        // including an empty list — is authoritative: a grid row that
        // exists and is active means the admin has deliberately curated its
        // cards, so an empty result is "all cards hidden/deleted", not
        // "missing data", and must not fall back to the defaults above.
        $content['items'] = array_map(static function (array $item): array {
            $titleNl = (string) $item['title_nl'];
            $bodyNl = (string) $item['body_nl'];
            $iconKey = (string) $item['icon_key'];

            return [
                'icon_key' => array_key_exists($iconKey, self::ICON_KEYS) ? $iconKey : array_key_first(self::ICON_KEYS),
                'title_nl' => $titleNl,
                'title_en' => self::valueOrDefault($item['title_en'] ?? null, $titleNl),
                'body_nl' => $bodyNl,
                'body_en' => self::valueOrDefault($item['body_en'] ?? null, $bodyNl),
            ];
        }, $items);

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Section-level fields only (no 'items') — used by the admin edit page
     * to pre-fill the section-heading form the first time a grid is edited.
     *
     * @return array<string, string>
     */
    public static function defaultsForSection(string $pageSlug, string $sectionKey): array
    {
        $defaults = self::DEFAULTS[$pageSlug . ':' . $sectionKey] ?? null;
        if ($defaults === null) {
            return [];
        }

        unset($defaults['items']);

        return $defaults;
    }

    /**
     * Clears the in-process cache — used by the admin save handlers right
     * after writing a new value, and by tests.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private static function valueOrDefault(?string $value, string $default): string
    {
        return ($value !== null && $value !== '') ? $value : $default;
    }
}

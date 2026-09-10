<?php

namespace App\Service;

use App\Repository\PageHeroRepository;

/**
 * Content for the "Page hero" section — the eyebrow/H1/lead/breadcrumb block
 * repeated identically (same markup/CSS) at the top of several pages. See
 * docs/CMS_CONTENT_AUDIT.md, "Recommended smallest next step", and
 * App\Service\SiteSettings for the equivalent pattern this mirrors.
 *
 * This is NOT a generic page builder: PAGES below is the fixed, known list
 * of pages that currently use this exact section type. Adding a new page to
 * this list (or a future, separate website installation reusing this same
 * table) only ever needs a new DEFAULTS entry here plus the matching PHP
 * markup on that page's template — never a schema change.
 *
 * DEFAULTS is the fallback used whenever a page's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the values that used to be
 * hardcoded. An empty *_en value on an active row falls back to the *_nl
 * value, matching the NL-fallback convention already used elsewhere on this
 * site (e.g. products).
 *
 * `is_active = false` on an *existing* row is a different, deliberate case:
 * it means the site owner has intentionally hidden this Page Hero, and must
 * NOT fall back to the defaults — that would make the "Actief" checkbox
 * unable to actually hide anything. forSlug()'s returned `state` field is
 * how a template tells the three cases apart: STATE_FALLBACK (no row / DB
 * unreachable — render the defaults), STATE_ACTIVE (row is active — render
 * its content) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 */
class PageHeroContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known page slugs and their admin-facing label — the "Pages" list in
     * admin/pages.php. Only pages in this list have an editable Page Hero.
     */
    public const PAGES = [
        'diensten' => 'Diensten',
        'portfolio' => 'Portfolio',
        'over-mij' => 'Over mij',
        'contact' => 'Contact',
        'shop' => 'Shop',
    ];

    private const DEFAULTS = [
        'diensten' => [
            'eyebrow_nl' => 'Diensten',
            'eyebrow_en' => 'Services',
            'title_nl' => 'Lasergravure voor elk materiaal',
            'title_en' => 'Laser engraving for every material',
            'lead_nl' => 'Hout, metaal, acryl of glas — elk materiaal vraagt om een andere laser, instelling en afwerking. Hieronder lees je per materiaal wat er mogelijk is, met voorbeelden uit eerder werk.',
            'lead_en' => "Wood, metal, acrylic or glass — every material calls for a different laser, setting and finish. Below you'll find what's possible per material, with examples from past work.",
            'breadcrumb_label_nl' => 'Diensten',
            'breadcrumb_label_en' => 'Services',
        ],
        'portfolio' => [
            'eyebrow_nl' => 'Portfolio',
            'eyebrow_en' => 'Portfolio',
            'title_nl' => 'Een greep uit eerder werk',
            'title_en' => 'A glimpse of past work',
            'lead_nl' => 'Van een gegraveerde snijplank voor een bruiloft tot een aluminium visitekaartje voor een barbershop — hieronder een selectie van wat er allemaal mogelijk is.',
            'lead_en' => "From an engraved cutting board for a wedding to an aluminium business card for a barbershop — below is a selection of what's possible.",
            'breadcrumb_label_nl' => 'Portfolio',
            'breadcrumb_label_en' => 'Portfolio',
        ],
        'over-mij' => [
            'eyebrow_nl' => 'Over mij',
            'eyebrow_en' => 'About',
            'title_nl' => 'Ontwerp en ambacht, samen in één gravure',
            'title_en' => 'Design and craft, together in one engraving',
            'lead_nl' => '',
            'lead_en' => '',
            'breadcrumb_label_nl' => 'Over mij',
            'breadcrumb_label_en' => 'About',
        ],
        'contact' => [
            'eyebrow_nl' => 'Contact',
            'eyebrow_en' => 'Contact',
            'title_nl' => 'Vertel me over jouw idee',
            'title_en' => 'Tell me about your idea',
            'lead_nl' => 'Heb je interesse in een gepersonaliseerde bestelling of zakelijke opdracht? Vul het formulier in en ik denk graag met je mee over ontwerp, materiaal en mogelijkheden.',
            'lead_en' => "Interested in a personalised order or business commission? Fill in the form and I'll be happy to think along about design, material and options.",
            'breadcrumb_label_nl' => 'Contact',
            'breadcrumb_label_en' => 'Contact',
        ],
        'shop' => [
            'eyebrow_nl' => 'Shop',
            'eyebrow_en' => 'Shop',
            'title_nl' => 'Gegraveerde producten, klaar om te bestellen',
            'title_en' => 'Engraved products, ready to order',
            'lead_nl' => 'Naast maatwerk op aanvraag komen hier ook kant-en-klare producten die je direct kunt bestellen — de webshop wordt geleidelijk uitgebreid.',
            'lead_en' => 'Alongside custom commissions, this is where ready-made products you can order directly will appear — the shop is gradually being expanded.',
            'breadcrumb_label_nl' => 'Shop',
            'breadcrumb_label_en' => 'Shop',
        ],
    ];

    /** @var array<string, array<string, string>> */
    private static array $cache = [];

    /**
     * @return array<string, string> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, lead_nl/en,
     *                                breadcrumb_label_nl/en — lead_* may be
     *                                ''. Templates must check 'state' !==
     *                                STATE_HIDDEN before rendering the
     *                                section at all; the content fields are
     *                                still populated (with DEFAULTS) even
     *                                when hidden, purely so a template that
     *                                forgets the check fails safe instead of
     *                                emitting empty markup.
     */
    public static function forSlug(string $pageSlug): array
    {
        if (isset(self::$cache[$pageSlug])) {
            return self::$cache[$pageSlug];
        }

        // A slug not in DEFAULTS is a page-builder-attached instance rather
        // than one of the originally hardcoded pages — it has no hardcoded
        // fallback copy (there is nothing to fall back TO), so an
        // unreachable database degrades to an empty section instead of
        // throwing. Its own DB row (created with placeholder text by
        // SectionRegistry::create()) is what actually renders in the normal
        // case.
        $defaults = self::DEFAULTS[$pageSlug] ?? self::emptyDefaults();

        $row = null;
        try {
            $row = (new PageHeroRepository())->findBySlug($pageSlug);
        } catch (\Throwable $e) {
            error_log('[PageHeroContent] falling back to defaults for "' . $pageSlug . '": ' . $e->getMessage());
        }

        if ($row === null) {
            return self::$cache[$pageSlug] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        if (!(bool) $row['is_active']) {
            // Intentionally hidden: NOT a fallback case. The content fields
            // are filled with defaults only as a defensive fallback for a
            // template that forgets to check 'state' — see forSlug() docblock.
            return self::$cache[$pageSlug] = $defaults + ['state' => self::STATE_HIDDEN];
        }

        $content = [
            'eyebrow_nl' => self::valueOrDefault($row['eyebrow_nl'] ?? null, $defaults['eyebrow_nl']),
            'title_nl' => self::valueOrDefault($row['title_nl'] ?? null, $defaults['title_nl']),
            'lead_nl' => (string) ($row['lead_nl'] ?? ''),
            'breadcrumb_label_nl' => self::valueOrDefault($row['breadcrumb_label_nl'] ?? null, $defaults['breadcrumb_label_nl']),
        ];

        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['lead_en'] = self::valueOrDefault($row['lead_en'] ?? null, $content['lead_nl']);
        $content['breadcrumb_label_en'] = self::valueOrDefault($row['breadcrumb_label_en'] ?? null, $content['breadcrumb_label_nl']);
        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$pageSlug] = $content;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultsForSlug(string $pageSlug): array
    {
        return self::DEFAULTS[$pageSlug] ?? [];
    }

    /**
     * Clears the in-process cache — used by the admin save handler right
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

    /**
     * @return array<string, string>
     */
    private static function emptyDefaults(): array
    {
        return [
            'eyebrow_nl' => '', 'eyebrow_en' => '',
            'title_nl' => '', 'title_en' => '',
            'lead_nl' => '', 'lead_en' => '',
            'breadcrumb_label_nl' => '', 'breadcrumb_label_en' => '',
        ];
    }
}

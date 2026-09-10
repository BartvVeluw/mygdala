<?php

namespace App\Service;

use App\Repository\StepListRepository;

/**
 * Content for the "Step list" section (`.process` > `.process-step`) — see
 * docs/CMS_CONTENT_AUDIT.md, proposed type #5. Same repeater architecture as
 * App\Service\FaqContent (read that class's docblock first — this mirrors it
 * item for item).
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) step list blocks that currently exist on the
 * site. Adding a new step list to an existing or new page only ever needs a
 * new SECTIONS entry + DEFAULTS entry here, plus the matching PHP loop on
 * that page's template — never a schema change.
 *
 * DEFAULTS is the fallback used whenever a section's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the exact steps that used to
 * be hardcoded.
 *
 * `is_active = false` on an *existing* section row is a different,
 * deliberate case: it means the site owner has intentionally hidden the
 * whole section (heading + steps), and must NOT fall back to the defaults —
 * that would make the "Actief" checkbox unable to actually hide anything.
 * forSection()'s returned `state` field is how a template tells the three
 * cases apart: STATE_FALLBACK (no row / DB unreachable — render the default
 * heading + steps), STATE_ACTIVE (row is active — render its own heading +
 * steps) and STATE_HIDDEN (row exists and is_active = false — render
 * nothing for this section).
 *
 * Once a section's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 items), even if that list is empty — an
 * individually hidden/deleted step must stay hidden, not fall back to the
 * defaults. Defaults only apply when the whole section is missing/
 * unreachable, never per missing/hidden item.
 *
 * Step numbers ("1", "2", ...) are deliberately NOT a content field: the
 * frontend numbers steps purely from their display order via a CSS counter
 * (`.process{ counter-reset: step; }` in assets/css/core.css), so
 * reordering/adding/removing steps in the admin renumbers them automatically.
 */
class StepListContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + items. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) step list sections and their
     * admin-facing labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'index:werkwijze' => [
            'page_slug' => 'index',
            'section_key' => 'werkwijze',
            'page_label' => 'Homepage',
            'section_label' => 'Werkwijze (stappenlijst)',
        ],
    ];

    private const DEFAULTS = [
        'index:werkwijze' => [
            'eyebrow_nl' => 'Werkwijze',
            'eyebrow_en' => 'Process',
            'title_nl' => 'Van idee naar eindproduct',
            'title_en' => 'From idea to finished piece',
            'items' => [
                [
                    'title_nl' => 'Contact & wens',
                    'title_en' => 'Get in touch',
                    'body_nl' => 'Je stuurt je idee, foto of voorbeeld via het contactformulier. Ik denk mee over wat mogelijk is.',
                    'body_en' => "Send your idea, a photo or an example through the contact form. I'll think along about what's possible.",
                ],
                [
                    'title_nl' => 'Ontwerp op maat',
                    'title_en' => 'Custom design',
                    'body_nl' => 'Samen bepalen we tekst, plaatsing, materiaal en formaat, tot het ontwerp helemaal klopt.',
                    'body_en' => 'Together we settle on text, placement, material and size, until the design feels exactly right.',
                ],
                [
                    'title_nl' => 'Graveren met precisie',
                    'title_en' => 'Precision engraving',
                    'body_nl' => 'Met de CO₂- en MOPA-laser breng ik de gravure nauwkeurig en zorgvuldig aan.',
                    'body_en' => 'Using the CO₂ and MOPA laser, I engrave the piece with care and precision.',
                ],
                [
                    'title_nl' => 'Ophalen of verzenden',
                    'title_en' => 'Pick up or delivery',
                    'body_nl' => 'Je product wordt afgewerkt en is klaar om op te halen in Nijmegen of te verzenden.',
                    'body_en' => 'Your piece is finished and ready for pickup in Nijmegen or for shipping.',
                ],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus
     *                                eyebrow_nl/en, title_nl/en, and
     *                                'items': list of
     *                                title_nl/en/body_nl/en. Templates must
     *                                check 'state' !== STATE_HIDDEN before
     *                                rendering the section at all; the
     *                                content fields are still populated
     *                                (with DEFAULTS) even when hidden,
     *                                purely so a template that forgets the
     *                                check fails safe instead of emitting
     *                                empty markup.
     */
    public static function forSection(string $pageSlug, string $sectionKey): array
    {
        $cacheKey = $pageSlug . ':' . $sectionKey;

        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        // A page-builder-attached instance not in DEFAULTS has no hardcoded
        // fallback copy — an unreachable database degrades to an empty step
        // list rather than throwing.
        $defaults = self::DEFAULTS[$cacheKey] ?? ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'items' => []];

        try {
            $repository = new StepListRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[StepListContent] falling back to defaults for "' . $cacheKey . '": ' . $e->getMessage());

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
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);

        try {
            $items = $repository->findItemsBySectionId((int) $row['id'], true);
        } catch (\Throwable $e) {
            error_log('[StepListContent] falling back to defaults for "' . $cacheKey . '" (items lookup failed): ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a section row that
        // exists and is active means the admin has deliberately curated its
        // steps, so an empty result is "all steps hidden/deleted", not
        // "missing data", and must not fall back to the defaults above.
        $content['items'] = array_map(static function (array $item): array {
            $titleNl = (string) $item['title_nl'];
            $bodyNl = (string) $item['body_nl'];

            return [
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
     * to pre-fill the section-heading form the first time a section is
     * edited.
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

<?php

namespace App\Service;

use App\Repository\FaqRepository;

/**
 * Content for the "FAQ list" section (`.faq-list` > `.faq-item` /
 * `<details>`) — see docs/CMS_CONTENT_AUDIT.md, proposed type #9. Same
 * repeater architecture as App\Service\FeatureGridContent (read that
 * class's docblock first — this mirrors it item for item).
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) FAQ blocks that currently exist on the site.
 * Adding a new FAQ block to an existing or new page only ever needs a new
 * SECTIONS entry + DEFAULTS entry here, plus the matching PHP loop on that
 * page's template — never a schema change (section_key is what lets a page
 * have more than one FAQ list without a schema rewrite).
 *
 * DEFAULTS is the fallback used whenever a section's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the exact questions that used
 * to be hardcoded.
 *
 * `is_active = false` on an *existing* section row is a different,
 * deliberate case: it means the site owner has intentionally hidden the
 * whole section (heading + questions), and must NOT fall back to the
 * defaults — that would make the "Actief" checkbox unable to actually hide
 * anything. forSection()'s returned `state` field is how a template tells
 * the three cases apart: STATE_FALLBACK (no row / DB unreachable — render
 * the default heading + questions), STATE_ACTIVE (row is active — render
 * its own heading + questions) and STATE_HIDDEN (row exists and
 * is_active = false — render nothing for this section).
 *
 * Once a section's row exists and is active, its *items* come strictly from
 * the database (only is_active = 1 items), even if that list is empty — an
 * individually hidden/deleted item must stay hidden, not fall back to the
 * defaults. Defaults only apply when the whole section is missing/
 * unreachable, never per missing/hidden item.
 */
class FaqContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own heading + items. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) FAQ sections and their admin-facing
     * labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'diensten:faq' => [
            'page_slug' => 'diensten',
            'section_key' => 'faq',
            'page_label' => 'Diensten',
            'section_label' => 'FAQ: Veelgestelde vragen',
        ],
    ];

    private const DEFAULTS = [
        'diensten:faq' => [
            'eyebrow_nl' => 'Veelgestelde vragen',
            'eyebrow_en' => 'Frequently asked questions',
            'title_nl' => 'Nog vragen?',
            'title_en' => 'Any questions?',
            'items' => [
                [
                    'question_nl' => 'Wat kost een gravure?',
                    'question_en' => 'What does an engraving cost?',
                    'answer_nl' => 'De prijs hangt af van materiaal, formaat, complexiteit van het ontwerp en het aantal stuks. Stuur je idee via het contactformulier, dan ontvang je een vrijblijvende offerte op maat.',
                    'answer_en' => "The price depends on the material, size, design complexity and quantity. Send your idea through the contact form and you'll receive a free, no-obligation quote.",
                ],
                [
                    'question_nl' => 'Kan ik zelf een ontwerp aanleveren?',
                    'question_en' => 'Can I submit my own design?',
                    'answer_nl' => 'Zeker. Deel je bestand, foto of logo via het contactformulier. Niet elk bestand is direct geschikt om te graveren — indien nodig pas ik het ontwerp aan zodat het goed uitkomt op het gekozen materiaal.',
                    'answer_en' => "Absolutely. Share your file, photo or logo through the contact form. Not every file is immediately suitable for engraving — if needed, I'll adjust the design so it comes out well on the chosen material.",
                ],
                [
                    'question_nl' => 'Hoe lang duurt een bestelling?',
                    'question_en' => 'How long does an order take?',
                    'answer_nl' => 'Dat hangt af van het ontwerp en de drukte op dat moment. Bij de offerte krijg je een indicatie van de levertijd, zodat je weet waar je aan toe bent.',
                    'answer_en' => "That depends on the design and current workload. You'll get a delivery estimate along with your quote, so you know what to expect.",
                ],
                [
                    'question_nl' => 'Kan ik mijn eigen product laten graveren?',
                    'question_en' => 'Can I have my own item engraved?',
                    'answer_nl' => 'In veel gevallen wel, bijvoorbeeld gereedschap of een persoonlijk voorwerp. Stuur een foto, de afmetingen en het materiaal (als bekend) mee, dan bekijk ik wat technisch mogelijk is.',
                    'answer_en' => "In many cases, yes — for example a tool or a personal item. Send a photo, the dimensions and the material (if known), and I'll look at what's technically possible.",
                ],
                [
                    'question_nl' => 'Kan ik het bestellen afhalen in Nijmegen?',
                    'question_en' => 'Can I pick up my order in Nijmegen?',
                    'answer_nl' => 'Ja, ophalen in Nijmegen is mogelijk. Verzenden kan ook — dit stemmen we af zodra je bestelling klaar is.',
                    'answer_en' => 'Yes, pickup in Nijmegen is possible. Shipping is also an option — we\'ll arrange this once your order is ready.',
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
     *                                question_nl/en/answer_nl/en. Templates
     *                                must check 'state' !== STATE_HIDDEN
     *                                before rendering the section at all;
     *                                the content fields are still populated
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
        // fallback copy — an unreachable database degrades to an empty FAQ
        // list rather than throwing.
        $defaults = self::DEFAULTS[$cacheKey] ?? ['eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '', 'items' => []];

        try {
            $repository = new FaqRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[FaqContent] falling back to defaults for "' . $cacheKey . '": ' . $e->getMessage());

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
            error_log('[FaqContent] falling back to defaults for "' . $cacheKey . '" (items lookup failed): ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        // Only is_active items are queried above, and whatever comes back —
        // including an empty list — is authoritative: a section row that
        // exists and is active means the admin has deliberately curated its
        // questions, so an empty result is "all questions hidden/deleted",
        // not "missing data", and must not fall back to the defaults above.
        $content['items'] = array_map(static function (array $item): array {
            $questionNl = (string) $item['question_nl'];
            $answerNl = (string) $item['answer_nl'];

            return [
                'question_nl' => $questionNl,
                'question_en' => self::valueOrDefault($item['question_en'] ?? null, $questionNl),
                'answer_nl' => $answerNl,
                'answer_en' => self::valueOrDefault($item['answer_en'] ?? null, $answerNl),
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

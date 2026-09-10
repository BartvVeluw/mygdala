<?php

namespace App\Service;

use App\Repository\TextImageSplitRepository;
use App\Service\Media\BlockImage;

/**
 * Content for the "Text + image split" section (`.service-detail__head`,
 * text column + image(s) column) — see docs/CMS_CONTENT_AUDIT.md, proposed
 * type #7. Same repeater architecture as App\Service\FaqContent (read that
 * class's docblock first — this mirrors it item for item), but with TWO
 * repeaters on one section instead of one: paragraphs and images.
 *
 * Not a generic page builder: SECTIONS below is the fixed, known list of
 * (page_slug, section_key) Text + image split blocks that currently exist
 * on the site — verified by inspecting every `.service-detail__head` usage
 * on the whole site before writing this schema (over-mij.php has exactly
 * two; diensten.php's `.service-detail__head` usages are the larger,
 * unrelated "Material/service detail" type with points-lists, not plain
 * text+image, and are out of scope). Adding a new block to an existing or
 * new page only ever needs a new SECTIONS entry + DEFAULTS entry here, plus
 * the matching PHP loop on that page's template — never a schema change
 * (section_key is what lets a page have more than one of these blocks
 * without a schema rewrite).
 *
 * `layout` ('image_left' | 'image_right') picks between two fixed markup
 * branches the template already has — it is the only "variant" field this
 * type has, and it is deliberately not a free layout/CSS configuration.
 *
 * Paragraphs are plain text (never raw HTML) rendered one per `<p>` by the
 * template. Whether the FIRST paragraph gets the `.lead` CSS class is a
 * presentation decision the TEMPLATE makes purely from whether the section
 * has a title (title_nl === '') — not a stored "is_lead" flag — because
 * that is exactly how the two current instances already differ (the
 * title-less "intro" section's first paragraph is `.lead`, the titled
 * "idee-naar-product" section's paragraph is plain).
 *
 * Images render via one of three fixed template branches purely based on
 * `count($images)`: exactly 1 -> `.hero__media-frame` (single framed
 * photo), exactly 2 -> `.service-detail__gallery` with the
 * `grid-template-columns:1fr 1fr` override, 3+ -> the gallery's own
 * default 3-col grid. No layout/markup info is ever stored per image.
 *
 * The button (button_label_nl/en + button_url) is fully optional and
 * all-or-nothing, same convention as CtaBandContent's secondary button: a
 * half-filled button (label without URL, or vice versa) is treated as "no
 * button", never rendered as a broken link.
 *
 * DEFAULTS is the fallback used whenever a section's row is missing, or the
 * database is unreachable, so the public site never breaks because of a CMS
 * content problem — it silently falls back to the exact content that used
 * to be hardcoded.
 *
 * `is_active = false` on an *existing* section row is a different,
 * deliberate case: it means the site owner has intentionally hidden the
 * whole section, and must NOT fall back to the defaults — that would make
 * the "Actief" checkbox unable to actually hide anything. forSection()'s
 * returned `state` field is how a template tells the three cases apart:
 * STATE_FALLBACK (no row / DB unreachable — render the default content),
 * STATE_ACTIVE (row is active — render its own content) and STATE_HIDDEN
 * (row exists and is_active = false — render nothing for this section).
 *
 * Once a section's row exists and is active, its paragraphs/images come
 * strictly from the database, even if that list is empty — an emptied-out
 * section must stay empty, not fall back to the defaults. Defaults only
 * apply when the whole section is missing/unreachable, never per
 * missing/emptied repeater.
 */
class TextImageSplitContent
{
    /** No row exists (or the row lookup failed) — rendering DEFAULTS. */
    public const STATE_FALLBACK = 'fallback';

    /** A row exists and is_active = true — rendering its own content. */
    public const STATE_ACTIVE = 'active';

    /** A row exists and is_active = false — an intentional hide; render nothing. */
    public const STATE_HIDDEN = 'hidden';

    /**
     * Known (page_slug, section_key) sections and their admin-facing
     * labels — the "Pages" list in admin/pages.php. Keyed by
     * "page_slug:section_key".
     */
    public const SECTIONS = [
        'over-mij:intro' => [
            'page_slug' => 'over-mij',
            'section_key' => 'intro',
            'page_label' => 'Over mij',
            'section_label' => 'Tekst + afbeelding: Intro',
        ],
        'over-mij:idee-naar-product' => [
            'page_slug' => 'over-mij',
            'section_key' => 'idee-naar-product',
            'page_label' => 'Over mij',
            'section_label' => 'Tekst + afbeelding: Van idee naar product',
        ],
    ];

    private const DEFAULTS = [
        'over-mij:intro' => [
            'layout' => 'image_right',
            'eyebrow_nl' => '',
            'eyebrow_en' => '',
            'title_nl' => '',
            'title_en' => '',
            'button_label_nl' => '',
            'button_label_en' => '',
            'button_url' => '',
            'paragraphs' => [
                [
                    'content_nl' => 'Achter Van Veluw Laserdesign sta ik: iemand met een grote liefde voor ontwerpen, maken en het uitwerken van een idee tot iets tastbaars.',
                    'content_en' => 'Behind Van Veluw Laserdesign is me: someone with a real love for designing, making, and turning an idea into something tangible.',
                ],
                [
                    'content_nl' => "Wat begon als plezier in creatief bezig zijn, is uitgegroeid tot werk waarin ontwerp en techniek samenkomen. Ik houd me niet alleen bezig met graveren, maar ook met het ontwerpen van de producten zelf — en dat creatieve proces vind ik minstens zo belangrijk als het eindresultaat.",
                    'content_en' => "What began as a creative hobby has grown into work where design and technique come together. I'm not just focused on engraving, but also on designing the products themselves — and I find that creative process just as important as the end result.",
                ],
                [
                    'content_nl' => 'Ik besteed veel tijd aan het uitdenken van vormen, het kiezen van materialen en het zoeken naar een uitstraling die klopt. Ik werk vooral met hout, metaal en acryl, en maak zowel kant-en-klare items als persoonlijk maatwerk — denk aan onderzetters, naambordjes, sleutelhangers en andere ontwerpen die met zorg worden opgebouwd en afgewerkt.',
                    'content_en' => 'I spend real time working out shapes, choosing materials, and finding a look that feels right. I mainly work with wood, metal and acrylic, making both ready-made items and personal custom pieces — think coasters, name signs, keyrings and other designs that are built up and finished with care.',
                ],
            ],
            'images' => [
                [
                    'image_path' => 'assets/images/hero-collage-a.webp',
                    'alt_nl' => 'MOPA-laser graveert een naam in een stalen hamer, met vonken',
                    'alt_en' => 'MOPA laser engraving a name into a steel hammer, sparks flying',
                ],
            ],
        ],
        'over-mij:idee-naar-product' => [
            'layout' => 'image_left',
            'eyebrow_nl' => "Waar het om draait",
            'eyebrow_en' => "What it's about",
            'title_nl' => 'Van idee naar zorgvuldig gemaakt product',
            'title_en' => 'From idea to carefully made product',
            'button_label_nl' => 'Vertel me over jouw idee',
            'button_label_en' => 'Tell me about your idea',
            'button_url' => 'contact.php',
            'paragraphs' => [
                [
                    'content_nl' => 'Van Veluw Laserdesign draait voor mij om meer dan het eindresultaat alleen. Het gaat ook om het proces: van idee naar ontwerp, en van ontwerp naar een product dat met zorg is gemaakt — in mijn werkplaats in Nijmegen.',
                    'content_en' => "For me, Van Veluw Laserdesign is about more than just the end result. It's also about the process: from idea to design, and from design to a product made with care — in my workshop in Nijmegen.",
                ],
            ],
            'images' => [
                [
                    'image_path' => 'assets/images/hero-collage-b.webp',
                    'alt_nl' => 'Gegraveerde hamer met tekst Van Leo Afblijven',
                    'alt_en' => 'Engraved hammer reading Van Leo Hands Off',
                ],
                [
                    'image_path' => 'assets/images/hero-collage-c.webp',
                    'alt_nl' => 'Set gereedschap gegraveerd met naam en logo',
                    'alt_en' => 'Set of tools engraved with a name and logo',
                ],
            ],
        ],
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
     * @return array<string, mixed> 'state' (one of STATE_*), plus layout,
     *                                eyebrow_nl/en, title_nl/en,
     *                                button_label_nl/en, button_url (all
     *                                three '' together when there is no
     *                                button), 'paragraphs': list of
     *                                content_nl/en, and 'images': list of
     *                                image_path/alt_nl/en. Templates must
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
        // fallback copy — an unreachable database degrades to an empty
        // section rather than throwing.
        $defaults = self::DEFAULTS[$cacheKey] ?? [
            'layout' => 'image_right',
            'eyebrow_nl' => '', 'eyebrow_en' => '', 'title_nl' => '', 'title_en' => '',
            'button_label_nl' => '', 'button_label_en' => '', 'button_url' => '',
            'paragraphs' => [], 'images' => [],
        ];

        try {
            $repository = new TextImageSplitRepository();
            $row = $repository->findBySlugAndKey($pageSlug, $sectionKey);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] falling back to defaults for "' . $cacheKey . '": ' . $e->getMessage());

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
            'layout' => in_array($row['layout'] ?? null, ['image_left', 'image_right'], true) ? $row['layout'] : 'image_right',
            'eyebrow_nl' => (string) ($row['eyebrow_nl'] ?? ''),
            'title_nl' => (string) ($row['title_nl'] ?? ''),
            'button_label_nl' => (string) ($row['button_label_nl'] ?? ''),
            'button_url' => (string) ($row['button_url'] ?? ''),
        ];
        $content['eyebrow_en'] = self::valueOrDefault($row['eyebrow_en'] ?? null, $content['eyebrow_nl']);
        $content['title_en'] = self::valueOrDefault($row['title_en'] ?? null, $content['title_nl']);
        $content['button_label_en'] = self::valueOrDefault($row['button_label_en'] ?? null, $content['button_label_nl']);

        // A button only renders when it has both a label and a URL — a
        // half-filled optional button would be broken/dead.
        if ($content['button_label_nl'] === '' || $content['button_url'] === '') {
            $content['button_label_nl'] = '';
            $content['button_label_en'] = '';
            $content['button_url'] = '';
        }

        try {
            $paragraphs = $repository->findParagraphsBySectionId((int) $row['id']);
            $images = $repository->findImagesBySectionId((int) $row['id']);
        } catch (\Throwable $e) {
            error_log('[TextImageSplitContent] falling back to defaults for "' . $cacheKey . '" (paragraphs/images lookup failed): ' . $e->getMessage());

            return self::$cache[$cacheKey] = $defaults + ['state' => self::STATE_FALLBACK];
        }

        // Whatever comes back — including an empty list — is authoritative
        // once the section row exists and is active: the admin has
        // deliberately curated this content, so an empty result means "all
        // paragraphs/images removed", not "missing data", and must not fall
        // back to the defaults above.
        $content['paragraphs'] = array_map(static function (array $paragraph): array {
            $contentNl = (string) $paragraph['content_nl'];

            return [
                'content_nl' => $contentNl,
                'content_en' => self::valueOrDefault($paragraph['content_en'] ?? null, $contentNl),
            ];
        }, $paragraphs);

        // Media Library first, the row's own image_path second, and the
        // block's own alt text over the media item's default — all of that
        // lives in App\Service\Media\BlockImage so the integrated blocks
        // share one answer. It also returns width/height, null whenever the
        // library does not know them.
        $content['images'] = array_map(
            static fn (array $image): array => BlockImage::fromRow($image),
            $images
        );

        $content['state'] = self::STATE_ACTIVE;

        return self::$cache[$cacheKey] = $content;
    }

    /**
     * Section-level fields only (no 'paragraphs'/'images') — used by the
     * admin edit page to pre-fill the section form the first time a
     * section is edited.
     *
     * @return array<string, string>
     */
    public static function defaultsForSection(string $pageSlug, string $sectionKey): array
    {
        $defaults = self::DEFAULTS[$pageSlug . ':' . $sectionKey] ?? null;
        if ($defaults === null) {
            return [];
        }

        unset($defaults['paragraphs'], $defaults['images']);

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

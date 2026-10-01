<?php

namespace App\Service\Blocks;

use App\Repository\CtaBandRepository;
use App\Service\CtaBandContent;
use App\Service\Routing\LinkChoice;

require_once dirname(__DIR__, 3) . '/partials/section-cta-band.php';

/**
 * The eyebrow/H2/lead/button(s) band that closes several pages. Repeatable
 * per instance since phase 2. Since CTA 2.0 it has no, one or two buttons,
 * each with a destination of the shared kind (LinkChoice), a layout (aligned,
 * lead width, full width) and an optional background picture with an overlay
 * and a text panel (CtaBandContent, CONTENT-BLOCKS.md). Its words are stored
 * per website language in block_translations (BlockLocalization).
 */
final class CtaBandBlock extends BlockDefinition implements InspectsContent
{
    public function type(): string
    {
        return 'cta_band';
    }

    public function meta(): array
    {
        return [
            'label' => 'Oproep met knop',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Een opvallend kader met een korte oproep en een of twee knoppen, om de bezoeker naar de volgende stap te sturen.';
    }

    public function category(): string
    {
        return BlockCategories::ACTION;
    }

    public function icon(): string
    {
        return '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6.5 10.5h8"/><rect x="6.5" y="13" width="6" height="2.5" rx="1.25"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::BAND];
    }

    public function useCases(): array
    {
        return [
            'onderaan een pagina naar contact sturen',
            'een offerte of afspraak laten aanvragen',
        ];
    }

    /**
     * The words of the band, per website language; the destinations are the
     * same in every language and stay in cta_bands. The lengths are the ones
     * the editor always allowed. A button label is required only while its
     * button has a destination, which the endpoint checks itself
     * (api/admin/update-cta-band.php): a band without a button has none.
     */
    public function translatableFields(): array
    {
        return [
            'cta_bands' => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('lead', 500),
                TranslatableField::plain('primary_label', 150),
                TranslatableField::plain('secondary_label', 150),
            ],
        ];
    }

    /**
     * What the site search finds this block by (BlockDefinition::searchFields()).
     */
    public function searchFields(): array
    {
        return [
            'cta_bands' => [
                'eyebrow' => BlockSearchRole::TEXT,
                'title' => BlockSearchRole::HEADING,
                'lead' => BlockSearchRole::TEXT,
                'primary_label' => BlockSearchRole::NONE,
                'secondary_label' => BlockSearchRole::NONE,
            ],
        ];
    }

    public function styles(): array
    {
        // The shared picture rules first (Responsive Media 2.0).
        return ['assets/css/responsive-media.css', 'assets/css/blocks/cta-band.css'];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new CtaBandRepository();
        $repository->upsertSection($pageSlug, $key, [
            // A newly added band must not assume this site's routes. It used
            // to start out pointing at /contact.php, a page that exists only
            // on the installation this CMS grew out of — anywhere else that
            // is a 404 waiting for someone to notice. A new band starts with
            // one button, so the placeholder needs a destination: the site
            // root is the one URL every installation answers, and it is
            // obviously a value to change.
            'primary_url' => '/',
            'primary_link_type' => LinkChoice::URL,
            'secondary_url' => '',
            'is_active' => true,
        ]);

        $id = (int) $repository->findBySlugAndKey($pageSlug, $key)['id'];

        // Generic starting words in the website's default language, the
        // language every other language falls back to until it is written.
        BlockLocalization::save('cta_bands', $id, BlockLocalization::defaultLanguage(), [
            'eyebrow' => 'Nieuw',
            'title' => 'Nieuwe sectie — pas deze titel aan',
            'primary_label' => 'Meer informatie',
        ]);

        return [$id, $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new CtaBandRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = CtaBandContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === CtaBandContent::STATE_HIDDEN) {
            return;
        }

        render_section_cta_band($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        return [
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'primary_label' => $samples->localized('button'),
            'primary_url' => BlockSamples::LINK,
            'secondary_label' => $samples->localized('button_secondary'),
            'secondary_url' => BlockSamples::LINK,
        ] + CtaBandContent::presentation([]);
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_cta_band($content);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('cta_bands', $this->sectionId($pageSection), 'title');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('cta-band', $pageSection);
    }

    public function clearCache(): void
    {
        CtaBandContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'cta_bands';
    }

    /**
     * Content: a title or a button (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = CtaBandContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== CtaBandContent::STATE_ACTIVE) {
            return true;
        }

        return (string) ($content['title'] ?? '') !== '' || (string) ($content['primary_label'] ?? '') !== '';
    }

    /**
     * Every part of Extra vormgeving. A chosen background replaces the
     * section's own surface (a full-width band's colour); the card of a card
     * band keeps its own. The minimum height stays on the box that carries
     * the layers and the effect sits under the words, so the two combine.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section();
    }
}

<?php

namespace App\Service\Blocks;

use App\Repository\CtaBandRepository;
use App\Service\CtaBandContent;

require_once dirname(__DIR__, 3) . '/partials/section-cta-band.php';

/**
 * The eyebrow/H2/lead/button(s) band that closes several pages. Repeatable
 * per instance since phase 2; its secondary button stays optional. Its words
 * are stored per website language in block_translations (BlockLocalization).
 */
final class CtaBandBlock extends BlockDefinition
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
     * The words of the band, per website language; the two URLs are the same
     * in every language and stay in cta_bands. The lengths are the ones the
     * editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'cta_bands' => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('lead', 500),
                TranslatableField::plain('primary_label', 150)->required(),
                TranslatableField::plain('secondary_label', 150),
            ],
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new CtaBandRepository();
        $repository->upsertSection($pageSlug, $key, [
            // A newly added band must not assume this site's routes. It used
            // to start out pointing at /contact.php, a page that exists only
            // on the installation this CMS grew out of — anywhere else that
            // is a 404 waiting for someone to notice. The block always
            // renders its primary button, so the placeholder needs a
            // destination: the site root is the one URL every installation
            // answers, and it is obviously a value to change.
            'primary_url' => '/',
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
        ];
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
}

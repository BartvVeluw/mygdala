<?php

namespace App\Service\Blocks;

use App\Repository\CtaBandRepository;
use App\Service\CtaBandContent;

require_once dirname(__DIR__, 3) . '/partials/section-cta-band.php';

/**
 * The eyebrow/H2/lead/button(s) band that closes several pages. Repeatable
 * per instance since phase 2; its secondary button stays optional.
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

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new CtaBandRepository();
        $repository->upsertSection($pageSlug, $key, [
            'eyebrow_nl' => 'Nieuw',
            'eyebrow_en' => '',
            'title_nl' => 'Nieuwe sectie — pas deze titel aan',
            'title_en' => '',
            'lead_nl' => '',
            'lead_en' => '',
            // A newly added band must not assume this site's routes. It used
            // to start out pointing at /contact.php, a page that exists only
            // on the installation this CMS grew out of — anywhere else that
            // is a 404 waiting for someone to notice. The block always
            // renders its primary button, so the placeholder needs a
            // destination: the site root is the one URL every installation
            // answers, and it is obviously a value to change.
            'primary_label_nl' => 'Meer informatie',
            'primary_label_en' => '',
            'primary_url' => '/',
            'secondary_label_nl' => '',
            'secondary_label_en' => '',
            'secondary_url' => '',
            'is_active' => true,
        ]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
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

    public function instanceTitle(array $pageSection): string
    {
        return (string) (CtaBandContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
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

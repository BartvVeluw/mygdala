<?php

namespace App\Service\Blocks;

use App\Repository\FeatureGridRepository;
use App\Service\FeatureGridContent;

require_once dirname(__DIR__, 3) . '/partials/section-feature-grid.php';

/**
 * A grid of icon + title + text cards. Its items are child rows of the grid,
 * so deleting an instance takes them with it through ON DELETE CASCADE.
 */
final class FeatureGridBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'feature_grid';
    }

    public function meta(): array
    {
        return [
            'label' => 'Kenmerken in kaartjes',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Kaartjes naast elkaar, elk met een pictogram, een korte titel en een zin. Voor het opsommen van voordelen of eigenschappen.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<rect x="3" y="5" width="5.5" height="14" rx="1.5"/><rect x="9.25" y="5" width="5.5" height="14" rx="1.5"/><rect x="15.5" y="5" width="5.5" height="14" rx="1.5"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::COLUMNS];
    }

    public function useCases(): array
    {
        return [
            'waarom klanten voor je kiezen',
            'de eigenschappen van een dienst',
            'een kort overzicht van wat je doet',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new FeatureGridRepository();
        $repository->upsertGrid($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new FeatureGridRepository())->deleteGrid($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = FeatureGridContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === FeatureGridContent::STATE_HIDDEN) {
            return;
        }

        render_section_feature_grid($content, $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (FeatureGridContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('feature-grid', $pageSection);
    }

    public function clearCache(): void
    {
        FeatureGridContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'feature_grids';
    }
}

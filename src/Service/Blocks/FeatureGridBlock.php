<?php

namespace App\Service\Blocks;

use App\Repository\FeatureGridRepository;
use App\Service\FeatureGridContent;

require_once dirname(__DIR__, 3) . '/partials/section-feature-grid.php';

/**
 * A grid of icon + title + text cards. Its items are child rows of the grid,
 * so deleting an instance takes them with it through ON DELETE CASCADE. The
 * words of the grid and of every card are stored per website language in
 * block_translations (BlockLocalization), each card's on its own row.
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

    /**
     * The heading on the grid's row and the title and text on each card's
     * row, per website language; a card's icon is the same in every language
     * and stays in feature_grid_items. What the editor always required in
     * Dutch is required in the default language (the lead never was); the
     * lengths are the ones the editor always allowed. A grid without a
     * heading of its own (FeatureGridContent::SECTIONS, has_heading) never
     * has its heading validated or saved, so the required eyebrow and title
     * do not apply to it.
     */
    public function translatableFields(): array
    {
        return [
            'feature_grids' => [
                TranslatableField::plain('eyebrow', 150)->required(),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('lead', 500),
            ],
            'feature_grid_items' => [
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('body', 500)->required(),
            ],
        ];
    }

    public function childTables(): array
    {
        return ['feature_grid_items' => ['parent' => 'feature_grids', 'column' => 'feature_grid_id']];
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
        if ($content['state'] !== FeatureGridContent::STATE_ACTIVE) {
            return;
        }

        render_section_feature_grid($content, $revealGroup);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $items = [];
        foreach (array_slice(array_keys(FeatureGridContent::ICON_KEYS), 0, 3) as $index => $iconKey) {
            $items[] = [
                'icon_key' => $iconKey,
                'title' => $samples->localizedItem('item', $index),
                'body' => $samples->localizedItem('item_body', $index),
            ];
        }

        return [
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_feature_grid($content, $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('feature_grids', $this->sectionId($pageSection), 'title');
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

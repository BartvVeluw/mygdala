<?php

namespace App\Service\Blocks;

use App\Repository\StepListRepository;
use App\Service\StepListContent;

require_once dirname(__DIR__, 3) . '/partials/section-step-list.php';

/**
 * A numbered "zo werkt het" list. Its steps are child rows of the section, so
 * deleting an instance takes them with it through ON DELETE CASCADE. The
 * words of the section and of every step are stored per website language in
 * block_translations (BlockLocalization), each step's on its own row.
 */
final class StepListBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'step_list';
    }

    public function meta(): array
    {
        return [
            'label' => 'Stappenplan',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Genummerde stappen onder elkaar die uitleggen hoe iets gaat. De nummering komt er vanzelf bij.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<circle cx="5.5" cy="6.5" r="2.2"/><circle cx="5.5" cy="12" r="2.2"/><circle cx="5.5" cy="17.5" r="2.2"/><path d="M10.5 6.5h10"/><path d="M10.5 12h10"/><path d="M10.5 17.5h7"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::NUMBERS];
    }

    public function useCases(): array
    {
        return [
            'hoe een bestelling verloopt',
            'de werkwijze van een dienst',
            'een stappenplan of routebeschrijving',
        ];
    }

    /**
     * The heading on the section's row and the title and description on each
     * step's row, per website language. What the editor always required in
     * Dutch is required in the default language; the lengths are the ones
     * the editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'step_list_sections' => [
                TranslatableField::plain('eyebrow', 150)->required(),
                TranslatableField::plain('title', 255)->required(),
            ],
            'step_list_items' => [
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('body', 1000)->required(),
            ],
        ];
    }

    public function childTables(): array
    {
        return ['step_list_items' => ['parent' => 'step_list_sections', 'column' => 'step_list_section_id']];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new StepListRepository();
        $repository->upsertSection($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new StepListRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = StepListContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] !== StepListContent::STATE_ACTIVE) {
            return;
        }

        render_section_step_list($content, $revealGroup);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $items = [];
        foreach (range(0, 3) as $index) {
            $items[] = [
                'title' => $samples->localizedItem('step', $index),
                'body' => $samples->localizedItem('item_body', $index),
            ];
        }

        return [
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_step_list($content, $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('step_list_sections', $this->sectionId($pageSection), 'title');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('step-list', $pageSection);
    }

    public function clearCache(): void
    {
        StepListContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'step_list_sections';
    }
}

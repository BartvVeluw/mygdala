<?php

namespace App\Service\Blocks;

use App\Repository\StepListRepository;
use App\Service\StepListContent;

require_once dirname(__DIR__, 3) . '/partials/section-step-list.php';

/**
 * A numbered "zo werkt het" list. Its steps are child rows of the section, so
 * deleting an instance takes them with it through ON DELETE CASCADE.
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
        if ($content['state'] === StepListContent::STATE_HIDDEN) {
            return;
        }

        render_section_step_list($content);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (StepListContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
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

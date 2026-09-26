<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\SpacerRepository;
use App\Service\SpacerContent;

require_once dirname(__DIR__, 3) . '/partials/section-spacer.php';

/**
 * Witruimte: deliberate vertical room between two blocks, in one of a few
 * heights (App\Service\SpacerContent::SIZES). No words, so nothing of it is
 * in block_translations; the room is the same in every language.
 *
 * Content Blocks Polish 1. The heights are steps of the spacing scale
 * (assets/css/blocks/spacer.css) rather than a number an editor types, so a
 * page cannot drift into spacing the theme does not have.
 */
final class SpacerBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'spacer';
    }

    public function meta(): array
    {
        return [
            'label' => 'Witruimte',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Extra ruimte tussen twee blokken, in een vaste hoogte die je kiest. Er staat verder niets in: de bezoeker ziet alleen de ruimte.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<path d="M4 4h16"/><path d="M4 20h16"/><path d="M12 7v10"/><path d="M9 10l3-3 3 3"/><path d="M9 14l3 3 3-3"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::SPACE];
    }

    public function useCases(): array
    {
        return [
            'meer lucht tussen twee blokken die te dicht op elkaar staan',
            'een rustpunt voor een nieuw onderdeel van de pagina',
        ];
    }

    public function translatableFields(): array
    {
        return [];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new SpacerRepository();
        $repository->createSection($pageSlug, $key, SpacerContent::DEFAULT_SIZE);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new SpacerRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = SpacerContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === SpacerContent::STATE_HIDDEN) {
            return;
        }

        render_section_spacer($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        return ['state' => SpacerContent::STATE_ACTIVE, 'size' => SpacerContent::DEFAULT_SIZE];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_spacer($content);
    }

    /** The height, so two spacers on one page are told apart in the page builder. */
    public function instanceTitle(array $pageSection): string
    {
        $row = (new SpacerRepository())->findById($this->sectionId($pageSection));

        return $row === null ? '' : SpacerContent::SIZES[SpacerContent::size((string) ($row['size'] ?? ''))];
    }

    public function styles(): array
    {
        return ['assets/css/blocks/spacer.css'];
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('spacer', $pageSection);
    }

    public function clearCache(): void
    {
        SpacerContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'spacers';
    }
}

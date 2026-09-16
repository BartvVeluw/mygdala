<?php

namespace App\Service\Blocks;

use App\Repository\FaqRepository;
use App\Service\FaqContent;

require_once dirname(__DIR__, 3) . '/partials/section-faq.php';

/**
 * A question/answer accordion. The questions are child rows of the section,
 * so deleting an instance takes them with it through ON DELETE CASCADE.
 */
final class FaqBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'faq';
    }

    public function meta(): array
    {
        return [
            'label' => 'Veelgestelde vragen',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Veelgestelde vragen onder elkaar. De bezoeker klikt een vraag open om het antwoord te lezen.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.6a2.5 2.5 0 1 1 2.9 2.45V14"/><circle cx="12.4" cy="17" r="0.85" fill="currentColor" stroke="none"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::ROWS];
    }

    public function useCases(): array
    {
        return [
            'vragen over levering en retour',
            'twijfels wegnemen voor het bestellen',
            'uitleg die niet in de lopende tekst past',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new FaqRepository();
        $repository->upsertSection($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new FaqRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = FaqContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] !== FaqContent::STATE_ACTIVE) {
            return;
        }

        render_section_faq($content);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $items = [];
        foreach (range(0, 3) as $index) {
            $items[] = [
                ...$samples->itemFields('question', 'question', $index),
                ...$samples->itemFields('answer', 'answer', $index),
            ];
        }

        return [
            ...$samples->fields('eyebrow', 'eyebrow'),
            ...$samples->fields('title', 'title'),
            'items' => $items,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_faq($content);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (FaqContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('faq', $pageSection);
    }

    public function clearCache(): void
    {
        FaqContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'faq_sections';
    }
}

<?php

namespace App\Service\Blocks;

use App\Repository\StatStripRepository;
use App\Service\StatStripContent;

require_once dirname(__DIR__, 3) . '/partials/section-stat-strip.php';

/**
 * A row of number + caption figures. It carries no heading of its own, so the
 * page builder shows it by its type label alone. The figures are child rows of
 * the strip, so deleting an instance takes them with it through ON DELETE
 * CASCADE; the words of every figure are stored per website language in
 * block_translations (BlockLocalization), each figure's on its own row. The
 * strip itself has no words.
 */
final class StatStripBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'stat_strip';
    }

    public function meta(): array
    {
        return [
            'label' => 'Cijferbalk',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Een smalle band met een paar sprekende cijfers, elk met een woord eronder. Kort, en meteen zichtbaar.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<path d="M3 20h18"/><path d="M6 20V10"/><path d="M12 20V4.5"/><path d="M18 20v-6.5"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::FIGURES];
    }

    public function useCases(): array
    {
        return [
            'jaren ervaring of aantal projecten',
            'levertijd en garantie in cijfers',
        ];
    }

    /**
     * The number and the caption on each figure's row, per website language;
     * stat_strips has no words, so it declares nothing. What the editor
     * always required in Dutch is required in the default language; the
     * lengths are the ones the editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'stat_strip_items' => [
                TranslatableField::plain('primary_text', 100)->required(),
                TranslatableField::plain('secondary_text', 150)->required(),
            ],
        ];
    }

    public function childTables(): array
    {
        return ['stat_strip_items' => ['parent' => 'stat_strips', 'column' => 'stat_strip_id']];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new StatStripRepository();
        $repository->upsertStrip($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new StatStripRepository())->deleteStrip($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = StatStripContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] !== StatStripContent::STATE_ACTIVE) {
            return;
        }

        render_section_stat_strip($content, $revealGroup);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $items = [];
        foreach (range(0, 3) as $index) {
            $items[] = [
                'primary_text' => $samples->localizedItem('figure', $index),
                'secondary_text' => $samples->localizedItem('figure_caption', $index),
            ];
        }

        return ['items' => $items];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_stat_strip($content, $revealGroup);
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('stat-strip', $pageSection);
    }

    public function clearCache(): void
    {
        StatStripContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'stat_strips';
    }
}

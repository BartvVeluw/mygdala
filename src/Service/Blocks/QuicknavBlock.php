<?php

namespace App\Service\Blocks;

use App\Service\DetailSectionContent;

require_once dirname(__DIR__, 3) . '/partials/section-quicknav.php';

/**
 * The Diensten quicknav: a link to every Detailsectie with an anchor on the
 * SAME page, derived at render time (phase 3) rather than listed anywhere, so
 * there is nothing to keep in sync by hand and nothing to edit.
 */
final class QuicknavBlock extends FixedBlockDefinition
{
    public function type(): string
    {
        return 'quicknav';
    }

    public function meta(): array
    {
        return [
            'label' => 'Snelnavigatie',
            'manual_add' => false,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => ['diensten'],
            'deletable' => false,
            'kind' => self::KIND_FUNCTIONAL,
            'note' => 'Toont automatisch een link naar elke Detailsectie met een anker op deze pagina — er valt niets handmatig gelijk te houden.',
        ];
    }

    public function description(): string
    {
        return 'Een rij snelkoppelingen naar de detailsecties op deze pagina. De links stelt de site zelf samen uit de secties die eronder staan.';
    }

    public function category(): string
    {
        return BlockCategories::ACTION;
    }

    public function icon(): string
    {
        return '<rect x="2.5" y="9" width="5.5" height="6" rx="3"/><rect x="9.25" y="9" width="5.5" height="6" rx="3"/><rect x="16" y="9" width="5.5" height="6" rx="3"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::CHIPS];
    }

    public function useCases(): array
    {
        return [
            'bovenaan een lange pagina met veel detailsecties',
        ];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        render_section_quicknav(DetailSectionContent::navItemsForPage($this->pageSlug($pageSection)));
    }

    /** Links to the sections a page would have; in a preview they go nowhere. */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $items = [];
        foreach (range(0, 3) as $index) {
            $items[] = ['anchor' => 'voorbeeld-' . ($index + 1), ...$samples->itemFields('label', 'item', $index)];
        }

        return ['items' => $items];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_quicknav($content['items']);
    }
}

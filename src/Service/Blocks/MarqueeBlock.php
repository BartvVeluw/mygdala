<?php

namespace App\Service\Blocks;

use App\Repository\MarqueeRepository;
use App\Service\MarqueeContent;

require_once dirname(__DIR__, 3) . '/partials/section-marquee.php';

/**
 * The scrolling word band. It carries no heading, so the page builder shows
 * it by its type label alone.
 */
final class MarqueeBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'marquee';
    }

    public function meta(): array
    {
        return [
            'label' => 'Woordenband',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Een dunne band met korte woorden die langzaam voorbijschuiven. Decoratief, als accent tussen twee secties.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<rect x="2.5" y="8.5" width="19" height="7" rx="2"/><path d="M6 12h3"/><path d="M11 12h3"/><path d="M16 12h2"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::TICKER];
    }

    public function useCases(): array
    {
        return [
            'materialen of technieken opsommen',
            'een visueel accent tussen twee blokken',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new MarqueeRepository();
        $repository->upsertSection($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new MarqueeRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = MarqueeContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === MarqueeContent::STATE_HIDDEN) {
            return;
        }

        render_section_marquee($content);
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('marquee', $pageSection);
    }

    public function styles(): array
    {
        return ['assets/css/blocks/marquee.css'];
    }

    public function scripts(): array
    {
        return ['assets/js/blocks/marquee.js'];
    }

    public function clearCache(): void
    {
        MarqueeContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'marquee_sections';
    }
}

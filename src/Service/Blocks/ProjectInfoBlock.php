<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\ProjectInfoRepository;
use App\Service\PortfolioUrls;
use App\Service\ProjectInfoContent;

require_once dirname(__DIR__, 3) . '/partials/section-project-info.php';

/**
 * Projectinformatie: a Portfolio project's own head — main picture,
 * categories, title, short text, intro, description and, if wanted, its extra
 * photos — as a block, so a project with the free layout
 * (App\Service\PortfolioProjectLayout::FREE) can put it wherever the editor
 * likes among its other blocks (Product & Portfolio Content Pages 1.0).
 *
 * WHY A BLOCK, AND ONLY THIS ONE. The free layout hands the whole project page
 * to the content blocks, but the project's picture, title and text are the
 * project's data, edited on the project; nobody should retype them into a
 * Tekstblok. This block shows them LIVE from the project whose page it is on
 * (App\Service\ProjectInfoContent::projectFor()) and stores only how: where
 * the picture sits and whether the photos follow. It is offered only on a
 * project's page (`owners`), where there is a project to show.
 *
 * It renders only while its project has the free layout: every other layout
 * already prints the project's head above the blocks (portfolio-detail.php),
 * and the block then stays in the list, visible to the editor, doing nothing.
 *
 * No words of its own, so nothing in block_translations; the project's words
 * are in the request's language through the project itself.
 */
final class ProjectInfoBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'project_info';
    }

    public function meta(): array
    {
        return [
            'label' => 'Projectinformatie',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'deletable' => true,
            'owners' => [\App\Service\PortfolioContentOwner::KIND],
        ];
    }

    public function description(): string
    {
        return 'De foto, titel, tekst en categorieën van dit project, altijd zoals ze bij het project zelf staan. Voor een project met vrije indeling: zet het blok waar de projectgegevens moeten komen.';
    }

    public function category(): string
    {
        return BlockCategories::PORTFOLIO;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="8" height="10" rx="1.5"/><path d="M14 6h7"/><path d="M14 10h7"/><path d="M14 14h5"/><path d="M3 18h18"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::IMAGE_LEFT];
    }

    public function useCases(): array
    {
        return [
            'een projectpagina met vrije indeling',
            'de projectfoto en -tekst tussen andere blokken',
        ];
    }

    public function translatableFields(): array
    {
        return [];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ProjectInfoRepository();
        $repository->createSection($pageSlug, $key, ProjectInfoContent::DEFAULT_POSITION, true);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ProjectInfoRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = ProjectInfoContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] !== ProjectInfoContent::STATE_ACTIVE) {
            return;
        }

        // Only in the free layout: with the picture left, right or on top the
        // page already prints the project's head, and a second one would be
        // the same title and picture twice.
        $project = ProjectInfoContent::projectFor($this->pageSlug($pageSection));
        if ($project === null || \App\Service\PortfolioProjectLayout::forItem($project) !== \App\Service\PortfolioProjectLayout::FREE) {
            return;
        }

        render_section_project_info($content, $project, PortfolioUrls::backLink(), $revealGroup);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        return [
            'content' => ['state' => ProjectInfoContent::STATE_ACTIVE, 'image_position' => ProjectInfoContent::DEFAULT_POSITION, 'show_gallery' => false],
            'project' => [
                'image_path' => $image['image_path'],
                'alt' => $image['alt'],
                'title' => $samples->localized('title'),
                'subtitle' => $samples->localized('short_title'),
                'categories' => [
                    ['slug' => 'sample-1', 'name' => $samples->localizedItem('category', 0)],
                    ['slug' => 'sample-2', 'name' => $samples->localizedItem('category', 1)],
                ],
                'intro' => $samples->localizedRichText(),
                'description' => '',
                'images' => [],
            ],
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_project_info($content['content'], $content['project'], null, $revealGroup);
    }

    /** Where the picture sits, so the collapsed row says something useful. */
    public function instanceTitle(array $pageSection): string
    {
        $row = (new ProjectInfoRepository())->findById($this->sectionId($pageSection));

        return $row === null ? '' : \App\Service\Language\AdminTranslator::trans(
            'block_project_info.position_' . ProjectInfoContent::position((string) ($row['image_position'] ?? ''))
        );
    }

    public function scripts(): array
    {
        return ['assets/js/lightbox.js'];
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('project-info', $pageSection);
    }

    public function clearCache(): void
    {
        ProjectInfoContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'portfolio_project_infos';
    }
}

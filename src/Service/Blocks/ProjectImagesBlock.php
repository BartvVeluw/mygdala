<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\ContentOwners\ContentPages;
use App\Service\PageContent;
use App\Service\PortfolioContentOwner;
use App\Service\PortfolioGalleryContent;

require_once dirname(__DIR__, 3) . '/partials/section-project-images.php';

/**
 * Projectafbeeldingen (Portfolio 3.0): WHERE a project's extra photos stand
 * on its project page, and whether they show — as one block in the project's
 * Pagina-inhoud list, so they can be dragged between the other blocks and
 * hidden like any of them.
 *
 * THE BLOCK OWNS NO PICTURE. The photos are the project's
 * (portfolio_item_images, edited in the project's own gallery on the Project
 * tab); this block reads them live from the project whose content page it is
 * on, at every render, in the project's own order. No media picker, no copy of
 * a media reference, no synchronisation: a photo added to the project shows
 * here at once, a photo taken off it is gone at once, and hiding or moving
 * the block changes nothing about the photos or the Media Library's usage
 * (which points at the project).
 *
 * A FIXED BLOCK (FixedBlockDefinition): no content row, not added from the
 * picker, never deleted, at most one. App\Service\ProjectImagesPlacement puts
 * it on every project when the project is made; db/migrations/20261014100000
 * put it on every project that existed. Its page_sections.section_id is the
 * project's id, so UNIQUE(section_type, section_id) lets the database itself
 * refuse a second one for the same project. Hidden is the way to leave the
 * photos off the page.
 *
 * Only on a project's content page (`owners`). The project's fixed head —
 * main picture, title, text — is not a block: the project page prints it
 * above every block, laid out by the project layout
 * (App\Service\PortfolioProjectLayout), which has nothing to do with this
 * block.
 *
 * No words of its own, so nothing to translate and nothing to search: the
 * photos' alt texts stay out of the site search, as every alt text does
 * (SEARCH.md).
 */
final class ProjectImagesBlock extends FixedBlockDefinition
{
    public const TYPE = 'project_images';

    public function type(): string
    {
        return self::TYPE;
    }

    public function meta(): array
    {
        return [
            'label' => 'Projectafbeeldingen',
            'manual_add' => false,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'deletable' => false,
            'owners' => [PortfolioContentOwner::KIND],
            'kind' => self::KIND_DYNAMIC,
            'note' => 'De extra afbeeldingen van dit project, in de volgorde van het project. Welke afbeeldingen het zijn, kies je op het tabblad Project; hier bepaal je waar ze op de projectpagina staan en of ze zichtbaar zijn.',
        ];
    }

    public function description(): string
    {
        return 'De extra afbeeldingen van het project, altijd zoals ze bij het project zelf staan. Versleep het blok om ze ergens anders op de projectpagina te zetten, of verberg het.';
    }

    public function category(): string
    {
        return BlockCategories::PORTFOLIO;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="8" height="7" rx="1.5"/><rect x="13" y="4" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="7" rx="1.5"/><path d="M13 19h8"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::GALLERY];
    }

    public function useCases(): array
    {
        return [
            'de extra beelden van een project',
            'projectbeelden tussen andere blokken',
        ];
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $project = self::projectOn($this->pageSlug($pageSection));
        if ($project === null) {
            return;
        }

        render_section_project_images(
            $project['images'],
            self::projectName($project),
            self::lightboxGroup((int) $project['id']),
            $revealGroup
        );
    }

    /**
     * The project whose content page this is, as its project page reads it
     * now, or null: not a project's page, the project gone, hidden or
     * without its project page — then there is no page to show photos on.
     *
     * @return array<string, mixed>|null PortfolioGalleryContent::itemForDetailPage()
     */
    public static function projectOn(string $pageSlug): ?array
    {
        $page = PageContent::forContentKey($pageSlug);
        $owner = $page === null ? null : ContentPages::ownerOf($page);

        if ($owner === null || $owner['owner']->kind() !== PortfolioContentOwner::KIND) {
            return null;
        }

        return PortfolioGalleryContent::itemForDetailPageById($owner['id']);
    }

    /**
     * The lightbox group the project's head and this block share, so the main
     * picture and the photos are ONE sequence wherever the block stands
     * (assets/js/lightbox.js, a named group).
     */
    public static function lightboxGroup(int $projectId): string
    {
        return 'project-' . $projectId;
    }

    /** @param array<string, mixed> $project */
    private static function projectName(array $project): string
    {
        return trim((string) $project['title']) !== ''
            ? (string) $project['title']
            : \App\Service\Language\SiteText::pick(['nl' => 'Project', 'en' => 'Project']);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();
        $photo = ['image_path' => $image['image_path'], 'thumbnail_path' => $image['image_path'], 'alt' => $image['alt']];

        return [
            'images' => [$photo, $photo, $photo],
            'name' => $samples->localized('title'),
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_project_images($content['images'], $content['name'], 'project-sample', $revealGroup);
    }

    public function scripts(): array
    {
        return ['assets/js/lightbox.js'];
    }

    /**
     * Background, borders, spacing and the calm effects; nothing that moves
     * over a grid of pictures a visitor clicks (the gallery block's choice).
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }
}

<?php

namespace App\Service\Blocks;

use App\Repository\TextImageSplitRepository;
use App\Service\TextImageSplitContent;

require_once dirname(__DIR__, 3) . '/partials/section-text-image-split.php';

/**
 * Text beside an image (or a small gallery), image left or right. One of the
 * three block types with uploaded files of its own, so it cleans those up
 * before its rows disappear.
 *
 * It is also the only block that cares about `$tightTop`: directly under a
 * hero it drops its own top spacing so the two do not stack twice.
 */
final class TextImageSplitBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'text_image_split';
    }

    public function meta(): array
    {
        return [
            'label' => 'Tekst met afbeelding',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Tekst en een afbeelding naast elkaar. Je kiest zelf aan welke kant het beeld staat.';
    }

    public function category(): string
    {
        return BlockCategories::MEDIA;
    }

    public function icon(): string
    {
        return '<rect x="3" y="5" width="8" height="14" rx="1.5"/><path d="M13.5 8h7.5"/><path d="M13.5 12h7.5"/><path d="M13.5 16h4.5"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::IMAGE_LEFT];
    }

    public function useCases(): array
    {
        return [
            'een introductie met foto',
            'een dienst toelichten',
            'over-ons content',
        ];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new TextImageSplitRepository();
        $repository->upsertSection($pageSlug, $key, ['is_active' => true, 'layout' => 'image_right']);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    /**
     * Deliberately nothing.
     *
     * This block's images are Media Library items now, and a media item is
     * SHARED: the same photo may be on three other pages and in the site's
     * branding. Removing an instance of this block removes its references
     * (deleteContent() below), never the files behind them. Deleting a file
     * is the Media Library's own decision, and it refuses while anything
     * still uses it — see App\Service\Media\MediaService::delete().
     *
     * Left as an explicit override rather than falling through to the base
     * class, so that "why does this block not clean up its images?" has an
     * answer in the file somebody would look in.
     */
    public function deleteFiles(array $pageSection): void
    {
    }

    public function deleteContent(array $pageSection): void
    {
        (new TextImageSplitRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = TextImageSplitContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === TextImageSplitContent::STATE_HIDDEN) {
            return;
        }

        render_section_text_image_split($content, $tightTop, $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return (string) (TextImageSplitContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection))['title_nl'] ?? '');
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('text-image-split', $pageSection);
    }

    public function clearCache(): void
    {
        TextImageSplitContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'text_image_splits';
    }
}

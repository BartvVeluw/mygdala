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
 *
 * The words of the section, of every paragraph and of every image's alt text
 * are stored per website language in block_translations (BlockLocalization),
 * each paragraph's and each image's on its own row.
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

    /**
     * The eyebrow, title and button label on the section's row, the text on
     * each paragraph's row and the alt text on each image's row, per website
     * language; the layout, the button URL, the media and the order are the
     * same in every language and stay in their tables. What the editor always
     * required in Dutch is required in the default language (only a
     * paragraph's text; the heading, the button and an alt text never were);
     * the lengths are the ones the editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'text_image_splits' => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('button_label', 150),
            ],
            'text_image_split_paragraphs' => [
                TranslatableField::plain('content', 1000)->required(),
            ],
            'text_image_split_images' => [
                TranslatableField::plain('alt', 255),
            ],
        ];
    }

    public function childTables(): array
    {
        return [
            'text_image_split_paragraphs' => ['parent' => 'text_image_splits', 'column' => 'text_image_split_id'],
            'text_image_split_images' => ['parent' => 'text_image_splits', 'column' => 'text_image_split_id'],
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
        if ($content['state'] !== TextImageSplitContent::STATE_ACTIVE) {
            return;
        }

        render_section_text_image_split($content, $tightTop, $revealGroup);
    }

    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        return [
            'layout' => 'image_right',
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'button_label' => $samples->localized('button'),
            'button_url' => BlockSamples::LINK,
            'paragraphs' => [
                ['content' => $samples->localized('lead')],
                ['content' => $samples->localized('body')],
            ],
            'images' => [[
                'image_path' => $image['image_path'],
                'alt' => $image['alt'],
                'width' => $image['width'],
                'height' => $image['height'],
                'media_id' => null,
            ]],
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_text_image_split($content, false, $revealGroup);
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('text_image_splits', $this->sectionId($pageSection), 'title');
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

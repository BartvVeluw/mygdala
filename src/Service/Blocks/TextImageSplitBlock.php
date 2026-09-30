<?php

namespace App\Service\Blocks;

use App\Repository\TextImageSplitRepository;
use App\Service\TextImageSplitContent;

require_once dirname(__DIR__, 3) . '/partials/section-text-image-split.php';

/**
 * A list of items, each a rich text beside at most one picture, with its own
 * side, share of the row, picture height and focus point (Tekst met
 * afbeelding 2.0, App\Service\TextImageSplitContent). Items of one block sit
 * closer together than two blocks do; that is the reason to have several.
 *
 * It is also the only block that cares about `$tightTop`: directly under a
 * hero it drops its own top spacing so the two do not stack twice.
 *
 * The words are stored per website language in block_translations
 * (BlockLocalization): the block's own optional title and lead above all its
 * items on the block row, and each item's on its own row. What the block row
 * holds besides that is whether the block shows.
 */
final class TextImageSplitBlock extends BlockDefinition implements InspectsContent
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
        return 'Tekst en een afbeelding naast elkaar, één of meer keer onder elkaar. Per item kies je de kant, de breedte, de hoogte en het focuspunt van het beeld.';
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
     * The block's own heading above all its items — an optional title and an
     * optional lead — and every item's words: the eyebrow, title, rich body,
     * button label and the picture's own alt text, per website language. The
     * picture, the layout, the button URL and the order are the same in every
     * language and stay in the items table. Nothing is required on its own:
     * the block needs no heading, and an item needs text (an eyebrow, a
     * title, a body or a whole button) or a picture, which the editor's
     * endpoint checks as a whole. The body is as long as the Tekstblok's may
     * be. (The block's own row owned words before Tekst met afbeelding 2.0 as
     * well; db/migrations/20260924100000 moved every one of them onto an item,
     * so a title here starts empty on every existing block.)
     */
    public function translatableFields(): array
    {
        return [
            'text_image_splits' => [
                TranslatableField::plain('title', 255),
                TranslatableField::plain('lead', 500),
            ],
            'text_image_split_items' => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255),
                TranslatableField::rich('body', 50000),
                TranslatableField::plain('button_label', 150),
                TranslatableField::plain('alt', 255),
            ],
        ];
    }

    /**
     * The items. The two child tables of the block before 2.0,
     * text_image_split_paragraphs and text_image_split_images, still cascade
     * from the block row but own no words any more: db/migrations/20260924100000
     * moved their rows and words onto items, and nothing writes them
     * (Tests\Install\BlockTranslationSchemaTest keeps them empty).
     */
    public function childTables(): array
    {
        return [
            'text_image_split_items' => ['parent' => 'text_image_splits', 'column' => 'text_image_split_id'],
        ];
    }

    public function styles(): array
    {
        // The shared picture rules first (Responsive Media 2.0).
        return ['assets/css/responsive-media.css', 'assets/css/blocks/text-image-split.css'];
    }

    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new TextImageSplitRepository();
        $repository->upsertSection($pageSlug, $key, ['is_active' => true]);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    /**
     * Deliberately nothing.
     *
     * This block's pictures are Media Library items, and a media item is
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
        $image = $samples->image() + ['media_id' => null];
        $item = static fn (string $side, string $column, string $height): array => [
            'image_side' => $side,
            'image_column' => $column,
            'image_height' => $height,
        ];

        return [
            'title' => $samples->localized('short_title'),
            'lead' => $samples->localized('lead'),
            'items' => [
                $item('right', '50', 'medium') + [
                    'eyebrow' => $samples->localized('eyebrow'),
                    'title' => $samples->localized('title'),
                    'body' => $samples->localizedRichText(),
                    'button_label' => $samples->localized('button'),
                    'button_url' => BlockSamples::LINK,
                    'image' => $image,
                ],
                $item('left', '25', 'small') + [
                    'eyebrow' => '',
                    'title' => '',
                    'body' => '<p>' . htmlspecialchars($samples->localized('body'), ENT_NOQUOTES, 'UTF-8') . '</p>',
                    'button_label' => '',
                    'button_url' => '',
                    'image' => $image,
                ],
            ],
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_text_image_split($content, false, $revealGroup);
    }

    /** The block's own title, else its first item's: the name the page builder shows for this block. */
    public function instanceTitle(array $pageSection): string
    {
        $title = BlockLocalization::name('text_image_splits', $this->sectionId($pageSection), 'title');
        if ($title !== '') {
            return $title;
        }

        foreach ((new TextImageSplitRepository())->findItemsBySectionId($this->sectionId($pageSection)) as $item) {
            $title = BlockLocalization::name('text_image_split_items', (int) $item['id'], 'title');
            if ($title !== '') {
                return $title;
            }
        }

        return '';
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

    /**
     * Content: at least one item; its partial shows nothing without one (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = TextImageSplitContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== TextImageSplitContent::STATE_ACTIVE) {
            return true;
        }

        return $content['items'] !== [];
    }

    /**
     * Background, lines and room, and the effects that stand still (glow,
     * pattern) only: falling sparks would move behind the pictures, where they
     * compete with the pictures and the things to click.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\ReviewsRepository;
use App\Service\ReviewsContent;

require_once dirname(__DIR__, 3) . '/partials/section-reviews.php';

/**
 * Reviews: experiences of customers that an editor types in by hand, shown as
 * cards, one calm quote after another, one featured review or a carousel
 * (CONTENT-BLOCKS.md, "Reviews").
 *
 * Every review and every choice belongs to the instance
 * (App\Service\ReviewsContent); the words — the heading, the button label,
 * each review's text, name, description and source label — are stored per
 * website language in block_translations. A review's picture is a Media
 * Library item, so removing the block removes references and never a file
 * (deleteFiles() keeps its empty default).
 *
 * The block is manual on purpose. A later, optional Reviews module could keep
 * reviews centrally and hand this same partial the same review shape
 * (ReviewsContent::review()); nothing of that exists yet.
 */
final class ReviewsBlock extends BlockDefinition implements InspectsContent
{
    public function type(): string
    {
        return 'reviews';
    }

    public function meta(): array
    {
        return [
            'label' => 'Reviews',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Laat ervaringen van klanten zien als stijlvolle reviewkaarten, een grote quote of een carrousel. Met optioneel sterren, een portret, een datum en een bron.';
    }

    public function category(): string
    {
        return BlockCategories::CONTENT;
    }

    public function icon(): string
    {
        return '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H10l-4.5 4v-4A2.5 2.5 0 0 1 4 13.5z"/><path d="M12 6.4l.97 1.97 2.17.31-1.57 1.53.37 2.16L12 11.35l-1.94 1.02.37-2.16-1.57-1.53 2.17-.31z"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::HEADING, BlockPreview::COLUMNS];
    }

    public function useCases(): array
    {
        return [
            'ervaringen van tevreden klanten',
            'een testimonial als rustige, grote quote',
            'reviews bij een product of project',
            'beoordelingen met sterren en een bron',
        ];
    }

    /**
     * The heading and the button label of the block and, per review, its
     * text, name, description and source label, per website language. Only
     * a review's text is required (in the default language); everything else
     * is optional. What is the same in every language — the choices, a
     * review's stars, date, picture and source address, the button's
     * destination — stays in the two tables.
     */
    public function translatableFields(): array
    {
        return [
            ReviewsContent::TABLE => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255),
                TranslatableField::plain('lead', 500),
                TranslatableField::plain('button_label', 150),
            ],
            ReviewsContent::ITEMS => [
                TranslatableField::plain('body', 1500)->required(),
                TranslatableField::plain('name', 150),
                TranslatableField::plain('role', 150),
                TranslatableField::plain('source_label', 150),
            ],
        ];
    }

    /**
     * What the site search finds this block by (BlockDefinition::searchFields()).
     */
    public function searchFields(): array
    {
        return [
            'review_blocks' => [
                'eyebrow' => BlockSearchRole::TEXT,
                'title' => BlockSearchRole::HEADING,
                'lead' => BlockSearchRole::TEXT,
                'button_label' => BlockSearchRole::NONE,
            ],
            'review_block_items' => [
                'body' => BlockSearchRole::TEXT,
                'name' => BlockSearchRole::TEXT,
                'role' => BlockSearchRole::TEXT,
                'source_label' => BlockSearchRole::NONE,
            ],
        ];
    }

    public function childTables(): array
    {
        return [ReviewsContent::ITEMS => ['parent' => ReviewsContent::TABLE, 'column' => 'review_block_id']];
    }

    /**
     * A new block has no reviews and every choice at its default: it renders
     * nothing until it has a review. No starting words: a review is somebody
     * else's words, and a sample review on a page would be a review nobody
     * gave.
     */
    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new ReviewsRepository();
        $repository->createSection($pageSlug, $key);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new ReviewsRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = ReviewsContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === ReviewsContent::STATE_HIDDEN) {
            return;
        }

        render_section_reviews($content, $revealGroup);
    }

    /**
     * Three sample reviews as cards: one with five stars, a name and a
     * description, one with four stars and a name, one with only its words —
     * so the library shows how a review without extras looks — and the
     * optional button under them. No date: its
     * machine-readable form reads like a phone number to
     * BlockSampleContractTest, and a sample needs none.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $review = static fn (int $index, int $rating, string $name, string $role): array => ReviewsContent::review([
            'text' => $samples->localizedItem('review', $index),
            'name' => $name,
            'role' => $role,
            'rating' => $rating > 0 ? (string) $rating : '',
        ]);

        return [
            'state' => ReviewsContent::STATE_ACTIVE,
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
        ] + ReviewsContent::settings([]) + [
            'reviews' => [
                $review(0, 5, $samples->localizedItem('reviewer', 0), $samples->localizedItem('reviewer_role', 0)),
                $review(1, 4, $samples->localizedItem('reviewer', 1), ''),
                $review(2, 0, '', ''),
            ],
            'featured' => 0,
            'button' => ['href' => BlockSamples::LINK, 'label' => $samples->localized('button')],
            'button_style' => null,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_reviews($content, $revealGroup);
    }

    /** The block's own title, else the first reviewer's name, so two blocks on one page are told apart. */
    public function instanceTitle(array $pageSection): string
    {
        $blockId = $this->sectionId($pageSection);
        $title = BlockLocalization::name(ReviewsContent::TABLE, $blockId, 'title');
        if ($title !== '') {
            return $title;
        }

        foreach ((new ReviewsRepository())->findItemsByBlockId($blockId) as $item) {
            $name = BlockLocalization::name(ReviewsContent::ITEMS, (int) $item['id'], 'name');
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    public function styles(): array
    {
        // The shared picture rules first (Responsive Media), as every block
        // that prints a picture through partials/responsive-image.php.
        return ['assets/css/responsive-media.css', 'assets/css/blocks/reviews.css'];
    }

    /**
     * The carousel's arrows. Asked for per block type, like every block
     * script; on a page whose Reviews are not a carousel it finds nothing and
     * does nothing.
     */
    public function scripts(): array
    {
        return ['assets/js/blocks/reviews.js'];
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('reviews', $pageSection);
    }

    public function clearCache(): void
    {
        ReviewsContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return ReviewsContent::TABLE;
    }

    /**
     * Content: at least one review (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = ReviewsContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== ReviewsContent::STATE_ACTIVE) {
            return true;
        }

        return ($content['reviews'] ?? []) !== [];
    }

    /**
     * Every part of "Extra vormgeving", every effect included: the sparks
     * suit a calm quote or a featured review, and the cards are opaque, so
     * nothing moves behind a review's words. Behind the carousel the sparks
     * are left out by its stylesheet (reviews.css): a moving strip with moving
     * dots behind it is one movement too many.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section();
    }
}

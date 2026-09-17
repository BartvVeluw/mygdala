<?php

namespace App\Service\Blocks;

use App\Repository\PageHeroRepository;
use App\Service\PageHeroContent;

require_once dirname(__DIR__, 3) . '/partials/section-page-hero.php';

/**
 * The ordinary page hero: the H1, and optionally an eyebrow, a lead and an
 * image from the Media Library behind them, placed and sized by three
 * closed choices (App\Service\PageHeroContent). One per page, addressed by
 * page_slug (it predates repeatable instances and there is no second hero to
 * tell it apart from), and denied on the homepage, which has its own richer
 * HomepageHeroBlock.
 *
 * The image belongs to the library, not to this block, so deleting a hero
 * removes only the reference and deleteFiles() keeps its empty default
 * (MEDIA.md, "Een nieuw blok aansluiten"). Its words are stored per website
 * language in block_translations (BlockLocalization).
 */
final class PageHeroBlock extends BlockDefinition
{
    /**
     * The per-page `<h1>` line-wrap width — a purely cosmetic value that was
     * hand-tuned per page template and never CMS content (see
     * partials/section-page-hero.php). Pages not listed render without the
     * inline style.
     */
    private const TITLE_MAX_WIDTH = [
        'shop' => '22ch',
        'diensten' => '18ch',
        'portfolio' => '20ch',
        'over-mij' => '16ch',
        'contact' => '18ch',
    ];

    public function type(): string
    {
        return 'page_hero';
    }

    public function meta(): array
    {
        return [
            'label' => 'Paginakop',
            'manual_add' => true,
            'allow_multiple' => false,
            'max_instances' => 1,
            'allowed_pages' => null,
            'denied_pages' => ['index'],
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'De kop van een gewone pagina: de paginatitel, met naar keuze een bovenschrift, een korte inleiding en een afbeelding op de achtergrond. Hiermee begint een pagina normaal gesproken.';
    }

    public function category(): string
    {
        return BlockCategories::HERO;
    }

    public function icon(): string
    {
        return '<rect x="3" y="4" width="18" height="7.5" rx="1.5"/><path d="M6 15.5h12"/><path d="M6 19h8"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::PAGE_TITLE];
    }

    public function useCases(): array
    {
        return [
            'de titel en inleiding van een pagina',
            'het begin van een nieuwe pagina',
        ];
    }

    /**
     * The words of the header, per website language; the image and the three
     * choices are the same in every language and stay in page_heroes. The
     * lengths are the ones the editor always allowed.
     */
    public function translatableFields(): array
    {
        return [
            'page_heroes' => [
                TranslatableField::plain('eyebrow', 150),
                TranslatableField::plain('title', 255)->required(),
                TranslatableField::plain('lead', 500),
            ],
        ];
    }

    public function create(string $pageSlug): array
    {
        $repository = new PageHeroRepository();
        $repository->upsert($pageSlug, PageHeroContent::startingValues() + ['is_active' => true]);

        $id = (int) $repository->findBySlug($pageSlug)['id'];

        // Generic starting words in the website's default language, the
        // language every other language falls back to until it is written.
        BlockLocalization::save('page_heroes', $id, BlockLocalization::defaultLanguage(), PageHeroContent::startingWords());

        return [$id, null];
    }

    public function deleteContent(array $pageSection): void
    {
        (new PageHeroRepository())->deleteBySlug($this->pageSlug($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $pageSlug = $this->pageSlug($pageSection);

        $content = PageHeroContent::forSlug($pageSlug);
        if ($content['state'] !== PageHeroContent::STATE_ACTIVE) {
            return;
        }

        render_section_page_hero($content, self::TITLE_MAX_WIDTH[$pageSlug] ?? null);
    }

    /**
     * With a picture behind the text: the header's richest form, and the one
     * whose veil and spacing are hardest to imagine from a description. The
     * choices stay at their defaults, like a header that was just added.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();

        return [
            'eyebrow' => $samples->localized('eyebrow'),
            'title' => $samples->localized('title'),
            'lead' => $samples->localized('lead'),
            'media_id' => null,
            'image_path' => $image['image_path'],
            'image_alt' => $image['alt'],
            'image_width' => $image['width'],
            'image_height' => $image['height'],
            'content_position' => PageHeroContent::POSITION_LEFT,
            'title_size' => PageHeroContent::SIZE_NORMAL,
            'text_size' => PageHeroContent::SIZE_NORMAL,
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_page_hero($content);
    }

    /**
     * Only the choices an editor makes. The header itself — .page-hero,
     * .eyebrow, .lead — stays in core.css, because the shop, cart, checkout,
     * blog and legal templates print the same header by hand.
     */
    public function styles(): array
    {
        return ['assets/css/blocks/page-hero.css'];
    }

    public function instanceTitle(array $pageSection): string
    {
        return BlockLocalization::name('page_heroes', $this->sectionId($pageSection), 'title');
    }

    public function tightensFollowingBlock(): bool
    {
        return true;
    }

    public function editUrl(array $pageSection): ?string
    {
        return '/admin/page-hero.php?slug=' . urlencode($this->pageSlug($pageSection));
    }

    public function clearCache(): void
    {
        PageHeroContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'page_heroes';
    }
}

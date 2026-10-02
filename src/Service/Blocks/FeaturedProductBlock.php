<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Repository\FeaturedProductRepository;
use App\Service\FeaturedProductContent;
use App\Service\ProductGalleryLightbox;
use App\Service\ProductPurchasePath;
use App\Service\ShopLocalization;

require_once dirname(__DIR__, 3) . '/partials/section-featured-product.php';

/**
 * Uitgelicht product: one product of the Shop on an ordinary page, in a
 * promotional layout — its pictures beside its name, price, text and
 * variants, the way to put it in the cart when the product is sold directly,
 * and a button to its own product page (CONTENT-BLOCKS.md, "Uitgelicht
 * product").
 *
 * The product is chosen by id and read live at every render
 * (App\Service\FeaturedProductContent): nothing of it is copied into the
 * block, so a new price, picture or sold-out variant shows by itself. What the
 * block stores is only how it shows the product, plus two optional words per
 * language (an intro and the button's own label).
 *
 * Belongs to the Shop module: with the Shop off the type is not registered, so
 * it is neither offered nor rendered, and a placed block keeps its row until
 * the Shop is back (MODULES.md, "Blokken van een uitgeschakelde module").
 */
final class FeaturedProductBlock extends BlockDefinition implements InspectsContent
{
    public function type(): string
    {
        return 'featured_product';
    }

    public function meta(): array
    {
        return [
            'label' => 'Uitgelicht product',
            'manual_add' => true,
            'allow_multiple' => true,
            'max_instances' => null,
            'allowed_pages' => null,
            'deletable' => true,
        ];
    }

    public function description(): string
    {
        return 'Eén product uit de webshop groot in beeld, met foto\'s, naam, prijs en tekst, en naar keuze de knop om het meteen in de winkelwagen te leggen. Het product zelf beheer je bij Producten.';
    }

    public function category(): string
    {
        return BlockCategories::SHOP;
    }

    public function icon(): string
    {
        return '<rect x="2.5" y="4.5" width="9" height="15" rx="1.5"/><path d="M14.5 7.5h7M14.5 11h5M14.5 14.5h4"/><path d="M5 15l2.2-2.6 1.6 1.8 1.2-1.2"/>';
    }

    public function preview(): array
    {
        return [BlockPreview::IMAGE_LEFT];
    }

    public function useCases(): array
    {
        return [
            'een nieuw of populair product op de homepage',
            'een product bij een verhaal of een blogachtige pagina',
            'direct bestellen zonder eerst naar de shop te gaan',
        ];
    }

    public function translatableFields(): array
    {
        return [
            'featured_products' => [
                TranslatableField::plain(FeaturedProductContent::INTRO, 500),
                TranslatableField::plain(FeaturedProductContent::LINK_LABEL, 150),
            ],
        ];
    }

    /**
     * What the site search finds this block by (BlockDefinition::searchFields()).
     */
    public function searchFields(): array
    {
        return [
            'featured_products' => [
                'intro' => BlockSearchRole::TEXT,
                'link_label' => BlockSearchRole::NONE,
            ],
        ];
    }

    /**
     * A new block has no product yet and every other choice at its default:
     * it renders nothing until a product is chosen, so it can be placed first
     * and set up later.
     */
    public function create(string $pageSlug): array
    {
        $key = self::newSectionKey();

        $repository = new FeaturedProductRepository();
        $repository->createSection($pageSlug, $key);

        return [(int) $repository->findBySlugAndKey($pageSlug, $key)['id'], $key];
    }

    public function deleteContent(array $pageSection): void
    {
        (new FeaturedProductRepository())->deleteSection($this->sectionId($pageSection));
    }

    public function render(array $pageSection, bool $tightTop, string $revealGroup): void
    {
        $content = FeaturedProductContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));
        if ($content['state'] === FeaturedProductContent::STATE_HIDDEN) {
            return;
        }

        render_section_featured_product($content, $revealGroup);
    }

    /**
     * The block as a visitor sees it, with the library's sample words and
     * picture and no real product: "Alleen product bekijken" and no price, so
     * the preview names no price and has no cart to put anything in
     * (PAGE-EDITOR.md, "Wat een voorbeeld nooit doet"). The product is the
     * payload shape App\Service\ProductDetail gives, held in memory.
     */
    public function sampleContent(BlockSamples $samples): ?array
    {
        $image = $samples->image();
        $name = $samples->localized('short_title');
        $description = '<p>' . htmlspecialchars($samples->localized('body'), ENT_QUOTES, 'UTF-8') . '</p>';

        return [
            'state' => FeaturedProductContent::STATE_ACTIVE,
        ] + FeaturedProductContent::settings(['ordering' => 'view', 'show_price' => false]) + [
            'intro' => $samples->localized('lead'),
            // The block's own default label, as a new block shows it.
            'link_label' => FeaturedProductContent::defaultLinkLabel(),
            'product' => [
                'id' => 0,
                'name' => $name,
                'description' => $description,
                'inquiry' => false,
                'purchase_path' => ProductPurchasePath::CART,
                'personalization_required' => false,
                'offers_cart' => false,
                'order_questions' => [],
                'specifications' => [],
                'gallery_transition' => 'fade',
                'url' => BlockSamples::LINK,
                'payload' => [
                    'id' => 0,
                    'slug' => '',
                    'price' => null,
                    'image_path' => $image['image_path'],
                    'name' => $name,
                    'description' => $description,
                    'images' => [[
                        'id' => 0,
                        'image_path' => $image['image_path'],
                        'alt_text' => $image['alt'],
                        'width' => $image['width'],
                        'height' => $image['height'],
                        'is_primary' => true,
                    ]],
                    'options' => [],
                    'variants' => [],
                    'has_variants' => false,
                    'stock_tracked' => false,
                    'sold_out' => false,
                    'max_quantity' => null,
                    'inquiry' => false,
                ],
            ],
        ];
    }

    public function renderSample(array $content, string $revealGroup): void
    {
        render_section_featured_product($content, $revealGroup);
    }

    /** The chosen product's name, so two of these blocks on one page are told apart in the page builder. */
    public function instanceTitle(array $pageSection): string
    {
        $row = (new FeaturedProductRepository())->findById($this->sectionId($pageSection));
        $productId = (int) ($row['product_id'] ?? 0);

        return $productId > 0 ? ShopLocalization::productName($productId) : '';
    }

    /**
     * The product page's own frontend, asked for by this block: the gallery
     * controller first (shop.js hands it the pictures), then the Shop's script,
     * which runs the same product code here as on product.php. The block's
     * own stylesheet is only its layout.
     */
    public function styles(): array
    {
        return ['assets/css/shop/shop.css', 'assets/css/shop/featured-product.css'];
    }

    public function scripts(): array
    {
        $scripts = ['assets/js/shop/product-gallery.js', 'assets/js/shop/shop.js'];

        // The site's one lightbox, only when the Shop switched the gallery's
        // lightbox on (App\Service\ProductGalleryLightbox); off asks for
        // exactly what the block always did.
        return ProductGalleryLightbox::enabled() ? ['assets/js/lightbox.js', ...$scripts] : $scripts;
    }

    public function editUrl(array $pageSection): ?string
    {
        return $this->sectionEditUrl('featured-product', $pageSection);
    }

    public function clearCache(): void
    {
        FeaturedProductContent::clearCache();
    }

    public function contentTable(): ?string
    {
        return 'featured_products';
    }

    /**
     * Content: a product to show (a dynamic block is judged on its source) (InspectsContent, SectionRegistry::isEmpty()).
     */
    public function hasContent(array $pageSection): bool
    {
        $content = FeaturedProductContent::forSection($this->pageSlug($pageSection), $this->sectionKey($pageSection));

        if ($content['state'] !== FeaturedProductContent::STATE_ACTIVE) {
            return true;
        }

        return is_array($content['product'] ?? null);
    }

    /**
     * Background, lines and room, and the effects that stand still (glow,
     * pattern) only: falling sparks would move behind a product with its
     * options and buy button, where they compete with the pictures and the
     * things to click.
     */
    public function appearanceSupport(): AppearanceSupport
    {
        return AppearanceSupport::section(['glow', 'pattern']);
    }
}

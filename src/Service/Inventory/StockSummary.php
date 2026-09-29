<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Service\Language\AdminTranslator;

/**
 * A product's stock in one short line for the CMS product overview
 * (admin/products.php, MODULES.md "Voorraad"): what an owner needs to see
 * without opening the product, and nothing that pretends more than the
 * inventory model says.
 *
 * Read from App\Service\Inventory\ProductStock, the one resolver of a
 * product's units; this class counts nothing of its own.
 *
 *   op aanvraag                  "Niet direct te bestellen": a product that is
 *                                never put in a cart has no stock to show
 *   no variants, not tracked     "Onbeperkt"
 *   no variants, tracked         "12 op voorraad" or "Uitverkocht"
 *   variants, not tracked        "4 varianten · Onbeperkt"
 *   variants, tracked            "4 varianten · 23 op voorraad", or with one
 *                                or more sold out "4 varianten · 1 uitverkocht",
 *                                or "Uitverkocht · 4 varianten" when none is left
 *   variants, none active        "Geen actieve varianten"
 *
 * Only ACTIVE variants count: an inactive one is never sold. Adding the
 * variants' stock up is honest here because every variant is its own unit
 * (ProductStock): the total is the number of pieces there are, not a number
 * any one variant can sell.
 *
 * The tone is one of the CMS's existing badge tones (admin.css
 * .admin-badge--*), so a sold-out product reads as an error and a partly
 * sold-out one as a warning without a colour of its own.
 */
final class StockSummary
{
    public const TONE_OK = 'paid';
    public const TONE_WARNING = 'draft';
    public const TONE_ERROR = 'failed';
    public const TONE_MUTED = 'muted';
    public const TONE_INFO = 'info';

    /**
     * @param int      $variants      active variants, 0 for a product without variants
     * @param int      $soldOut       active variants (or the product itself) with nothing left
     * @param int|null $inStock       pieces left over all units, null when unlimited or not applicable
     */
    private function __construct(
        public readonly string $kind,
        public readonly string $tone,
        public readonly int $variants,
        public readonly int $soldOut,
        public readonly ?int $inStock
    ) {
    }

    public static function for(ProductStock $stock, bool $inquiry): self
    {
        if ($inquiry) {
            return new self('inquiry', self::TONE_MUTED, 0, 0, null);
        }

        if (!$stock->hasVariants()) {
            $unit = $stock->productUnit();
            if (!$unit->tracked) {
                return new self('unlimited', self::TONE_MUTED, 0, 0, null);
            }

            return $unit->isSoldOut()
                ? new self('sold_out', self::TONE_ERROR, 0, 1, 0)
                : new self('in_stock', self::TONE_OK, 0, 0, $unit->available());
        }

        $active = array_filter(
            $stock->variantUnits(),
            static fn (StockUnit $unit, int $variantId): bool => $stock->isActiveVariant($variantId),
            ARRAY_FILTER_USE_BOTH
        );
        $count = count($active);

        if ($count === 0) {
            return new self('no_active_variants', self::TONE_MUTED, 0, 0, null);
        }
        if (!$stock->tracked) {
            return new self('variants_unlimited', self::TONE_MUTED, $count, 0, null);
        }

        $soldOut = 0;
        $total = 0;
        foreach ($active as $unit) {
            $soldOut += $unit->isSoldOut() ? 1 : 0;
            $total += (int) $unit->available();
        }

        if ($soldOut === $count) {
            return new self('variants_sold_out', self::TONE_ERROR, $count, $soldOut, 0);
        }
        if ($soldOut > 0) {
            return new self('variants_partly_sold_out', self::TONE_WARNING, $count, $soldOut, $total);
        }

        return new self('variants_in_stock', self::TONE_OK, $count, 0, $total);
    }

    /** The line as the CMS shows it, in the CMS language. */
    public function text(): string
    {
        $variants = $this->variants === 1
            ? AdminTranslator::trans('shop.stock_summary.variant_one')
            : AdminTranslator::trans('shop.stock_summary.variants', ['count' => (string) $this->variants]);
        $inStock = AdminTranslator::trans('shop.stock_summary.in_stock', ['count' => (string) $this->inStock]);

        return match ($this->kind) {
            'inquiry' => AdminTranslator::trans('shop.stock_summary.inquiry'),
            'unlimited' => AdminTranslator::trans('shop.stock_summary.unlimited'),
            'sold_out' => AdminTranslator::trans('shop.stock_summary.sold_out'),
            'in_stock' => $inStock,
            'no_active_variants' => AdminTranslator::trans('shop.stock_summary.no_active_variants'),
            'variants_unlimited' => $variants . ' · ' . AdminTranslator::trans('shop.stock_summary.unlimited'),
            'variants_sold_out' => AdminTranslator::trans('shop.stock_summary.sold_out') . ' · ' . $variants,
            'variants_partly_sold_out' => $variants . ' · ' . AdminTranslator::trans('shop.stock_summary.variants_sold_out', ['count' => (string) $this->soldOut]),
            default => $variants . ' · ' . $inStock,
        };
    }
}

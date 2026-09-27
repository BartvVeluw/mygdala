<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductSpecificationRepository;
use PDO;

/**
 * A product's specifications as a visitor reads them (Shop Product &
 * Ordering 2.0, MODULES.md "Specificaties"): the properties from the
 * library that the product has a value for — "Dikte: 3 mm", "Materiaal:
 * Berken multiplex" — in the product's own order, in the language of the
 * page. A property without a value in any language is left out: there is
 * never an empty row. Presentation only; nothing filters or compares on it.
 *
 * The words are the library's and the product's own
 * (App\Service\ShopLocalization): a name or value in the page's language,
 * else the default language's, else the one it was typed in. The unit is
 * the same in every language.
 */
final class ProductSpecifications
{
    public function __construct(private readonly ?PDO $db = null)
    {
    }

    /**
     * @return list<array{name: string, value: string, unit: string}>
     */
    public function forProduct(int $productId, string $languageCode): array
    {
        $rows = (new ProductSpecificationRepository($this->db))->valuesForProduct($productId);
        if ($rows === []) {
            return [];
        }

        ShopLocalization::preloadSpecifications(array_column($rows, 'specification_id'));
        ShopLocalization::preloadSpecificationValues(array_column($rows, 'id'));

        $specifications = [];
        foreach ($rows as $row) {
            $value = ShopLocalization::specificationValue($row['id'], $languageCode);
            $name = ShopLocalization::specificationName($row['specification_id'], $languageCode);
            if ($value === '' || $name === '') {
                continue;
            }

            $specifications[] = ['name' => $name, 'value' => $value, 'unit' => $row['unit']];
        }

        return $specifications;
    }
}

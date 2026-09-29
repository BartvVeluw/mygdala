<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ProductImageRepository;
use App\Repository\ProductRepository;
use App\Service\Language\SiteText;
use App\Service\Search\SearchCandidates;
use App\Service\Search\SearchDocument;
use App\Service\Search\SearchProvider;
use App\Service\Search\SearchQuery;
use App\Service\Search\SearchText;

/**
 * The Shop's contribution to the site search (App\Module\ShopModule::
 * searchProviders()): products, by name and description.
 *
 * VISIBILITY is product.php's own rule: `active = 1`
 * (ProductRepository::findActiveByIds). That includes a product that is not
 * listed in the shop overview (in_shop = 0, a personalisation product):
 * product.php shows it and the sitemap lists it, so the search finds it too.
 *
 * COST, per search: one LIKE per field and term over product_translations
 * (SearchCandidates::ids(), any language, escaped),
 * one query for the active rows among those ids, one for their words
 * (preload) and one for their primary pictures. Never one per result.
 */
final class ProductSearchProvider implements SearchProvider
{
    public function label(string $language): string
    {
        return SiteText::pick(['nl' => 'Product', 'en' => 'Product'], $language);
    }

    public function documents(SearchQuery $query, string $language, int $limit): array
    {
        $ids = SearchCandidates::ids(ShopLocalization::products(), [ShopLocalization::NAME, ShopLocalization::DESCRIPTION], $query);
        if ($ids === []) {
            return [];
        }

        $rows = (new ProductRepository())->findActiveByIds($ids);
        if ($rows === []) {
            return [];
        }

        $productIds = array_keys($rows);
        ShopLocalization::preloadProducts($productIds);
        $pictures = (new ProductImageRepository())->primaryForProducts($productIds);

        $documents = [];
        foreach ($productIds as $id) {
            $name = trim(ShopLocalization::product($id, ShopLocalization::NAME, $language));
            if ($name === '') {
                continue;
            }

            $picture = (string) ($pictures[$id]['thumbnail_path'] ?? '');
            if ($picture === '') {
                $picture = (string) ($rows[$id]['image_path'] ?? '');
            }

            $documents[] = [
                'name' => $name,
                'document' => new SearchDocument(
                    $name,
                    SearchText::plain(ShopLocalization::productDescription($id, $language)),
                    ProductSeo::publicPath($id, $language),
                    $picture !== '' ? '/' . ltrim($picture, '/') : null
                ),
            ];
        }

        // The storefront has no one order a search could borrow; by name is
        // the order a visitor can predict.
        usort($documents, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return array_slice(array_column($documents, 'document'), 0, $limit);
    }
}

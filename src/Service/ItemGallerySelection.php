<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Language\AdminTranslator;

/**
 * THE CHOICE OF ITEMS of a gallery block, as its editors post it
 * (admin/_gallery_selection.php) — read and checked once, for both blocks
 * that share item_galleries: the gallery (api/admin/update-item-gallery.php)
 * and the Projecten block (api/admin/update-project-cards.php). Projecten 2.0,
 * CONTENT-BLOCKS.md.
 *
 * WHAT IT READS, each against a closed list or the source's own choices:
 *
 *   portfolio_scope  ItemGalleryContent::SCOPES — refused when unknown
 *   category_id      one of the source's categories
 *                    (ItemGallerySources::categoryChoices()); required for the
 *                    'category' scope, kept for the others, so switching to
 *                    "all" and back finds it again; a form without the field
 *                    keeps the stored one
 *   item_sort        ItemGalleryContent::SORTS, for 'all' and 'category'; a
 *                    form without it keeps the stored order
 *   manual_random    for 'manual': its own order, or random (the only two a
 *                    picked list has)
 *   item_ids[]       the picked items in order, only when `items_submitted`
 *                    says the picker was on the form (a form without it keeps
 *                    the stored list); each once, and only ids the source
 *                    offers — a stale one (deleted meanwhile) is dropped, not
 *                    refused, the rule the Portfolio's category boxes follow
 *
 * and, on its own because the gallery editor keeps its number field for it,
 * maxItems(): empty (all), or a whole number 1 to 200 — the Projecten editor
 * offers 3, 4, 6, 8, 12 and all (MAXIMUMS), plus a number stored before there
 * was a list, which must not be lost by saving.
 *
 * Nothing is written here: the endpoint stores `values` through
 * App\Repository\ItemGalleryRepository and, when `selected` is not null, the
 * picked items through ItemGallerySources::saveSelection(), in one
 * transaction with everything else.
 */
final class ItemGallerySelection
{
    /** What the maximum choice offers; empty is "all". */
    public const MAXIMUMS = [3, 4, 6, 8, 12];

    /**
     * @param array<string, mixed> $post   raw $_POST
     * @param string               $source the source whose items are chosen (it may offer no choice)
     * @param array<string, mixed> $stored the block's item_galleries row
     *
     * @return array{values: array{portfolio_scope: string, portfolio_category_id: ?int, item_sort: string}, selected: ?list<int>, errors: list<string>}
     */
    public static function fromRequest(array $post, string $source, array $stored): array
    {
        $text = static fn (string $name): string => is_scalar($post[$name] ?? null) ? trim((string) $post[$name]) : '';
        $errors = [];

        $scope = $text('portfolio_scope');
        if (!ItemGalleryContent::isPortfolioScope($scope)) {
            $errors[] = AdminTranslator::trans('validation.kies_welke_projecten');
            $scope = ItemGalleryContent::SCOPE_ALL;
        }

        $categoryIds = array_map(
            static fn (array $category): int => (int) $category['id'],
            ItemGallerySources::categoryChoices($source)
        );
        // A form without the field keeps the stored category (the category a
        // deleted one leaves is NULL already, through its foreign key).
        $categoryId = array_key_exists('category_id', $post)
            ? (ctype_digit($text('category_id')) ? (int) $text('category_id') : 0)
            : (int) ($stored['portfolio_category_id'] ?? 0);
        if (!in_array($categoryId, $categoryIds, true)) {
            $categoryId = 0;
        }
        if ($scope === ItemGalleryContent::SCOPE_CATEGORY && $categoryId === 0) {
            $errors[] = AdminTranslator::trans('validation.gallery_category_required');
        }

        if ($scope === ItemGalleryContent::SCOPE_MANUAL) {
            $sort = ($post['manual_random'] ?? '') === '1' ? 'random' : 'source';
        } elseif (array_key_exists('item_sort', $post)) {
            $sort = $text('item_sort');
            if (!ItemGalleryContent::isSort($sort)) {
                $errors[] = AdminTranslator::trans('validation.gallery_sort');
                $sort = 'source';
            }
        } else {
            $storedSort = (string) ($stored['item_sort'] ?? 'source');
            $sort = ItemGalleryContent::isSort($storedSort) ? $storedSort : 'source';
        }

        $selected = null;
        if (($post['items_submitted'] ?? '') === '1') {
            $offered = array_flip(array_map(
                static fn (array $item): int => (int) $item['id'],
                ItemGallerySources::itemChoices($source)
            ));
            $selected = [];
            foreach (is_array($post['item_ids'] ?? null) ? $post['item_ids'] : [] as $posted) {
                $id = is_scalar($posted) && ctype_digit((string) $posted) ? (int) $posted : 0;
                if (isset($offered[$id]) && !in_array($id, $selected, true)) {
                    $selected[] = $id;
                }
            }
        }

        return [
            'values' => [
                'portfolio_scope' => $scope,
                'portfolio_category_id' => $categoryId > 0 ? $categoryId : null,
                'item_sort' => $sort,
            ],
            'selected' => $selected,
            'errors' => $errors,
        ];
    }

    /**
     * The posted maximum: null for all, or a whole number from 1 to 200, and
     * what is wrong with it.
     *
     * @param array<string, mixed> $post raw $_POST
     *
     * @return array{0: ?int, 1: list<string>}
     */
    public static function maxItems(array $post): array
    {
        $raw = is_scalar($post['max_items'] ?? null) ? trim((string) $post['max_items']) : '';
        if ($raw === '') {
            return [null, []];
        }

        $max = ctype_digit($raw) ? (int) $raw : 0;

        return $max >= 1 && $max <= 200
            ? [$max, []]
            : [null, [AdminTranslator::trans('validation.maximum_aantal_projecten')]];
    }
}

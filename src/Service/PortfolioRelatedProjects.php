<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PortfolioGalleryRepository;
use App\Service\Language\SiteText;
use App\Service\Routing\RequestLanguage;

/**
 * RELATED PROJECTS below a project page (portfolio-detail.php, MODULES.md
 * "Portfolio"): other projects, as the very cards every gallery shows
 * (PortfolioGalleryContent::cards(), partials/section-item-gallery.php), under
 * a heading of their own. Off until an editor switches it on per project
 * (related_enabled, db/migrations/20260928190000), so no existing page
 * changes.
 *
 * THREE WAYS TO CHOOSE (related_mode):
 *
 *   automatic  projects that share a category with this one, the ones with
 *              more categories in common first when sorted by relevance
 *   manual     exactly the projects picked for it (portfolio_related_items),
 *              in their picked order
 *   hybrid     the picked ones first, in their order, then automatic ones
 *              for the places left, never one of the picked ones twice
 *
 * THE RULES, whichever way (choose(), and nowhere else):
 *
 *   - the project itself never appears: not picked, not drawn, not filled in;
 *   - only visible projects (is_active = 1), the one visibility the Portfolio
 *     has; a hidden or deleted one simply drops out (a deleted one takes its
 *     relation rows along, ON DELETE CASCADE);
 *   - never the same project twice;
 *   - at most related_max (MAXIMUMS);
 *   - automatic ones in related_sort order: most relevant (shared categories,
 *     then newest, then the highest id — a stable order), newest, oldest, by
 *     title, or random (App\Service\RandomOrder, on the server, per request);
 *   - too few that share a category: show only those ('available', the
 *     start, so nothing unrelated turns up unasked), or top up with other
 *     projects in the same order ('fill').
 *
 * NO SNAPSHOT: the choice is made per request from the rows as they are, so a
 * project that is hidden, deleted or recategorised changes the row with it.
 *
 * THE HEADING is the project's own related_title in the language of the
 * request, with the usual fallback to the default language
 * (App\Service\Language\LanguageFallback through PortfolioLocalization), and
 * without any, the built-in "Gerelateerde projecten" / "Related projects".
 * The lead is optional and has no built-in words.
 */
final class PortfolioRelatedProjects
{
    public const MODES = ['automatic', 'manual', 'hybrid'];

    /** How many at most, in the order the editor offers them. */
    public const MAXIMUMS = [2, 3, 4, 6, 8];

    /** The start: 3 fills one row of the gallery grid. */
    public const DEFAULT_MAX = 3;

    public const SORTS = ['relevance', 'newest', 'oldest', 'title', 'random'];

    /** What happens when too few projects share a category: show those, or top up. */
    public const FALLBACKS = ['available', 'fill'];

    /** The card grid's preset: more, smaller cards, the gallery's own three, or two large ones. */
    public const LAYOUTS = ['normal', 'compact', 'large'];

    /** The heading when the project has none of its own. */
    public const DEFAULT_TITLE = ['nl' => 'Gerelateerde projecten', 'en' => 'Related projects'];

    /**
     * An item row's related-project settings, each read against its closed
     * list: a value this class does not know is that list's first.
     *
     * @param array<string, mixed> $row a portfolio_gallery_items row
     *
     * @return array{related_enabled: bool, related_mode: string, related_max: int, related_sort: string, related_fallback: string, related_layout: string, related_show_text: bool}
     */
    public static function settings(array $row): array
    {
        $word = static fn (string $column, array $list): string => in_array((string) ($row[$column] ?? ''), $list, true)
            ? (string) $row[$column]
            : $list[0];
        $max = (int) ($row['related_max'] ?? 0);

        return [
            'related_enabled' => !empty($row['related_enabled']),
            'related_mode' => $word('related_mode', self::MODES),
            'related_max' => in_array($max, self::MAXIMUMS, true) ? $max : self::DEFAULT_MAX,
            'related_sort' => $word('related_sort', self::SORTS),
            'related_fallback' => $word('related_fallback', self::FALLBACKS),
            'related_layout' => $word('related_layout', self::LAYOUTS),
            'related_show_text' => !array_key_exists('related_show_text', $row) || !empty($row['related_show_text']),
        ];
    }

    /**
     * forItem() for the project page, which knows its project by id
     * (PortfolioGalleryContent::itemForDetailPage()): the row is read again
     * here rather than carried in the page's content, so the page's own shape
     * stays what it was.
     *
     * @return array<string, mixed>|null
     */
    public static function forItemId(int $itemId): ?array
    {
        try {
            $item = (new PortfolioGalleryRepository())->findItemById($itemId);
        } catch (\Throwable $e) {
            error_log('[PortfolioRelatedProjects] item lookup failed for #' . $itemId . ': ' . $e->getMessage());

            return null;
        }

        return $item === null ? null : self::forItem($item);
    }

    /**
     * The related projects of one project page, ready for
     * render_section_item_gallery(), or null when there is nothing to show:
     * switched off, or no project qualifies. $item is the project's own
     * portfolio_gallery_items row, as PortfolioGalleryContent::itemForDetailPage()
     * read it.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>|null
     */
    public static function forItem(array $item): ?array
    {
        $settings = self::settings($item);
        if (!$settings['related_enabled']) {
            return null;
        }

        $itemId = (int) $item['id'];
        $visible = PortfolioGalleryContent::visibleRows();
        $language = RequestLanguage::current();

        try {
            $repository = new PortfolioGalleryRepository();
            $manual = $settings['related_mode'] === 'automatic' ? [] : $repository->relatedItemIds($itemId);
            $categories = $settings['related_mode'] === 'manual'
                ? []
                : $repository->categoryIdsByItemIds(array_merge([$itemId], array_keys($visible)));
        } catch (\Throwable $e) {
            error_log('[PortfolioRelatedProjects] lookup failed for item #' . $itemId . ': ' . $e->getMessage());

            return null;
        }

        $ids = self::choose(
            $settings,
            $itemId,
            $visible,
            $categories,
            $manual,
            static fn (array $ids, string $sort): array => PortfolioGalleryContent::sortIds(
                $ids,
                $visible,
                $sort === 'title' ? 'title_asc' : $sort,
                $language
            )
        );

        if ($ids === []) {
            return null;
        }

        $cards = PortfolioGalleryContent::cards(array_map(static fn (int $id): array => $visible[$id], $ids));
        if (!$settings['related_show_text']) {
            foreach ($cards as $index => $card) {
                $cards[$index]['subtitle'] = '';
            }
        }

        $title = PortfolioLocalization::item($itemId, PortfolioLocalization::RELATED_TITLE, $language);

        return [
            'items' => $cards,
            'filter_categories' => [],
            'enable_lightbox' => false,
            'fallback_link_url' => '',
            'eyebrow' => '',
            'title' => trim($title) !== '' ? $title : SiteText::pick(self::DEFAULT_TITLE),
            'lead' => PortfolioLocalization::item($itemId, PortfolioLocalization::RELATED_LEAD, $language),
            'footer_note' => '',
            'button_label' => '',
            'button_url' => '',
            'background' => 'default',
            'tight_top' => false,
            'grid' => $settings['related_layout'],
        ];
    }

    /**
     * THE CHOICE, without a database: which projects, in which order.
     *
     * @param array{related_mode: string, related_max: int, related_sort: string, related_fallback: string} $settings from settings()
     * @param int                              $currentId  the project whose page this is; never chosen
     * @param array<int, array<string, mixed>> $visible    every visible project row by id, in the Portfolio's own order
     * @param array<int, list<int>>            $categories category ids by project id (this project's included)
     * @param list<int>                        $manual     the picked ones, in their picked order
     * @param callable(list<int>, string): list<int> $sortIds orders ids for 'newest', 'oldest' and 'title'
     *
     * @return list<int>
     */
    public static function choose(array $settings, int $currentId, array $visible, array $categories, array $manual, callable $sortIds): array
    {
        $max = (int) $settings['related_max'];
        $mode = (string) $settings['related_mode'];

        // The picked ones that may be shown: visible, not this project, once.
        // The automatic way ignores a stored list altogether: it is kept for
        // when the editor switches back, and excludes nothing meanwhile.
        $picked = [];
        foreach ($mode === 'automatic' ? [] : $manual as $id) {
            $id = (int) $id;
            if ($id !== $currentId && isset($visible[$id]) && !in_array($id, $picked, true)) {
                $picked[] = $id;
            }
        }

        $chosen = $mode === 'automatic' ? [] : array_slice($picked, 0, $max);
        if ($mode === 'manual') {
            return $chosen;
        }

        $places = $max - count($chosen);
        if ($places <= 0) {
            return $chosen;
        }

        // Every other visible project, minus this one and every picked one
        // (a picked one that did not fit is not drawn back in either).
        $own = array_flip($categories[$currentId] ?? []);
        $relevant = [];
        $others = [];
        foreach (array_keys($visible) as $id) {
            if ($id === $currentId || in_array($id, $picked, true)) {
                continue;
            }

            $shared = count(array_intersect_key(array_flip($categories[$id] ?? []), $own));
            if ($shared > 0) {
                $relevant[$id] = $shared;
            } else {
                $others[] = $id;
            }
        }

        $sort = (string) $settings['related_sort'];
        $ordered = self::order(array_keys($relevant), $relevant, $sort, $visible, $sortIds);
        if ($settings['related_fallback'] === 'fill') {
            $ordered = array_merge($ordered, self::order($others, [], $sort, $visible, $sortIds));
        }

        return array_merge($chosen, array_slice($ordered, 0, $places));
    }

    /**
     * Ids in one of the related sorts. Relevance is the number of shared
     * categories, then newest, then the highest id, so equal projects always
     * come in the same order; without scores (the ones filled in) it is newest.
     *
     * @param list<int>                        $ids
     * @param array<int, int>                  $scores  shared categories by id
     * @param array<int, array<string, mixed>> $visible rows by id
     * @param callable(list<int>, string): list<int> $sortIds
     *
     * @return list<int>
     */
    private static function order(array $ids, array $scores, string $sort, array $visible, callable $sortIds): array
    {
        if ($ids === []) {
            return [];
        }

        if ($sort === 'random') {
            return RandomOrder::shuffle($ids);
        }

        if ($sort !== 'relevance') {
            return $sortIds($ids, $sort);
        }

        $created = static fn (int $id): string => (string) ($visible[$id]['created_at'] ?? '');
        usort($ids, static fn (int $a, int $b): int => [$scores[$b] ?? 0, $created($b), $b] <=> [$scores[$a] ?? 0, $created($a), $a]);

        return $ids;
    }
}

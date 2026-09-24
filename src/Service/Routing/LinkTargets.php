<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Module\ModuleRegistry;
use App\Service\LinkResolver;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PagePath;
use App\Service\PageTree;

/**
 * The items of this website a block's button can point at by id instead of
 * by a typed address: a page, and whatever an enabled module contributes (a
 * blog post, a product) through App\Module\ModuleDefinition::linkTargets().
 * First used by the Kaarten-carrousel's cards (CONTENT-BLOCKS.md).
 *
 * WHY AN ID AND NOT AN ADDRESS. A stored '/blog/mijn-bericht' breaks the day
 * the post gets another slug, and it is the Dutch address on the English site.
 * An id is resolved per render, in the language being read, by the owner of
 * the item (PageContent::publicUrl(), BlogContent::postUrl(),
 * ProductSeo::publicPath()), so the button follows a rename and a translation
 * by itself — the same reason nav_items store target_page_id.
 *
 * A CLOSED LIST. Core owns 'page'; every other type is code in a module class.
 * A type whose module is switched off is simply not in types(): the editor
 * offers no unusable choice, and href() gives null, so the button is left out
 * rather than pointing at a 404 — while the stored row is untouched and comes
 * back when the module does (MODULES.md). A type never comes from a request
 * without being checked against this list.
 *
 * NOT HERE: typed addresses ('url'), which App\Service\Routing\TypedLink
 * translates, and the header/footer link rows, which have their own
 * App\Service\LinkResolver shape.
 */
final class LinkTargets
{
    public const PAGE = 'page';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $types = null;

    /**
     * Every available type, lowest `order` first.
     *
     * @return array<string, array{label: string, order: int, choices: callable(): list<array{id: int, label: string, note?: string}>, href: callable(int): ?string}>
     */
    public static function types(): array
    {
        if (self::$types !== null) {
            return self::$types;
        }

        $types = [self::PAGE => self::pageType()] + ModuleRegistry::collectMap('linkTargets');
        uasort($types, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return self::$types = $types;
    }

    public static function isAvailable(string $type): bool
    {
        return isset(self::types()[$type]);
    }

    /**
     * What an editor can choose for one type: id, name, and a `note`
     * ('draft', 'inactive') for an item a visitor cannot open yet. Admin-only;
     * a failing lookup is not caught, so an editor is never shown an empty
     * list and saves a link away.
     *
     * @return list<array{id: int, label: string, note?: string}>
     */
    public static function choices(string $type): array
    {
        $definition = self::types()[$type] ?? null;

        return $definition === null ? [] : ($definition['choices'])();
    }

    /** Whether $id is one of the choices of $type: what an endpoint checks before storing it. */
    public static function exists(string $type, int $id): bool
    {
        foreach (self::choices($type) as $choice) {
            if ($choice['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * The href of an item in the language being read, or null when there is
     * nothing a visitor can open: an unknown or switched-off type, an item
     * that is gone, a draft, an inactive product. Never throws.
     */
    public static function href(string $type, int $id): ?string
    {
        $definition = self::types()[$type] ?? null;
        if ($definition === null || $id < 1) {
            return null;
        }

        try {
            $href = ($definition['href'])($id);
        } catch (\Throwable $e) {
            error_log('[LinkTargets] resolving ' . $type . ' #' . $id . ' failed: ' . $e->getMessage());

            return null;
        }

        return is_string($href) && $href !== '' ? $href : null;
    }

    /** Forget the collected types; modules changed (tests). */
    public static function reset(): void
    {
        self::$types = null;
    }

    /** @return array<string, mixed> */
    private static function pageType(): array
    {
        return [
            'label' => 'Bestaande pagina',
            'order' => 10,
            'choices' => static function (): array {
                // In the Pages overview's order, a page under its parent and
                // indented one step per level (App\Service\PageTree), so two
                // pages with the same name in different trees can be told apart.
                $rows = PageTree::ordered();
                PageLocalization::preload(array_column($rows, 'id'));

                $choices = [];
                foreach ($rows as $row) {
                    $page = PagePath::node($row['id']) ?? [];

                    // A page served by a module that is off answers 404.
                    if ($page === [] || !PageContent::isServedByAnEnabledModule($page)) {
                        continue;
                    }

                    $choice = [
                        'id' => (int) $page['id'],
                        'label' => str_repeat("\u{00A0}\u{00A0}\u{00A0}", $row['depth']) . PageLocalization::name((int) $page['id']),
                    ];
                    if (!PageContent::isPublished($page)) {
                        $choice['note'] = 'draft';
                    }
                    $choices[] = $choice;
                }

                return $choices;
            },
            // The one page-link rule of the header and footer: published, served
            // by an enabled module, at its address in the language being read.
            'href' => static fn (int $id): ?string => LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $id])['href'] ?? null,
        ];
    }
}

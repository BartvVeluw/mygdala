<?php

declare(strict_types=1);

namespace App\Service\Routing;

use App\Module\ModuleRegistry;
use App\Service\Language\AdminLocale;
use App\Service\LinkResolver;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageOptions;

/**
 * THE DESTINATIONS a link can point at by id instead of by a typed address
 * (Destination Picker 2.0, Pages & Destinations 3.0; CONTENT-BLOCKS.md "Waar
 * een knop heen gaat"): a page, and whatever an enabled module contributes
 * through App\Module\ModuleDefinition::linkTargets() — a blog post, a
 * product, a collection, a Portfolio project. Every block button's picker
 * (admin/_link_target_field.php) is built from this list.
 *
 * WHY AN ID AND NOT AN ADDRESS. A stored '/blog/mijn-bericht' breaks the day
 * the post gets another slug, and it is the Dutch address on the English site.
 * An id is resolved per render, in the language being read, by the owner of
 * the item (PageContent::publicUrl(), BlogContent::postUrl(),
 * ProductSeo::publicPath(), CollectionContent::urlFor()), so the button
 * follows a rename and a translation by itself — the same reason nav_items
 * store target_page_id.
 *
 * A CLOSED LIST OF PROVIDERS. Core owns 'page' and knows no module's type by
 * name; every other type is code in a module class, in one shape:
 *
 *   label    the editor's name for the kind: Dutch, or per CMS language
 *            ['nl' => 'Product', 'en' => 'Product']
 *   order    its place among the kinds (page 10, blog post 20, product 30, …)
 *   choices  callable(): list<array{id, label, note?, context?, thumbnail?, meta?}>
 *            — what an editor can choose: `note` 'draft', 'inactive' or
 *            'hidden' for an item a visitor cannot open yet, `thumbnail` a
 *            root-relative image, `meta` a second line (a price, a date)
 *   href     callable(int): ?string — the address in the language being read,
 *            or null when a visitor cannot open it
 *   picker   optional: 'tree' (a list in tree order, the pages) or 'search'
 *            (a searchable list with pictures; the default for a module's)
 *   title    optional callable(int, string): ?string — the item's name as a
 *            visitor reads it in one language
 *
 * Only a kind that really has a public address is a kind here: a Portfolio
 * category is only a filter on the Portfolio page, so there is none.
 *
 * A TYPE WHOSE MODULE IS OFF is not in types(): the editor offers no unusable
 * choice, and href() gives null, so the button is left out rather than
 * pointing at a 404 — while the stored row is untouched and comes back when
 * the module does (MODULES.md). known() still names it, so the editor can say
 * WHICH module the stored destination is waiting for. A type never comes from
 * a request without being checked against this list.
 *
 * NOT HERE: typed addresses ('url'), which App\Service\Routing\TypedLink
 * translates and App\Service\Routing\SafeUrl guards, and the header/footer
 * link rows, which have their own App\Service\LinkResolver shape.
 */
final class LinkTargets
{
    public const PAGE = 'page';

    /** How a kind's items are chosen: a list in tree order, or a searchable list. */
    public const PICKER_TREE = 'tree';
    public const PICKER_SEARCH = 'search';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $types = null;

    /**
     * Every available type, lowest `order` first.
     *
     * @return array<string, array<string, mixed>>
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
     * The module a type belongs to while that module is OFF, or null — for an
     * available type, Core's own page, and anything unknown. What the editor
     * names when a stored destination is waiting for a module.
     */
    public static function disabledModuleOf(string $type): ?string
    {
        if ($type === '' || $type === self::PAGE || self::isAvailable($type)) {
            return null;
        }

        $owner = ModuleRegistry::ownerOf('linkTargets', $type);

        return $owner === null ? null : ModuleRegistry::label($owner);
    }

    /** The editor's name for a kind, in the CMS language (the type key when unknown). */
    public static function label(string $type): string
    {
        $label = self::types()[$type]['label'] ?? $type;

        if (is_array($label)) {
            return (string) ($label[AdminLocale::current()] ?? $label['nl'] ?? reset($label) ?: $type);
        }

        return (string) $label;
    }

    /** How a kind's items are chosen (PICKER_TREE or PICKER_SEARCH). */
    public static function picker(string $type): string
    {
        $picker = self::types()[$type]['picker'] ?? null;

        if ($picker === self::PICKER_TREE || $picker === self::PICKER_SEARCH) {
            return $picker;
        }

        return $type === self::PAGE ? self::PICKER_TREE : self::PICKER_SEARCH;
    }

    /**
     * What an editor can choose for one type: id, name, and a `note`
     * ('draft', 'inactive', 'hidden') for an item a visitor cannot open yet;
     * a `context` line is only there to keep a tree readable and cannot be
     * chosen. Admin-only; a failing lookup is not caught, so an editor is
     * never shown an empty list and saves a link away.
     *
     * @return list<array{id: int, label: string, note?: string, context?: bool, thumbnail?: string, meta?: string}>
     */
    public static function choices(string $type): array
    {
        $definition = self::types()[$type] ?? null;

        return $definition === null ? [] : ($definition['choices'])();
    }

    /**
     * One choice of a type by id, or null when the item cannot be chosen
     * (gone, or not offered right now). A `context` line counts as none.
     *
     * @return array{id: int, label: string, note?: string, thumbnail?: string, meta?: string}|null
     */
    public static function find(string $type, int $id): ?array
    {
        foreach (self::choices($type) as $choice) {
            if ($choice['id'] === $id && empty($choice['context'])) {
                return $choice;
            }
        }

        return null;
    }

    /**
     * Whether $id is one of the choices of $type: what an endpoint checks
     * before storing it. A `context` line (a page only listed to keep the
     * tree readable) is no choice.
     */
    public static function exists(string $type, int $id): bool
    {
        return self::find($type, $id) !== null;
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

    /**
     * The item's name as a visitor reads it in one language, or null when the
     * type has none to give or the item is gone. What a menu item that follows
     * its destination's title shows (HEADER-FOOTER.md). Never throws.
     */
    public static function title(string $type, int $id, string $language): ?string
    {
        $title = self::types()[$type]['title'] ?? null;
        if ($title === null || $id < 1) {
            return null;
        }

        try {
            $value = $title($id, $language);
        } catch (\Throwable $e) {
            error_log('[LinkTargets] the title of ' . $type . ' #' . $id . ' failed: ' . $e->getMessage());

            return null;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
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
            'label' => ['nl' => 'Pagina', 'en' => 'Page'],
            'order' => 10,
            'picker' => self::PICKER_TREE,
            'choices' => static function (): array {
                // In the Pages overview's order, a page under its parent and
                // indented one step per level (App\Service\PageOptions, the one
                // order of every page list), so two pages with the same name in
                // different trees can be told apart. A page served by a module
                // that is off answers 404, and is not offered.
                // A page above an offered one that is not offered itself (the
                // Portfolio's page while it is the module's own overview) is
                // there as `context`: shown, never a valid choice (exists()).
                $choices = [];
                foreach (PageOptions::tree(static fn (array $page): bool => PageContent::isServedByAnEnabledModule($page)) as $option) {
                    $choice = ['id' => $option['id'], 'label' => $option['label']];
                    if ($option['context']) {
                        $choice['context'] = true;
                    } elseif (!PageContent::isPublished($option['page'])) {
                        $choice['note'] = 'draft';
                    }
                    $choices[] = $choice;
                }

                return $choices;
            },
            // The one page-link rule of the header and footer: published, served
            // by an enabled module, at its address in the language being read.
            'href' => static fn (int $id): ?string => LinkResolver::resolve(['link_type' => 'page', 'target_page_id' => $id])['href'] ?? null,
            // The page's title as its own trail and header name it: the
            // language asked for, else the default language's.
            'title' => static fn (int $id, string $language): ?string => PageLocalization::title($id, $language),
        ];
    }
}

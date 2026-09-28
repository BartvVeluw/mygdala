<?php

declare(strict_types=1);

namespace App\Service;

/**
 * THE order and THE text of a CMS page wherever an editor picks one from a
 * list (Pages & Destinations 3.0, docs/pages/NESTING.md "Pagina's kiezen"): a
 * menu item's or footer link's page, a page's parent, a block button's page,
 * the Shop's product overview. Before this, three of those five lists were
 * flat and sorted their own way, so two pages called "Team" under different
 * parents looked the same and a subpage turned up far from its parent.
 *
 * THE ORDER is the Pages overview's (App\Service\PageTree): the roots in
 * their own order, every page directly under its parent, recursively — never
 * by id and never alphabetically on its own.
 *
 * THE TEXT is the page's name (App\Service\PageLocalization::name(), the
 * default language with the admin's fallback, like every page list in the
 * CMS) indented one step per level, with ONE mark before a page that sits
 * under another:
 *
 *     Diensten
 *        – Metaal graveren
 *           – Aluminium visitekaartjes
 *     Shop
 *        – Zakelijk
 *
 * A native <select> cannot draw a tree, so the depth is in the text. The
 * indent is no-break spaces, which a screen reader does not read, and the
 * mark is a single en dash, which a screen reader at its usual punctuation
 * level does not read either — a deliberate choice over a row of dashes or
 * box-drawing characters, which it would read out on every option.
 *
 * WHAT EACH LIST SHOWS stays that list's own business: drafts or not, the
 * page itself or not, a stored choice kept. This class only puts what a list
 * shows in one order and gives it one text — and keeps the tree honest: a
 * page a list does not offer but that stands above one it does (the Portfolio
 * page, which a menu links as a route, above a page under it) is still
 * listed, as `context`, for the list to show as a line nobody can choose.
 * Without it the page under it would read as sitting under whatever came
 * before. It reads, and never writes.
 */
final class PageOptions
{
    /** One level of indent: three no-break spaces. */
    private const INDENT = "\u{00A0}\u{00A0}\u{00A0}";

    /** Before a page that sits under another: an en dash and a no-break space. */
    private const MARK = "\u{2013}\u{00A0}";

    /**
     * The pages a list shows, in THE order, plus the pages above them that it
     * does not show (`context`), so every page stands under its own parent.
     *
     * @param (callable(array<string, mixed>): bool)|null $include which pages this list offers,
     *        asked with the page's structure row (App\Service\PagePath::node()); null offers all
     * @param list<int> $keep pages offered whatever $include says: a stored
     *        choice that must stay selectable
     * @return list<array{id: int, depth: int, name: string, label: string, page: array<string, mixed>, context: bool}>
     */
    public static function tree(?callable $include = null, array $keep = []): array
    {
        $rows = PageTree::ordered();
        PageLocalization::preload(array_column($rows, 'id'));
        $keep = array_flip(array_map('intval', $keep));

        $offered = [];
        foreach ($rows as $row) {
            $page = PagePath::node($row['id']);
            if ($page !== null && (isset($keep[$row['id']]) || $include === null || $include($page))) {
                $offered[$row['id']] = true;
            }
        }

        $context = [];
        foreach (array_keys($offered) as $id) {
            foreach (PagePath::ancestorIds($id) ?? [] as $ancestorId) {
                if (!isset($offered[$ancestorId])) {
                    $context[$ancestorId] = true;
                }
            }
        }

        $options = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $page = PagePath::node($id);
            if ($page === null || (!isset($offered[$id]) && !isset($context[$id]))) {
                continue;
            }

            $name = PageLocalization::name($id);
            $options[] = [
                'id' => $id,
                'depth' => $row['depth'],
                'name' => $name,
                'label' => self::label($name, $row['depth']),
                'page' => $page,
                'context' => isset($context[$id]),
            ];
        }

        return $options;
    }

    /** An option's text: the name, indented by its depth, marked when it sits under another page. */
    public static function label(string $name, int $depth): string
    {
        return $depth < 1 ? $name : str_repeat(self::INDENT, $depth) . self::MARK . $name;
    }
}

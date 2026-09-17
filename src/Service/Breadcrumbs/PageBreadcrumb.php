<?php

declare(strict_types=1);

namespace App\Service\Breadcrumbs;

use App\Service\Language\LanguageRegistry;
use App\Service\PageContent;
use App\Service\PageLocalization;
use App\Service\PageTranslation;

/**
 * The breadcrumb of an ordinary CMS page: Home, then the page itself.
 *
 * WHY IT IS NOT PART OF THE PAGE HEADER ANY MORE. The trail used to be printed
 * inside partials/section-page-hero.php, which meant a page whose Paginakop
 * was hidden, deleted or never added lost the only thing telling a visitor
 * where they were. Whether a page shows a breadcrumb and whether it shows a
 * header are two separate questions, so they are two separate answers now:
 * this class, and the block. See docs/content-blocks/DECISIONS.md and
 * HEADER-FOOTER.md.
 *
 * THE LABEL IS THE PAGE'S OWN TITLE, read per render, in both languages of the
 * V1 language switch. It comes from App\Service\PageLocalization, which is
 * the one place that knows where a page's name is stored and how an empty
 * translation falls back. Nothing is copied: the old `page_heroes.breadcrumb_label_nl/en` was a
 * second place to type the same words, and it fell behind the moment a page
 * was renamed. Renaming a page now moves its breadcrumb with it, by
 * construction, and translating it translates the breadcrumb.
 *
 * THE SITE ROOT NEVER HAS ONE. "Home / Home" is not a trail, and the homepage
 * is where a trail starts rather than something it can point at.
 *
 * THE TOGGLE is `pages.show_breadcrumb` — one column on the page, not a
 * property of the header, defaulting to on so every page that has a breadcrumb
 * today keeps it. Only pages have it: the application's own fixed routes (the
 * cart, the checkout, a product, a 404) have no `pages` row and always show
 * their trail, which is what they have always done. HEADER-FOOTER.md writes
 * that contract out per route group.
 */
final class PageBreadcrumb
{
    /** The column that says whether this page shows its trail. */
    public const COLUMN = 'show_breadcrumb';

    /**
     * The trail for one CMS page, or null when this page shows none.
     *
     * Null rather than an empty trail, so a template can hand the result
     * straight to render_breadcrumb() without asking twice.
     *
     * @param array<string, mixed>|null $page a `pages` row; null is a page
     *                                        that could not be loaded
     */
    public static function forPage(?array $page): ?BreadcrumbTrail
    {
        if ($page === null || PageContent::isSiteRoot($page) || !self::isEnabled($page)) {
            return null;
        }

        // Both halves of the V1 switch, already resolved by the page's own
        // fallback: the renderer decides which one a visitor sees and prints
        // the other for the language switch, the same way every other piece
        // of editor text on a public page works. The page a visitor is
        // standing on is never a link to itself.
        $title = PageLocalization::bilingual((int) $page['id'], PageTranslation::TITLE);

        return BreadcrumbTrail::home()->to(BreadcrumbItem::current(
            $title->raw(LanguageRegistry::DUTCH),
            $title->raw(LanguageRegistry::ENGLISH)
        ));
    }

    /**
     * Does this page show a breadcrumb at all?
     *
     * A row written before the column existed reads as "yes" — the column is
     * NOT NULL DEFAULT 1 and MySQL filled it in, and a page that is somehow
     * missing the key falls the same way, because every page showed one before
     * this was a choice.
     *
     * @param array<string, mixed> $page
     */
    public static function isEnabled(array $page): bool
    {
        return !array_key_exists(self::COLUMN, $page) || (int) $page[self::COLUMN] === 1;
    }
}

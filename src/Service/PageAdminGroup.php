<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Which list of the CMS Pages overview a page is filed under: the everyday
 * website pages, or "Service & juridisch" — terms and conditions, the privacy
 * statement, shipping and returns — which an editor maintains now and then
 * and does not want between the pages they work on every day
 * (docs/pages/NESTING.md).
 *
 * ADMIN ORGANISATION AND NOTHING ELSE. The group is not a page type: the
 * router, the SEO head, the sitemap, the menus, the blocks and the
 * permissions never read it, so filing a page under Service & juridisch
 * changes nothing a visitor or a crawler can see.
 *
 * ONE EFFECTIVE GROUP PER TREE. `pages.admin_group` is stored on every row,
 * but only a ROOT page's value decides: every page below it is in its root's
 * group (App\Service\PagePath::effectiveGroup()). A child is therefore never
 * listed on its own in the other list, and moving a subtree under a root of
 * the other group moves the whole subtree there. App\Service\PageService keeps
 * the stored values of a subtree equal to its root's, so the column tells the
 * truth when read directly too.
 *
 * A closed list, like App\Service\PageContent::STATUSES: an unknown value
 * from a request is refused, and one found in the database reads as the
 * website group, which is what every page was before this existed.
 */
final class PageAdminGroup
{
    public const WEBSITE = 'website';
    public const SERVICE = 'service';

    /** @var list<string> in the order the overview lists them */
    public const ALL = [self::WEBSITE, self::SERVICE];

    public static function isValid(string $group): bool
    {
        return in_array($group, self::ALL, true);
    }

    /** A stored value, read safely: anything unknown is the website group. */
    public static function normalise(mixed $group): string
    {
        $group = is_string($group) ? trim($group) : '';

        return self::isValid($group) ? $group : self::WEBSITE;
    }
}

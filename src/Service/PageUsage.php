<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\FooterRepository;
use App\Repository\NavigationRepository;

/**
 * Where on the website one CMS page is linked from, in words an editor
 * recognises: what admin/page.php lists before a page's web address changes.
 *
 * ONLY LINKS THAT KNOW WHICH PAGE THEY MEAN. A menu item, a header button and
 * a footer link can point at a page by its id, and App\Service\LinkResolver
 * turns that id into the page's CURRENT address on every render. Each of them
 * therefore follows a new address by itself, and nothing is ever rewritten.
 * Those are the places listed here.
 *
 * WHAT IS DELIBERATELY NOT COUNTED. An address somebody typed — "/contact" in
 * a button of a content block, a link inside rich text, a menu item of the
 * type "external URL" — is text, not a reference. The CMS cannot know that
 * such text means this page rather than a route, a redirect or a page that
 * used to have that address, and finding every candidate would mean reading
 * every text column of every block of every module. A count that included
 * some of them would look complete without being so. Typed links are never
 * rewritten either: a published page's old address keeps them working through
 * App\Service\Redirects\SlugChangeRedirects (REDIRECTS.md), and the editor is
 * told that in words.
 *
 * A read model like App\Service\Forms\FormUsage and
 * App\Service\Media\MediaUsage: it writes nothing. It is also not
 * App\Service\PageService::references(), which answers the narrower question
 * whether a page may be deleted. Since header buttons became navigation items
 * (App\Service\NavigationPresentation) that question counts them too.
 */
final class PageUsage
{
    public const KIND_MENU = 'menu';
    public const KIND_FOOTER = 'footer';
    public const KIND_HEADER_BUTTON = 'header_button';

    /**
     * Every place that links to the page: menu first, then footer, then the
     * header buttons.
     *
     * `hidden` marks a menu item, header button or footer link that is
     * switched off (or sits in a hidden footer column): it is not on the
     * website today, but it still points at the page and would follow it.
     *
     * @return list<array{kind: string, label: string, context: string, hidden: bool, edit_url: string}>
     */
    public static function forPageId(int $pageId): array
    {
        $menu = [];
        $buttons = [];

        foreach ((new NavigationRepository())->findByTargetPageId($pageId) as $item) {
            $place = [
                'kind' => NavigationPresentation::isButton($item) ? self::KIND_HEADER_BUTTON : self::KIND_MENU,
                'label' => self::label($item),
                'context' => '',
                'hidden' => (int) $item['is_visible'] !== 1,
                'edit_url' => '/admin/navigation-item.php?id=' . (int) $item['id'],
            ];

            if (NavigationPresentation::isButton($item)) {
                $buttons[] = $place;
            } else {
                $menu[] = $place;
            }
        }

        $footer = [];
        foreach ((new FooterRepository())->findLinksByTargetPageId($pageId) as $link) {
            $footer[] = [
                'kind' => self::KIND_FOOTER,
                'label' => self::label($link),
                'context' => trim((string) ($link['column_title_nl'] ?? '')),
                'hidden' => (int) $link['is_visible'] !== 1 || (int) $link['column_is_visible'] !== 1,
                'edit_url' => '/admin/footer-link.php?id=' . (int) $link['id'],
            ];
        }

        return array_merge($menu, $footer, $buttons);
    }

    /**
     * A row's Dutch label, or its English one when only that was filled in.
     *
     * @param array<string, mixed> $row
     */
    private static function label(array $row): string
    {
        $label = trim((string) ($row['label_nl'] ?? ''));

        return $label !== '' ? $label : trim((string) ($row['label_en'] ?? ''));
    }
}

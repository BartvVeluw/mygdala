<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How a project's FIXED HEAD is laid out (Portfolio layout 2.0, MODULES.md
 * "Portfolio"): the main picture, categories, title, short text, intro and
 * description (partials/project-hero.php), with the picture on the left, on
 * the right or on top. That is all it decides. The project's content blocks
 * follow the head in their own order, and its extra photos are one of them
 * (the Projectafbeeldingen block, Portfolio 3.0), so no layout moves them.
 *
 * Portfolio 3.0 retired the fourth layout, "free" (the blocks as the whole
 * page, the head a Projectinformatie block among them): every project page
 * has its fixed head now. db/migrations/20261014100000 gave every free
 * project, and a free default, the fixed layout matching where its
 * Projectinformatie block put the picture; a stored "free" that would still
 * turn up reads as the default, like every word that is not a layout.
 *
 * TWO LEVELS. The Portfolio default is the site setting
 * `portfolio_project_layout` (Portfolio → Instellingen), `image_left` unless
 * chosen otherwise: what every project page looked like before, so nothing
 * changes for an existing site. Each project may choose its own
 * (`portfolio_gallery_items.project_layout`); NULL follows the default, so
 * changing the default changes every project that has no choice of its own,
 * and none that has.
 *
 * Every value is a word from LAYOUTS, checked on save and again on read: an
 * unknown stored word reads as the default, never as a layout nobody chose.
 */
final class PortfolioProjectLayout
{
    public const IMAGE_LEFT = 'image_left';
    public const IMAGE_RIGHT = 'image_right';
    public const IMAGE_TOP = 'image_top';

    /** Every layout a project can have, in the order the editor offers them. */
    public const LAYOUTS = [self::IMAGE_LEFT, self::IMAGE_RIGHT, self::IMAGE_TOP];

    /** What the Portfolio default may be: every layout. */
    public const DEFAULTS = self::LAYOUTS;

    public const SETTING = 'portfolio_project_layout';

    public static function isValid(string $layout): bool
    {
        return in_array($layout, self::LAYOUTS, true);
    }

    /** The Portfolio default, as stored and checked. */
    public static function siteDefault(): string
    {
        $stored = SiteSettings::get(self::SETTING);

        return in_array($stored, self::DEFAULTS, true) ? $stored : self::IMAGE_LEFT;
    }

    /**
     * The project's own choice as stored, or null when it follows the
     * default (or holds a word that is not a layout).
     */
    public static function ownChoice(mixed $stored): ?string
    {
        $stored = is_string($stored) ? $stored : '';

        return self::isValid($stored) ? $stored : null;
    }

    /**
     * The layout this project's page is built with: its own choice, else the
     * Portfolio default.
     *
     * @param array<string, mixed> $item a portfolio_gallery_items row, or itemForDetailPage()
     */
    public static function forItem(array $item): string
    {
        return self::ownChoice($item['project_layout'] ?? null) ?? self::siteDefault();
    }

    /** Where the picture sits in the project's head: left, right or top. */
    public static function imagePosition(string $layout): string
    {
        return match ($layout) {
            self::IMAGE_RIGHT => 'right',
            self::IMAGE_TOP => 'top',
            default => 'left',
        };
    }

    /** The editor's word for a layout (portfolio.layout.<layout>). */
    public static function label(string $layout): string
    {
        return \App\Service\Language\AdminTranslator::trans('portfolio.layout.' . (self::isValid($layout) ? $layout : self::IMAGE_LEFT));
    }
}

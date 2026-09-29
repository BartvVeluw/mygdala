<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How a project page is built (Portfolio layout 2.0, MODULES.md "Portfolio"):
 * the project's own head — main picture, categories, title, short text,
 * intro, description and its extra photos (partials/project-hero.php) — with
 * the picture on the left, on the right or on top and the project's content
 * blocks below it; or FREE, where the content blocks are the whole page and
 * the Projectinformatie block puts the project's own head wherever the
 * editor places it.
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
    public const FREE = 'free';

    /** Every layout a project can have, in the order the editor offers them. */
    public const LAYOUTS = [self::IMAGE_LEFT, self::IMAGE_RIGHT, self::IMAGE_TOP, self::FREE];

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

    /**
     * Where the picture sits in the project's head for a layout that has one:
     * left, right or top. FREE has no fixed head; the Projectinformatie block
     * decides for itself.
     */
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

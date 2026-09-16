<?php

declare(strict_types=1);

namespace App\Service;

/**
 * How a navigation item appears in the header: as a link in the menu, or as
 * a button in the action area on the right. Two closed lists and the rules
 * that tie them to the rest of a nav_items row.
 *
 * ONE ITEM MODEL, TWO PRESENTATIONS. A header button is a nav_items row
 * like any menu item — same labels, same link_type and companion field, same
 * App\Service\LinkResolver, same order and visibility — whose presentation
 * says "button". See
 * db/migrations/20260916230000_move_the_header_button_into_the_navigation.php
 * for why that beats a second table, and HEADER-FOOTER.md for the contract.
 *
 * Closed on purpose, like SocialProfiles::NETWORKS and ThemeFonts: an editor
 * picks a variant, and the CSS class that reaches the page comes from
 * buttonClass(), never from the database. Only existing Core classes are
 * used (`.btn`, `.btn--ghost`, `.btn--sm` in assets/css/core.css); this is
 * not where a new button design gets added.
 *
 * NOT IN HERE: rendering (partials/header.php), resolving a target
 * (LinkResolver) and SQL (App\Repository\NavigationRepository).
 */
final class NavigationPresentation
{
    public const LINK = 'link';
    public const BUTTON = 'button';

    /** @var list<string> */
    public const PRESENTATIONS = [self::LINK, self::BUTTON];

    public const VARIANT_PRIMARY = 'primary';
    public const VARIANT_GHOST = 'ghost';

    /**
     * Variant => the classes the header prints. The primary one is exactly
     * what the single header CTA always rendered.
     *
     * @var array<string, string>
     */
    private const BUTTON_CLASSES = [
        self::VARIANT_PRIMARY => 'btn btn--sm',
        self::VARIANT_GHOST => 'btn btn--sm btn--ghost',
    ];

    /**
     * The target kinds a button may use. A button always goes somewhere, so
     * 'none' (a dropdown heading) is not among them.
     *
     * @var list<string>
     */
    public const BUTTON_LINK_TYPES = ['page', 'route', 'external'];

    /** @return list<string> */
    public static function variants(): array
    {
        return array_keys(self::BUTTON_CLASSES);
    }

    /**
     * A stored presentation, read defensively: anything unknown, and a row
     * from before the column existed, is a menu link — which is what every
     * row was.
     *
     * @param array<string, mixed> $row
     */
    public static function of(array $row): string
    {
        return ($row['presentation'] ?? null) === self::BUTTON ? self::BUTTON : self::LINK;
    }

    public static function isButton(array $row): bool
    {
        return self::of($row) === self::BUTTON;
    }

    /** @param array<string, mixed> $row */
    public static function variantOf(array $row): string
    {
        $variant = (string) ($row['button_variant'] ?? '');

        return array_key_exists($variant, self::BUTTON_CLASSES) ? $variant : self::VARIANT_PRIMARY;
    }

    public static function buttonClass(string $variant): string
    {
        return self::BUTTON_CLASSES[$variant] ?? self::BUTTON_CLASSES[self::VARIANT_PRIMARY];
    }

    /**
     * Why a submitted presentation cannot be stored, as catalog keys (empty
     * when it can). Shared by api/admin/create-nav-item.php and
     * api/admin/update-nav-item.php, so both refuse the same things:
     *
     *   - an unknown presentation or variant;
     *   - a button inside a submenu: the action area has no dropdowns;
     *   - a button without a destination ('none');
     *   - a button that still has submenu items, which would silently vanish
     *     from the header.
     *
     * @return list<string>
     */
    public static function errors(string $presentation, string $variant, ?int $parentId, string $linkType, int $childCount): array
    {
        if (!in_array($presentation, self::PRESENTATIONS, true)) {
            return ['validation.navigation_presentation_invalid'];
        }

        if ($presentation !== self::BUTTON) {
            return [];
        }

        $errors = [];

        if (!array_key_exists($variant, self::BUTTON_CLASSES)) {
            $errors[] = 'validation.navigation_button_variant_invalid';
        }
        if ($parentId !== null) {
            $errors[] = 'validation.navigation_button_in_submenu';
        }
        if (!in_array($linkType, self::BUTTON_LINK_TYPES, true)) {
            $errors[] = 'validation.navigation_button_needs_destination';
        }
        if ($childCount > 0) {
            $errors[] = 'validation.navigation_button_has_children';
        }

        return $errors;
    }
}

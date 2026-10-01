<?php

declare(strict_types=1);

namespace App\Service;

/**
 * The text of one option in a native <select> that lists a tree: a page's
 * parent (App\Service\PageOptions) and a menu item's parent
 * (admin/navigation-item.php). Both lists read the same way, so a nested
 * choice looks alike wherever an editor makes one:
 *
 *     Diensten
 *        – Metaal graveren
 *           – Snijplanken
 *
 * A <select> cannot draw a tree, so the depth is in the text. The indent is
 * no-break spaces, which a screen reader does not read, and the mark is a
 * single en dash, which a screen reader at its usual punctuation level does
 * not read either — a deliberate choice over a row of dashes or box-drawing
 * characters, which it would read out on every option.
 *
 * Only the text. Which options a list offers, and in which order, stays that
 * list's own business.
 */
final class TreeOptions
{
    /** One level of indent: three no-break spaces. */
    private const INDENT = "\u{00A0}\u{00A0}\u{00A0}";

    /** Before an option that sits under another: an en dash and a no-break space. */
    private const MARK = "\u{2013}\u{00A0}";

    /** $depth is 0 for the top level, 1 for one level below it, and so on. */
    public static function label(string $name, int $depth): string
    {
        return $depth < 1 ? $name : str_repeat(self::INDENT, $depth) . self::MARK . $name;
    }
}

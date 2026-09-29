<?php

declare(strict_types=1);

namespace App\Service\Blocks;

/**
 * WHAT A NUMBER OR LABEL ABOVE A TITLE SHOWS, for the blocks that print one
 * (CONTENT-BLOCKS.md "Nummer of label"): a card of the Kaarten-carrousel
 * (carousel_cards.label_mode) and a Detailsectie (detail_sections.label_mode).
 *
 *   none     nothing, and no room kept for it
 *   padded   the place in the current order, at least two digits: 01, 02, 10
 *   plain    the same without the leading zero: 1, 2, 10
 *   icon     a Media Library icon instead of words (a carousel card only)
 *   custom   the block's own words, per language
 *
 * THE NUMBER IS NEVER STORED. It is the place the block or card has when the
 * page is drawn, so moving it renumbers everything at once; the block that
 * prints it says what counts as a place. A stored "01" in the custom words is
 * text, not a number, and stays what it is.
 *
 * A closed list: a stored or posted word outside it reads as the block's own
 * default (fromStored()), and an endpoint refuses it (isValid()).
 */
final class LabelMode
{
    public const NONE = 'none';

    public const PADDED = 'padded';

    public const PLAIN = 'plain';

    public const ICON = 'icon';

    public const CUSTOM = 'custom';

    /** A carousel card's choices, in the order the editor offers them. */
    public const CARD_MODES = [self::NONE, self::PADDED, self::PLAIN, self::ICON, self::CUSTOM];

    /** A Detailsectie's choices: no icon. */
    public const SECTION_MODES = [self::NONE, self::PADDED, self::PLAIN, self::CUSTOM];

    /** @param list<string> $modes */
    public static function isValid(mixed $mode, array $modes): bool
    {
        return is_string($mode) && in_array($mode, $modes, true);
    }

    /**
     * A stored mode, or $default for anything that is not one of $modes.
     *
     * @param list<string> $modes
     */
    public static function fromStored(mixed $stored, array $modes, string $default): string
    {
        return self::isValid($stored, $modes) ? (string) $stored : $default;
    }

    /**
     * The words the label shows: the place for a number (1-based), the
     * custom words for custom, '' for none and icon.
     */
    public static function text(string $mode, int $position, string $custom): string
    {
        return match ($mode) {
            self::PADDED => sprintf('%02d', max(1, $position)),
            self::PLAIN => (string) max(1, $position),
            self::CUSTOM => trim($custom),
            default => '',
        };
    }
}

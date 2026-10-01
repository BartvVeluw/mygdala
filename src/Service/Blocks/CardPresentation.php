<?php

declare(strict_types=1);

namespace App\Service\Blocks;

use App\Service\PageAssets;

/**
 * CARD PRESENTATION 2.0: how the cards INSIDE a block look, one shared,
 * closed contract for every block whose items are content cards (a picture,
 * a title, a short text, a link or a call to action). CONTENT-BLOCKS.md,
 * "Kaartweergave".
 *
 *   default  the block's own cards, exactly as they were before this
 *            existed. Nothing here touches them: no class, no element.
 *   compact  smaller pictures, the words under them and always visible, less
 *            room: more cards in a row where the block's grid allows it.
 *   wide     a horizontal card: the picture on the left, the words on the
 *            right, one or two cards in a row. On a phone the picture goes
 *            on top and the words under it.
 *
 * There is no fourth "visual" presentation on purpose: the gallery's default
 * card already IS the picture-first card (a 4/5 photo with its words on
 * hover), so a fourth choice would draw the same thing twice.
 *
 * WHAT A PRESENTATION DECIDES: the grid's columns for that presentation, the
 * card's layout, the picture's shape and fit, the room around the words, and
 * that the words are always shown. It never decides which items, their
 * words, their links, whether a picture zooms, a publication state or a
 * price: that stays with the block and its source. It never decides the
 * block's own background, lines or room either: that is Extra vormgeving
 * (App\Service\Blocks\BlockAppearance), a different layer on the block's
 * root element.
 *
 * CLOSED. A presentation is a key of ALL, stored per block instance in the
 * block's own row (item_galleries.card_presentation), checked on save
 * (choiceFromRequest()) and again on read (stored()). The CSS is
 * assets/css/card-presentation.css, the classes come from classes() and
 * nowhere else, and no stored or posted string ever becomes a class or a
 * style.
 *
 * HOW A BLOCK JOINS: implement PresentsCards on the definition, store the
 * value in the block's row, offer admin/_card_presentation_field.php in the
 * editor, read the value with stored() and let the partial print classes()
 * on the grid and on the parts of each card — only when the presentation is
 * not DEFAULT, so the default markup stays byte for byte what it was.
 */
final class CardPresentation
{
    public const DEFAULT = 'default';

    public const COMPACT = 'compact';

    public const WIDE = 'wide';

    /** Every presentation, the default first. */
    public const ALL = [self::DEFAULT, self::COMPACT, self::WIDE];

    /** The one stylesheet of every presentation but the default. */
    public const STYLESHEET = 'assets/css/card-presentation.css';

    /** The parts of a card a partial marks, and the class each one gets. */
    private const PARTS = [
        'grid' => 'card-presentation',
        'card' => 'card-presentation__card',
        'media' => 'card-presentation__media',
        'body' => 'card-presentation__body',
        'title' => 'card-presentation__title',
        'text' => 'card-presentation__text',
    ];

    public static function isPresentation(string $presentation): bool
    {
        return in_array($presentation, self::ALL, true);
    }

    /**
     * What a block's row says, read safely: NULL, a missing column or a word
     * this contract does not know is the default, which is the block as it
     * always was.
     */
    public static function stored(mixed $value): string
    {
        return is_string($value) && self::isPresentation($value) ? $value : self::DEFAULT;
    }

    /**
     * The presentations a block offers, or none when it does not present
     * cards: what the editor shows and what a save may store.
     *
     * @return list<string>
     */
    public static function offered(BlockDefinition $definition): array
    {
        if (!$definition instanceof PresentsCards) {
            return [];
        }

        return array_values(array_filter(
            $definition->cardPresentations(),
            static fn (string $presentation): bool => self::isPresentation($presentation)
        ));
    }

    /**
     * The choice a save stores: the posted `card_presentation` when this
     * block offers it, the stored one when the form did not send the field,
     * and null — refuse the save — for anything else.
     *
     * @param array<string, mixed> $post
     */
    public static function choiceFromRequest(array $post, BlockDefinition $definition, mixed $stored): ?string
    {
        if (!array_key_exists('card_presentation', $post)) {
            return self::stored($stored);
        }

        $posted = $post['card_presentation'];

        return is_string($posted) && in_array($posted, self::offered($definition), true) ? $posted : null;
    }

    /**
     * The class one part of a card gets: '' for the default (which prints
     * nothing at all), else a leading space and the shared class, plus the
     * presentation's modifier on the grid. The only place these class names
     * are made.
     *
     * @param 'grid'|'card'|'media'|'body'|'title'|'text' $part
     */
    public static function classes(string $presentation, string $part): string
    {
        if (!self::isPresentation($presentation) || $presentation === self::DEFAULT || !isset(self::PARTS[$part])) {
            return '';
        }

        $class = ' ' . self::PARTS[$part];

        return $part === 'grid' ? $class . ' ' . self::PARTS['grid'] . '--' . $presentation : $class;
    }

    /**
     * Asks App\Service\PageAssets for the stylesheet when a block on this
     * page shows a presentation other than the default, before the page
     * writes its <head> (SectionRegistry::collectPageAssets()). A page whose
     * blocks keep their own cards downloads nothing new.
     *
     * @param list<array<string, mixed>> $sections the page's visible page_sections rows
     */
    public static function collectAssets(array $sections): void
    {
        foreach ($sections as $pageSection) {
            $type = (string) ($pageSection['section_type'] ?? '');
            if (!BlockDefinitions::has($type)) {
                continue;
            }

            $definition = BlockDefinitions::get($type);
            if ($definition instanceof PresentsCards && $definition->cardPresentation($pageSection) !== self::DEFAULT) {
                PageAssets::requireStyle(self::STYLESHEET);

                return;
            }
        }
    }
}

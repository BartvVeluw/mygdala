<?php

declare(strict_types=1);

namespace App\Service\OrderFields;

/**
 * The kinds of question a product can ask before it goes in the cart (Shop
 * Product & Ordering 2.0, MODULES.md "Bestelvelden"). A closed list of five,
 * deliberately without uploads, dates or conditions:
 *
 *   text       Kort tekstveld: one line ("Naam op het bord")
 *   textarea   Lang tekstveld: several lines
 *   radio      Keuzerondjes: one of the listed choices
 *   select     Dropdown: one of the listed choices
 *   checkbox   Selectievakje: yes or no ("Cadeauverpakking")
 *
 * A text answer is limited by the question's own maximum, and never beyond
 * the type's CAP; without its own maximum it gets the type's DEFAULT.
 */
final class OrderFieldType
{
    public const TEXT = 'text';
    public const TEXTAREA = 'textarea';
    public const RADIO = 'radio';
    public const SELECT = 'select';
    public const CHECKBOX = 'checkbox';

    public const ALL = [self::TEXT, self::TEXTAREA, self::RADIO, self::SELECT, self::CHECKBOX];

    /** @var array<string, int> the length a text answer gets without a maximum of its own */
    public const DEFAULT_MAX_LENGTH = [self::TEXT => 100, self::TEXTAREA => 1000];

    /** @var array<string, int> the longest maximum an editor may give */
    public const CAP = [self::TEXT => 255, self::TEXTAREA => 2000];

    public static function isValid(mixed $type): bool
    {
        return is_string($type) && in_array($type, self::ALL, true);
    }

    /** Whether the question lists choices (radio, select). */
    public static function hasOptions(string $type): bool
    {
        return $type === self::RADIO || $type === self::SELECT;
    }

    /** Whether the answer is typed text with a length (text, textarea). */
    public static function isText(string $type): bool
    {
        return $type === self::TEXT || $type === self::TEXTAREA;
    }

    /** The maximum length a text answer may have: the question's own, within the cap. */
    public static function maxLength(string $type, ?int $own): int
    {
        if (!self::isText($type)) {
            return 0;
        }

        return $own !== null && $own > 0 ? min($own, self::CAP[$type]) : self::DEFAULT_MAX_LENGTH[$type];
    }
}

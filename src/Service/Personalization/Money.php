<?php

declare(strict_types=1);

namespace App\Service\Personalization;

/**
 * Exact money arithmetic in integer cents, for everything personalization
 * pricing touches.
 *
 * Personalization surcharges are added to a product price and then to an
 * order total, so they are the one place in this project where repeated
 * addition happens on amounts that come from several sources. Doing that in
 * floats is how a €7.50 surcharge on three lines quietly becomes €22.499999
 * and then a total that is one cent off the invoice. Everything here is an
 * `int` number of cents; a value only becomes a decimal string again at the
 * boundary where it is stored or displayed.
 *
 * Parsing never goes through a float either: `toCents()` reads the digits of
 * the decimal string itself, so "0.29" cannot arrive as 28 cents the way
 * `(int) (0.29 * 100)` does on a binary float. A float input is accepted for
 * the callers that still hold one (a shipping quote, a variant price), and is
 * rounded exactly once, deliberately, at that boundary.
 */
final class Money
{
    /** A guard rail, not a business rule: nothing in this shop costs a million euro. */
    public const MAX_CENTS = 100_000_000;

    /**
     * Whole cents from a decimal string ("7.50", "7,50"), an int, or a float.
     * Anything unreadable is 0 — a surcharge that cannot be parsed must never
     * become a random amount a customer is charged.
     */
    public static function toCents(mixed $amount): int
    {
        if (is_int($amount)) {
            return self::clamp($amount * 100);
        }

        if (is_float($amount)) {
            // The one deliberate float boundary: round once, to the cent.
            $amount = number_format($amount, 2, '.', '');
        }

        if (!is_string($amount)) {
            return 0;
        }

        $amount = str_replace([' ', "\u{00A0}"], '', trim($amount));
        $amount = str_replace(',', '.', $amount);

        if (!preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $amount, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return 0;
        }

        $whole = $m[2] === '' ? '0' : $m[2];
        // Two decimals exactly: pad a short fraction, and round a longer one
        // on its third digit rather than truncating it.
        $fraction = $m[3] ?? '';
        $cents = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        if (strlen($fraction) > 2 && (int) $fraction[2] >= 5) {
            $cents++;
        }

        return self::clamp($m[1] === '-' ? -$cents : $cents);
    }

    /** The canonical storage/transport form: "7.50". */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** The Dutch display form, without a currency symbol: "7,50". */
    public static function formatDutch(int $cents): string
    {
        return str_replace('.', ',', self::format($cents));
    }

    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }

    private static function clamp(int $cents): int
    {
        return max(-self::MAX_CENTS, min(self::MAX_CENTS, $cents));
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What the owner may type as the prefix of an order number and of an invoice
 * number (Shop-instellingen). One class for both, so there is one place that
 * says it, and the same pattern string checks the form in the browser and
 * the request on the server.
 *
 * TWO RULES, because the two numbers are built differently:
 *
 *   ORDER    letters and digits, at most 10. OrderRepository::formatOrderNumber()
 *            puts the separators in itself (ORD-2026-000042), so a prefix
 *            needs none.
 *   INVOICE  a letter or digit, then letters, digits, "-" and "_", at most
 *            20. InvoiceService::formatInvoiceNumber() glues the prefix to the
 *            year (INV2026-000001), so a trailing "-" or "_" is how an owner
 *            gets INV-2026-000001.
 *
 * NEVER "/", "\", ":", a dot, quotes, whitespace or a control character. An
 * invoice number becomes the name of its PDF in private storage and of the
 * mail attachment: "/" or "C:" made a sub-folder of that storage, and a dot
 * adds nothing a number needs.
 *
 * Refused rather than cleaned on the screen, so what an owner sees after
 * saving is what they typed. A value stored before this rule existed is
 * left alone (no data is rewritten): the screen warns about it, and a NEW
 * invoice uses invoicePrefixForNewInvoice(), its allowed characters only.
 * Invoices that already exist keep their number and their file.
 */
final class DocumentNumberPrefix
{
    /** The order-number prefix, as a pattern for an HTML `pattern` attribute and for the server. */
    public const ORDER = '[A-Za-z0-9]{1,10}';

    /** The invoice-number prefix; the hyphen escaped so a browser's `v`-mode pattern accepts it too. */
    public const INVOICE = '[A-Za-z0-9][A-Za-z0-9_\-]{0,19}';

    /** What a new invoice falls back to when nothing usable is left of the stored prefix. */
    public const INVOICE_FALLBACK = 'INV';

    public static function isValidOrderPrefix(string $prefix): bool
    {
        return self::matches(self::ORDER, $prefix);
    }

    public static function isValidInvoicePrefix(string $prefix): bool
    {
        return self::matches(self::INVOICE, $prefix);
    }

    /**
     * The prefix a NEW invoice is numbered with: the stored one when it is
     * valid; otherwise the characters of it the rule allows (without a
     * leading "-" or "_", at most 20), or INV when none are left — the way
     * OrderRepository keeps only the letters and digits of an order prefix.
     */
    public static function invoicePrefixForNewInvoice(string $stored): string
    {
        if (self::isValidInvoicePrefix($stored)) {
            return $stored;
        }

        $kept = substr(ltrim((string) preg_replace('/[^A-Za-z0-9_-]/', '', $stored), '_-'), 0, 20);

        return $kept !== '' ? $kept : self::INVOICE_FALLBACK;
    }

    private static function matches(string $pattern, string $prefix): bool
    {
        return preg_match('/^' . $pattern . '$/D', $prefix) === 1;
    }
}
